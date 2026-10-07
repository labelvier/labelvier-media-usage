<?php

namespace LabelvierMediaUsage;

/**
 * Manage the media usage index.
 */
class Cli {

	/**
	 * Start an index run.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Run all phases inline instead of through Action Scheduler.
	 *
	 * [--force]
	 * : Start even when a run is already in progress.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lvmu index --sync
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function index($args, $assoc_args): void {
		if (!Scheduler::is_supported()) {
			\WP_CLI::error(Scheduler::unsupported_message());
		}
		$force = !empty($assoc_args['force']);

		if (!empty($assoc_args['sync'])) {
			$ok = Scheduler::run_sync(static fn(string $message) => \WP_CLI::log($message), $force);
			if (!$ok) {
				\WP_CLI::error(ScanState::get()['error'] ?: 'Index run did not complete. Use --force to restart.');
			}
			\WP_CLI::success('Index finished.');
			return;
		}

		if (!Scheduler::start_scan($force)) {
			\WP_CLI::error('A scan is already running. Use --force to restart.');
		}
		\WP_CLI::success('Scan queued. Action Scheduler will run it (or run it now: wp action-scheduler run --hooks=lvmu_start_scan,lvmu_run_phase).');
	}

	/**
	 * Show the state of the current or last index run.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: yaml
	 * options:
	 *   - yaml
	 *   - json
	 * ---
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function status($args, $assoc_args): void {
		$state = ScanState::get();
		foreach (['started_at', 'finished_at'] as $key) {
			if ($state[$key]) {
				$state[$key] = wp_date('Y-m-d H:i:s', (int) $state[$key]);
			}
		}
		\WP_CLI::print_value($state, ['format' => $assoc_args['format'] ?? 'yaml']);
	}

	/**
	 * Show totals by role, type and usage.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function summary($args, $assoc_args): void {
		$summary = (new FileRepository())->summary();
		$format  = $assoc_args['format'] ?? 'table';

		if ($format === 'json') {
			\WP_CLI::line(wp_json_encode($summary, JSON_PRETTY_PRINT));
			return;
		}

		$rows = [['group' => 'total', 'key' => 'all', 'count' => $summary['total']['count'], 'size' => size_format($summary['total']['size'], 2)]];
		foreach (['by_role', 'by_type', 'by_usage'] as $group) {
			foreach ($summary[$group] as $key => $data) {
				$rows[] = ['group' => substr($group, 3), 'key' => $key, 'count' => $data['count'], 'size' => size_format($data['size'], 2)];
			}
		}
		$rows[] = ['group' => 'reclaimable', 'key' => 'orphaned+unused', 'count' => $summary['orphaned_unused']['count'], 'size' => size_format($summary['orphaned_unused']['size'], 2)];
		\WP_CLI\Utils\format_items('table', $rows, ['group', 'key', 'count', 'size']);
	}

	/**
	 * List indexed files, largest first.
	 *
	 * ## OPTIONS
	 *
	 * [--file_type=<type>]
	 * : Filter by file type (image, video, audio, document, archive, database, log, code, other).
	 *
	 * [--file_role=<role>]
	 * : Filter by role (main, original, subsize, webp, orphan).
	 *
	 * [--usage_status=<status>]
	 * : Filter by usage (used, unused, unknown).
	 *
	 * [--orphaned]
	 * : Only files without an attachment.
	 *
	 * [--limit=<limit>]
	 * : Number of files to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lvmu list --usage_status=unused --orphaned --limit=50
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function list($args, $assoc_args): void {
		$result = (new FileRepository())->query([
			'file_type'    => $assoc_args['file_type'] ?? '',
			'file_role'    => $assoc_args['file_role'] ?? '',
			'usage_status' => $assoc_args['usage_status'] ?? '',
			'orphaned'     => !empty($assoc_args['orphaned']),
			'per_page'     => (int) ($assoc_args['limit'] ?? 20),
		]);

		$format = $assoc_args['format'] ?? 'table';
		$items  = array_map(static fn($row) => [
			'id'            => (int) $row->id,
			'path'          => $row->path,
			'file_type'     => $row->file_type,
			'file_role'     => $row->file_role,
			'size'          => $format === 'table' ? size_format((int) $row->size, 2) : (int) $row->size,
			'attachment_id' => $row->attachment_id ?? '',
			'usage_status'  => $row->usage_status,
			'usage_count'   => (int) $row->usage_count,
		], $result['items']);

		\WP_CLI\Utils\format_items($format, $items, ['id', 'path', 'file_type', 'file_role', 'size', 'attachment_id', 'usage_status', 'usage_count']);
		if ($format === 'table') {
			\WP_CLI::log(sprintf('%d matching files, %s in total.', $result['total'], size_format($result['total_size'], 2)));
		}
	}
}
