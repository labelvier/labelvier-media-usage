<?php

namespace LabelvierMediaUsage\Indexer;

use LabelvierMediaUsage\ScanState;
use LabelvierMediaUsage\Schema;

/**
 * Phase 2: links files to attachments and sets the file role
 * (main, original, subsize, webp). Cursor = last attachment ID.
 */
class AttachmentMapper implements PhaseInterface {

	public const OPTION = 'lvmu_map_cursor';
	private const BATCH = 200;

	public function start(int $scan_id): void {
		global $wpdb;
		update_option(self::OPTION, 0, false);
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
		ScanState::progress(['attachments_mapped' => 0, 'attachments_total' => $total]);
	}

	public function run(int $scan_id, float $deadline): bool {
		global $wpdb;
		$cursor = (int) get_option(self::OPTION, 0);
		$mapped = (int) (ScanState::get()['progress']['attachments_mapped'] ?? 0);

		do {
			$ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$cursor,
				self::BATCH
			)));
			if (!$ids) {
				return true;
			}

			update_meta_cache('post', $ids);
			$assignments = [];
			foreach ($ids as $id) {
				foreach ($this->files_for($id) as $path => $role) {
					$assignments[$path] ??= [$id, $role];
				}
			}
			$this->apply($assignments);

			$cursor  = end($ids);
			$mapped += count($ids);
			update_option(self::OPTION, $cursor, false);
			ScanState::progress(['attachments_mapped' => $mapped]);
			wp_cache_flush_runtime();
		} while (count($ids) === self::BATCH && microtime(true) < $deadline);

		return count($ids) < self::BATCH;
	}

	/**
	 * @return array<string, string> relative path => role
	 */
	private function files_for(int $id): array {
		$main = get_post_meta($id, '_wp_attached_file', true);
		if (!is_string($main) || $main === '') {
			return [];
		}
		$main = ltrim(wp_normalize_path($main), '/');
		$dir  = dirname($main);
		$dir  = $dir === '.' ? '' : $dir . '/';

		$files    = [$main => 'main'];
		$metadata = get_post_meta($id, '_wp_attachment_metadata', true);
		if (is_array($metadata)) {
			if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
				$files[$dir . $metadata['original_image']] ??= 'original';
			}
			foreach ((array) ($metadata['sizes'] ?? []) as $size) {
				if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
					$files[$dir . $size['file']] ??= 'subsize';
				}
			}
		}

		foreach ($files as $path => $role) {
			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			if ($ext === 'webp') {
				continue;
			}
			$files[$path . '.webp'] ??= 'webp';
			$files[preg_replace('/\.[^.\/]+$/', '', $path) . '.webp'] ??= 'webp';
		}

		return $files;
	}

	/**
	 * @param array<string, array{0: int, 1: string}> $assignments path => [attachment id, role]
	 */
	private function apply(array $assignments): void {
		global $wpdb;
		if (!$assignments) {
			return;
		}
		$table = Schema::files_table();

		foreach (array_chunk($assignments, 500, true) as $chunk) {
			$att_cases  = [];
			$role_cases = [];
			$hashes     = [];
			foreach ($chunk as $path => [$id, $role]) {
				$hash         = md5($path);
				$hashes[]     = $wpdb->prepare('%s', $hash);
				$att_cases[]  = $wpdb->prepare('WHEN %s THEN %d', $hash, $id);
				$role_cases[] = $wpdb->prepare('WHEN %s THEN %s', $hash, $role);
			}
			$wpdb->query(
				"UPDATE {$table} SET attachment_id = CASE path_hash " . implode(' ', $att_cases) . ' END,'
				. ' file_role = CASE path_hash ' . implode(' ', $role_cases) . ' END'
				. ' WHERE path_hash IN (' . implode(',', $hashes) . ')'
			);
		}
	}
}
