<?php

namespace LabelvierMediaUsage\Indexer;

use LabelvierMediaUsage\ScanState;
use LabelvierMediaUsage\Schema;

/**
 * Phase 2: links files to attachments and sets the file role
 * (main, original, subsize, webp). Cursor = last attachment ID.
 */
class AttachmentMapper implements PhaseInterface {

	public const OPTION     = 'lvmu_map_cursor';
	/** Option: duplicate attachment id => attachment id that owns the shared file. */
	public const DUP_OPTION = 'lvmu_dup_attachments';
	private const RANK      = ['webp' => 0, 'subsize' => 1, 'original' => 2, 'main' => 3];
	private const BATCH = 200;

	public function start(int $scan_id): void {
		global $wpdb;
		update_option(self::OPTION, 0, false);
		update_option(self::DUP_OPTION, [], false);
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
			// Real roles (main/original/subsize) always win over guessed webp siblings,
			// so a real WebP attachment keeps its own file.
			$real       = [];
			$guessed    = [];
			$main_paths = [];
			$dups       = [];
			foreach ($ids as $id) {
				foreach ($this->files_for($id) as $path => $role) {
					if ($role === 'webp') {
						$guessed[$path] ??= [$id, $role];
						continue;
					}
					if ($role === 'main') {
						$main_paths[$id] = $path;
					}
					if (!isset($real[$path])) {
						$real[$path] = [$id, $role];
					} elseif ($real[$path][0] !== $id) {
						if ($role === 'main' && $real[$path][1] === 'main') {
							$dups[$id] = $real[$path][0];
						} elseif (self::RANK[$role] > self::RANK[$real[$path][1]]) {
							$real[$path] = [$id, $role];
						}
					}
				}
			}
			$guessed = array_diff_key($guessed, $real);
			$dups   += $this->find_existing_owners($main_paths);
			$this->remember_duplicates($dups);
			$this->apply($real, ['orphan', 'webp']);
			$this->apply($guessed, ['orphan']);

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

		// Files kept by the image editor so "Restore original image" keeps working
		// (keys like full-orig, thumbnail-orig).
		$backup = get_post_meta($id, '_wp_attachment_backup_sizes', true);
		if (is_array($backup)) {
			foreach ($backup as $size) {
				if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
					$files[$dir . $size['file']] ??= 'original';
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
	 * Attachments of earlier batches that already own the main file of an attachment in this batch.
	 *
	 * @param array<int, string> $main_paths attachment id => main path
	 * @return array<int, int> duplicate attachment id => owner attachment id
	 */
	private function find_existing_owners(array $main_paths): array {
		global $wpdb;
		if (!$main_paths) {
			return [];
		}
		$by_hash = [];
		foreach ($main_paths as $id => $path) {
			$by_hash[md5($path)] = $id;
		}
		$in   = implode(',', array_map(static fn($h) => "'" . $h . "'", array_keys($by_hash)));
		$rows = $wpdb->get_results('SELECT path_hash, attachment_id FROM ' . Schema::files_table() . " WHERE path_hash IN ({$in}) AND attachment_id IS NOT NULL");
		$dups = [];
		foreach ($rows ?: [] as $row) {
			$id = $by_hash[$row->path_hash] ?? 0;
			if ($id && (int) $row->attachment_id !== $id) {
				$dups[$id] = (int) $row->attachment_id;
			}
		}
		return $dups;
	}

	/**
	 * @param array<int, int> $dups
	 */
	private function remember_duplicates(array $dups): void {
		if (!$dups) {
			return;
		}
		$all = get_option(self::DUP_OPTION, []);
		$all = is_array($all) ? $all : [];
		update_option(self::DUP_OPTION, $dups + $all, false);
	}

	/**
	 * @param array<string, array{0: int, 1: string}> $assignments path => [attachment id, role]
	 * @param string[] $only_roles only rows currently having one of these roles are updated
	 */
	private function apply(array $assignments, array $only_roles): void {
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
				. ' AND file_role IN (' . implode(',', array_map(static fn($r) => "'" . esc_sql($r) . "'", $only_roles)) . ')'
			);
		}
	}
}
