<?php

namespace LabelvierMediaUsage;

/**
 * Deletes indexed files from disk (and from the Media Library where needed).
 */
class Deleter {

	/** Maximum number of files handled in a single request. */
	public const MAX_PER_REQUEST = 500;

	private FileRepository $repo;

	/** scan_id of the completed index run the deletion is based on. */
	private int $scan_id = 0;

	public function __construct(?FileRepository $repo = null) {
		$this->repo = $repo ?? new FileRepository();
	}

	/**
	 * Deleting is only allowed on the result of a complete, finished index run.
	 * Returns an empty string when allowed, otherwise the reason it is not.
	 */
	public static function disabled_reason(): string {
		$state = ScanState::get();
		if ($state['status'] === 'running') {
			return __('Deleting is disabled while indexing.', 'labelvier-media-usage');
		}
		if ($state['status'] !== 'done' || !(int) $state['scan_id']) {
			return __('Deleting is disabled until a complete index has run.', 'labelvier-media-usage');
		}
		return '';
	}

	/**
	 * @param int[] $file_ids   Row ids from lvmu_files.
	 * @param bool  $allow_used Also delete files whose usage_status is 'used'.
	 * @return array{deleted: int, freed: int, skipped: array<int, array{path: string, reason: string}>, errors: array<int, array{path: string, reason: string}>, remaining: int}
	 */
	public function delete(array $file_ids, bool $allow_used = false, ?callable $progress = null): array {
		$result = ['deleted' => 0, 'freed' => 0, 'skipped' => [], 'errors' => [], 'remaining' => 0];

		$blocked = self::disabled_reason();
		if ($blocked !== '') {
			$result['errors'][] = ['path' => '', 'reason' => $blocked];
			return $result;
		}
		$this->scan_id = (int) ScanState::get()['scan_id'];

		$file_ids = array_values(array_unique(array_filter(array_map('intval', $file_ids))));
		if (count($file_ids) > self::MAX_PER_REQUEST) {
			$result['remaining'] = count($file_ids) - self::MAX_PER_REQUEST;
			$file_ids            = array_slice($file_ids, 0, self::MAX_PER_REQUEST);
		}
		if (!$file_ids) {
			return $result;
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$base = realpath(FileRepository::uploads_basedir());
		if ($base === false) {
			$result['errors'][] = ['path' => '', 'reason' => __('Uploads folder not found.', 'labelvier-media-usage')];
			return $result;
		}
		$base = wp_normalize_path($base);

		$gone = []; // ids already handled (e.g. variants removed together with their attachment)
		$done = 0;

		foreach (array_chunk($file_ids, 200) as $chunk) {
			$rows = $this->repo->find($chunk);
			// Process 'main' first so variants are removed together with their attachment.
			usort($rows, static fn($a, $b) => (int) ($b->file_role === 'main') <=> (int) ($a->file_role === 'main'));

			foreach ($rows as $row) {
				$done++;
				if (isset($gone[(int) $row->id])) {
					continue;
				}
				$this->delete_one($row, $base, $allow_used, $result, $gone);
				if ($progress) {
					$progress($result, $done);
				}
			}
		}

		do_action('lvmu_deleted_files', $result);

		return $result;
	}

	private function skip(array &$result, object $row, string $reason): void {
		$result['skipped'][] = ['path' => $row->path, 'reason' => $reason];
	}

	private function error(array &$result, object $row, string $reason): void {
		$result['errors'][] = ['path' => $row->path, 'reason' => $reason];
	}

	private function delete_one(object $row, string $base, bool $allow_used, array &$result, array &$gone): void {
		if (FileRules::protected((string) $row->path) !== null) {
			$this->skip($result, $row, __('Protected file.', 'labelvier-media-usage'));
			return;
		}
		if ((int) $row->scan_id !== $this->scan_id) {
			$this->skip($result, $row, __('Not part of the latest complete index.', 'labelvier-media-usage'));
			return;
		}
		if ($row->usage_status === 'used') {
			if (!$allow_used) {
				$this->skip($result, $row, __('File is in use.', 'labelvier-media-usage'));
				return;
			}
		} elseif ($row->usage_status !== 'unused') {
			$this->skip($result, $row, __('Usage unknown.', 'labelvier-media-usage'));
			return;
		}
		if (!$allow_used && $this->used_since_index($row)) {
			global $wpdb;
			$wpdb->update(Schema::files_table(), ['usage_status' => 'used'], ['id' => (int) $row->id]);
			$this->skip($result, $row, __('Used since last index.', 'labelvier-media-usage'));
			return;
		}

		$absolute = FileRepository::absolute_path($row->path);
		$real     = file_exists($absolute) ? realpath($absolute) : false;

		if ($real === false && $row->file_role !== 'main') {
			// Already gone from disk: just drop the stale index row.
			$this->repo->delete([(int) $row->id]);
			$gone[(int) $row->id] = true;
			$this->skip($result, $row, __('File no longer exists on disk, index entry removed.', 'labelvier-media-usage'));
			return;
		}
		if ($real !== false) {
			$real = wp_normalize_path($real);
			if (strpos($real, $base . '/') !== 0 || !is_file($real)) {
				$this->skip($result, $row, __('Outside the uploads folder or not a regular file.', 'labelvier-media-usage'));
				return;
			}
		}

		switch ($row->file_role) {
			case 'main':
				$this->delete_main($row, $result, $gone);
				return;
			case 'original':
				$this->unlink_file($row, $real, $result, $gone, function () use ($row) {
					$meta = wp_get_attachment_metadata((int) $row->attachment_id);
					if (is_array($meta) && isset($meta['original_image'])) {
						unset($meta['original_image']);
						wp_update_attachment_metadata((int) $row->attachment_id, $meta);
					}
				});
				return;
			case 'subsize':
				$this->unlink_file($row, $real, $result, $gone, function () use ($row) {
					$meta = wp_get_attachment_metadata((int) $row->attachment_id);
					if (!is_array($meta) || empty($meta['sizes'])) {
						return;
					}
					$file    = wp_basename($row->path);
					$changed = false;
					foreach ($meta['sizes'] as $size => $data) {
						if (isset($data['file']) && $data['file'] === $file) {
							unset($meta['sizes'][$size]);
							$changed = true;
						}
					}
					if ($changed) {
						wp_update_attachment_metadata((int) $row->attachment_id, $meta);
					}
				});
				return;
			default: // orphan, webp
				$this->unlink_file($row, $real, $result, $gone);
		}
	}

	private function unlink_file(object $row, string|false $real, array &$result, array &$gone, ?callable $after = null): void {
		if ($real !== false && $this->is_attached_file($row->path)) {
			$this->skip($result, $row, __('File is the main file of an attachment.', 'labelvier-media-usage'));
			return;
		}
		if ($real === false || !@unlink($real)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			$this->error($result, $row, __('Could not delete the file (permissions?).', 'labelvier-media-usage'));
			return;
		}
		if ($after && $row->attachment_id) {
			$after();
		}
		$this->repo->delete([(int) $row->id]);
		$gone[(int) $row->id] = true;
		$result['deleted']++;
		$result['freed'] += (int) $row->size;
	}

	private function delete_main(object $row, array &$result, array &$gone): void {
		$attachment_id = (int) $row->attachment_id;
		if (!$attachment_id || !get_post($attachment_id)) {
			// No attachment to remove: treat as plain file.
			$real = file_exists(FileRepository::absolute_path($row->path)) ? realpath(FileRepository::absolute_path($row->path)) : false;
			$this->unlink_file($row, $real, $result, $gone);
			return;
		}

		$shared = $this->shared_with_other_attachment($attachment_id);
		if ($shared !== '') {
			$this->skip($result, $row, sprintf(
				/* translators: %s: shared file path */
				__('File is shared with another attachment (%s).', 'labelvier-media-usage'),
				$shared
			));
			return;
		}

		$variants = $this->repo->find_by_attachment($attachment_id);
		if (!wp_delete_attachment($attachment_id, true)) {
			$this->error($result, $row, __('WordPress could not delete the attachment.', 'labelvier-media-usage'));
			return;
		}

		// WordPress does not know about webp copies made by optimizer plugins, remove them too.
		$basedir = realpath(FileRepository::uploads_basedir());
		foreach ($variants as $variant) {
			$path = FileRepository::absolute_path($variant->path);
			$real = file_exists($path) ? realpath($path) : false;
			if ($variant->file_role === 'webp' && $real && !$this->is_attached_file($variant->path) && $basedir && str_starts_with($real, $basedir . DIRECTORY_SEPARATOR)) {
				@unlink($real);
			}
		}

		$remove = [];
		foreach ($variants as $variant) {
			$gone[(int) $variant->id] = true;
			if (!file_exists(FileRepository::absolute_path($variant->path))) {
				$remove[]         = (int) $variant->id;
				$result['freed'] += (int) $variant->size;
				$result['deleted']++;
			}
		}
		// Rows of files that still exist (e.g. webp copies WP does not know about) stay in the index as orphans.
		$this->repo->delete($remove);
		foreach ($variants as $variant) {
			if (!in_array((int) $variant->id, $remove, true)) {
				global $wpdb;
				$wpdb->update(Schema::files_table(), ['attachment_id' => null, 'file_role' => 'orphan'], ['id' => (int) $variant->id]);
				unset($gone[(int) $variant->id]);
			}
		}
	}

	/* ------------------------------------------------------------ safety checks */

	/** True when a relative path is some attachment's `_wp_attached_file`. */
	private function is_attached_file(string $path): bool {
		global $wpdb;
		return (bool) $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
			ltrim($path, '/')
		));
	}

	/**
	 * Relative paths of all files an attachment's metadata lists (main, sizes, original).
	 *
	 * @return string[]
	 */
	private function attachment_files(int $attachment_id): array {
		$main = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		if ($main === '') {
			return [];
		}
		$files = [$main];
		$dir   = dirname($main);
		$dir   = $dir === '.' ? '' : $dir . '/';
		$meta  = wp_get_attachment_metadata($attachment_id);
		if (is_array($meta)) {
			if (!empty($meta['file']) && is_string($meta['file'])) {
				$files[] = $meta['file'];
			}
			foreach ((array) ($meta['sizes'] ?? []) as $size) {
				if (!empty($size['file']) && is_string($size['file'])) {
					$files[] = $dir . $size['file'];
				}
			}
			if (!empty($meta['original_image']) && is_string($meta['original_image'])) {
				$files[] = $dir . $meta['original_image'];
			}
		}
		return array_values(array_unique($files));
	}

	/**
	 * Returns a shared file path when another attachment uses the main file or any size
	 * file of this attachment, otherwise ''.
	 */
	private function shared_with_other_attachment(int $attachment_id): string {
		global $wpdb;
		$mine = $this->attachment_files($attachment_id);
		if (!$mine) {
			return '';
		}

		// Another attachment whose main file is one of ours.
		$in    = implode(',', array_fill(0, count($mine), '%s'));
		$other = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id <> %d AND meta_value IN ({$in}) LIMIT 1",
			array_merge([$attachment_id], $mine)
		));
		if ($other) {
			return (string) $other;
		}

		// Another attachment whose metadata lists one of our files.
		$candidates = [];
		foreach (array_unique(array_map('wp_basename', $mine)) as $basename) {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND post_id <> %d AND meta_value LIKE %s LIMIT 20",
				$attachment_id,
				'%' . $wpdb->esc_like($basename) . '%'
			));
			foreach ($ids as $id) {
				$candidates[(int) $id] = true;
			}
		}
		foreach (array_keys($candidates) as $id) {
			$common = array_intersect($mine, $this->attachment_files($id));
			if ($common) {
				return (string) reset($common);
			}
		}
		return '';
	}

	/**
	 * Cheap live check whether a file is referenced in content, meta or options even
	 * though the (possibly up to 24h old) index says it is unused.
	 */
	private function used_since_index(object $row): bool {
		global $wpdb;

		$needles = [wp_basename($row->path)];
		if ($row->file_role === 'main' && $row->attachment_id) {
			foreach (array_slice($this->repo->find_by_attachment((int) $row->attachment_id), 0, 50) as $variant) {
				$needles[] = wp_basename($variant->path);
			}
		}
		$like_content = "post_type NOT IN ('attachment', 'revision') AND post_status NOT IN ('trash', 'auto-draft')";

		foreach (array_unique($needles) as $needle) {
			$like = '%' . $wpdb->esc_like($needle) . '%';
			if ($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE {$like_content} AND post_content LIKE %s LIMIT 1", $like))) {
				return true;
			}
			if ($wpdb->get_var($wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND meta_value LIKE %s LIMIT 1",
				$like
			))) {
				return true;
			}
			if ($wpdb->get_var($wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value LIKE %s LIMIT 1",
				$wpdb->esc_like('_transient') . '%',
				$wpdb->esc_like('lvmu_') . '%',
				$like
			))) {
				return true;
			}
		}

		if ($row->file_role === 'main' && $row->attachment_id) {
			$id = (int) $row->attachment_id;
			if ($wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", (string) $id))) {
				return true;
			}
			if ($wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE {$like_content} AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT 1",
				'%' . $wpdb->esc_like('wp-image-' . $id . '"') . '%',
				'%' . $wpdb->esc_like('"id":' . $id . ',') . '%',
				'%' . $wpdb->esc_like('"id":' . $id . '}') . '%'
			))) {
				return true;
			}
		}
		return false;
	}
}
