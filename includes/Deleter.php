<?php

namespace LabelvierMediaUsage;

/**
 * Deletes indexed files from disk (and from the Media Library where needed).
 */
class Deleter {

	/** Maximum number of files handled in a single request. */
	public const MAX_PER_REQUEST = 2000;

	private const PROTECTED_NAMES = ['index.php', '.htaccess', 'web.config'];

	private FileRepository $repo;

	public function __construct(?FileRepository $repo = null) {
		$this->repo = $repo ?? new FileRepository();
	}

	/**
	 * @param int[] $file_ids   Row ids from lvmu_files.
	 * @param bool  $allow_used Also delete files whose usage_status is 'used'.
	 * @return array{deleted: int, freed: int, skipped: array<int, array{path: string, reason: string}>, errors: array<int, array{path: string, reason: string}>, remaining: int}
	 */
	public function delete(array $file_ids, bool $allow_used = false): array {
		$result = ['deleted' => 0, 'freed' => 0, 'skipped' => [], 'errors' => [], 'remaining' => 0];

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

		foreach (array_chunk($file_ids, 200) as $chunk) {
			$rows = $this->repo->find($chunk);
			// Process 'main' first so variants are removed together with their attachment.
			usort($rows, static fn($a, $b) => (int) ($b->file_role === 'main') <=> (int) ($a->file_role === 'main'));

			foreach ($rows as $row) {
				if (isset($gone[(int) $row->id])) {
					continue;
				}
				$this->delete_one($row, $base, $allow_used, $result, $gone);
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
		$name = strtolower(wp_basename($row->path));
		if (in_array($name, self::PROTECTED_NAMES, true)) {
			$this->skip($result, $row, __('Protected file.', 'labelvier-media-usage'));
			return;
		}
		if ($row->usage_status === 'used' && !$allow_used) {
			$this->skip($result, $row, __('File is in use.', 'labelvier-media-usage'));
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
			if ($variant->file_role === 'webp' && $real && $basedir && str_starts_with($real, $basedir . DIRECTORY_SEPARATOR)) {
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
}
