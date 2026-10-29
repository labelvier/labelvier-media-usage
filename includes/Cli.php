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
	 * Show totals per category (as in the admin overview) and by type. Values are count / size.
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
		$repo       = new FileRepository();
		$summary    = $repo->summary();
		$categories = $repo->category_summary();
		$potential  = $repo->cleanup_potential();
		$format     = $assoc_args['format'] ?? 'table';

		if ($format === 'json') {
			$summary['categories']  = $categories;
			$summary['reclaimable'] = $potential;
			\WP_CLI::line(wp_json_encode($summary, JSON_PRETTY_PRINT));
			return;
		}

		$fmt  = static fn(array $d): string => $d['count'] . ' / ' . size_format($d['size'], 2);
		$rows = [['group' => 'total', 'key' => 'all', 'total' => $fmt($summary['total']), 'used' => '', 'unused' => '']];
		foreach ($categories as $category => $data) {
			$rows[] = [
				'group'  => 'category',
				'key'    => $category,
				'total'  => $fmt($data['total']),
				'used'   => $fmt($data['used']),
				'unused' => $fmt($data['unused']),
			];
		}
		$rows[] = ['group' => 'reclaimable', 'key' => 'unused, excl. protected', 'total' => '', 'used' => '', 'unused' => $fmt($potential)];
		foreach (['by_type'] as $group) {
			foreach ($summary[$group] as $key => $data) {
				$rows[] = ['group' => substr($group, 3), 'key' => $key, 'total' => $fmt($data), 'used' => '', 'unused' => ''];
			}
		}
		\WP_CLI\Utils\format_items('table', $rows, ['group', 'key', 'total', 'used', 'unused']);
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

	/**
	 * Show how much disk space the site takes: uploads, database and plugins plus a fixed
	 * 300 MB allowance for WordPress core and themes. Sizes are in bytes in json format.
	 * Always measures live and stores the result for the Media Usage page.
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
	 * ## EXAMPLES
	 *
	 *     wp lvmu size --format=json
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function size($args, $assoc_args): void {
		$size = SiteSize::measure_and_store();

		if (($assoc_args['format'] ?? 'table') === 'json') {
			\WP_CLI::line(wp_json_encode($size));
			return;
		}

		$rows = [];
		foreach (['uploads', 'database', 'plugins', 'base', 'total'] as $key) {
			$rows[] = ['part' => $key, 'size' => size_format($size[$key], 2)];
		}
		\WP_CLI\Utils\format_items('table', $rows, ['part', 'size']);
	}
}
