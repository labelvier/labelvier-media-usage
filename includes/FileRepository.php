<?php

namespace LabelvierMediaUsage;

/**
 * Data access for the lvmu_files table. All paths are relative to the uploads basedir
 * with forward slashes.
 */
class FileRepository {

	public const ROLES  = ['main', 'original', 'subsize', 'webp', 'orphan'];
	public const TYPES  = ['image', 'video', 'audio', 'document', 'archive', 'database', 'log', 'code', 'other'];
	public const USAGES = ['used', 'unused', 'unknown'];

	private const TYPE_MAP = [
		'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tif', 'tiff', 'ico', 'heic'],
		'video'    => ['mp4', 'mov', 'webm', 'm4v', 'avi', 'wmv', 'mkv', 'ogv', 'mpg', 'mpeg'],
		'audio'    => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma'],
		'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'rtf', 'key', 'pages', 'numbers'],
		'archive'  => ['zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2', 'xz', 'wpress'],
		'database' => ['sql', 'sqlite', 'db', 'bak'],
		'log'      => ['log'],
		'code'     => ['php', 'js', 'css', 'html', 'htm', 'json', 'xml', 'htaccess', 'map'],
	];

	public static function file_type_for(string $extension): string {
		$extension = strtolower($extension);
		foreach (self::TYPE_MAP as $type => $extensions) {
			if (in_array($extension, $extensions, true)) {
				return $type;
			}
		}
		return 'other';
	}

	public static function uploads_basedir(): string {
		return wp_normalize_path(untrailingslashit(wp_get_upload_dir()['basedir']));
	}

	public static function absolute_path(string $relative): string {
		return self::uploads_basedir() . '/' . ltrim($relative, '/');
	}

	/**
	 * Insert or update file rows by path. Each row needs: path, size, mtime.
	 * Optional: attachment_id, file_role. Resets usage to unknown.
	 */
	public function upsert_many(array $rows, int $scan_id): void {
		global $wpdb;
		if (!$rows) {
			return;
		}
		$table = Schema::files_table();
		$now   = current_time('mysql', true);

		foreach (array_chunk($rows, 200) as $chunk) {
			$values = [];
			foreach ($chunk as $row) {
				$path      = ltrim(wp_normalize_path($row['path']), '/');
				$filename  = wp_basename($path);
				$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
				$values[]  = $wpdb->prepare(
					'(%s, %s, %s, %s, %s, %d, %d, %s, %d, %s)',
					$path,
					md5($path),
					$filename,
					$extension,
					self::file_type_for($extension),
					(int) $row['size'],
					(int) $row['mtime'],
					$row['file_role'] ?? 'orphan',
					$scan_id,
					$now
				);
			}
			$wpdb->query(
				"INSERT INTO {$table} (path, path_hash, filename, extension, file_type, size, mtime, file_role, scan_id, indexed_at) VALUES "
				. implode(',', $values)
				. ' ON DUPLICATE KEY UPDATE size = VALUES(size), mtime = VALUES(mtime), file_role = VALUES(file_role),'
				. " attachment_id = NULL, usage_status = 'unknown', usage_count = 0, usage_sources = NULL,"
				. ' scan_id = VALUES(scan_id), indexed_at = VALUES(indexed_at)'
			);
		}
	}

	/**
	 * Link a file (by relative path) to an attachment with a role. No-op when the file
	 * was not found on disk.
	 */
	public function assign_attachment(string $path, int $attachment_id, string $role): void {
		global $wpdb;
		$path = ltrim(wp_normalize_path($path), '/');
		$wpdb->update(
			Schema::files_table(),
			['attachment_id' => $attachment_id, 'file_role' => $role],
			['path_hash' => md5($path)],
			['%d', '%s'],
			['%s']
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function find(array $ids): array {
		global $wpdb;
		$ids = array_filter(array_map('intval', $ids));
		if (!$ids) {
			return [];
		}
		$table = Schema::files_table();
		$in    = implode(',', $ids);
		return $wpdb->get_results("SELECT * FROM {$table} WHERE id IN ({$in})");
	}

	/**
	 * @return array<int, object>
	 */
	public function find_by_attachment(int $attachment_id): array {
		global $wpdb;
		$table = Schema::files_table();
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE attachment_id = %d", $attachment_id));
	}

	public function delete(array $ids): void {
		global $wpdb;
		$ids = array_filter(array_map('intval', $ids));
		if (!$ids) {
			return;
		}
		$table = Schema::files_table();
		$wpdb->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $ids) . ')');
	}

	/**
	 * List files with filters, used by the admin list table.
	 *
	 * @param array{
	 *   file_type?: string, file_role?: string, usage_status?: string, orphaned?: bool,
	 *   search?: string, min_size?: int, attachment_id?: int,
	 *   orderby?: string, order?: string, page?: int, per_page?: int
	 * } $args
	 * @return array{items: array<int, object>, total: int, total_size: int}
	 */
	public function query(array $args = []): array {
		global $wpdb;
		$table = Schema::files_table();
		[$where, $params] = $this->where($args);

		$orderby  = in_array($args['orderby'] ?? '', ['size', 'path', 'mtime', 'filename', 'usage_count'], true) ? $args['orderby'] : 'size';
		$order    = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
		$per_page = max(1, (int) ($args['per_page'] ?? 50));
		$page     = max(1, (int) ($args['page'] ?? 1));
		$offset   = ($page - 1) * $per_page;

		$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
		$prepare   = static fn(string $sql) => $params ? $wpdb->prepare($sql, $params) : $sql;

		$totals = $wpdb->get_row($prepare("SELECT COUNT(*) AS total, COALESCE(SUM(size), 0) AS total_size FROM {$table} {$sql_where}"));
		$items  = $wpdb->get_results($prepare("SELECT * FROM {$table} {$sql_where} ORDER BY {$orderby} {$order}, id ASC LIMIT {$per_page} OFFSET {$offset}"));

		return [
			'items'      => $items ?: [],
			'total'      => (int) $totals->total,
			'total_size' => (int) $totals->total_size,
		];
	}

	/**
	 * Ids of all rows matching the filters (for "select all matching" bulk actions).
	 *
	 * @return int[]
	 */
	public function ids(array $args = []): array {
		global $wpdb;
		$table = Schema::files_table();
		[$where, $params] = $this->where($args);
		$sql = "SELECT id FROM {$table}" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
		return array_map('intval', $wpdb->get_col($params ? $wpdb->prepare($sql, $params) : $sql));
	}

	private function where(array $args): array {
		global $wpdb;
		$where  = [];
		$params = [];

		if (!empty($args['file_type']) && in_array($args['file_type'], self::TYPES, true)) {
			$where[]  = 'file_type = %s';
			$params[] = $args['file_type'];
		}
		if (!empty($args['file_role']) && in_array($args['file_role'], self::ROLES, true)) {
			$where[]  = 'file_role = %s';
			$params[] = $args['file_role'];
		}
		if (!empty($args['usage_status']) && in_array($args['usage_status'], self::USAGES, true)) {
			$where[]  = 'usage_status = %s';
			$params[] = $args['usage_status'];
		}
		if (!empty($args['orphaned'])) {
			$where[] = 'attachment_id IS NULL';
		}
		if (!empty($args['attachment_id'])) {
			$where[]  = 'attachment_id = %d';
			$params[] = (int) $args['attachment_id'];
		}
		if (!empty($args['search'])) {
			$where[]  = 'path LIKE %s';
			$params[] = '%' . $wpdb->esc_like($args['search']) . '%';
		}
		if (!empty($args['min_size'])) {
			$where[]  = 'size >= %d';
			$params[] = (int) $args['min_size'];
		}

		return [$where, $params];
	}

	/**
	 * Totals for the summary cards.
	 *
	 * @return array{
	 *   total: array{count: int, size: int},
	 *   by_role: array<string, array{count: int, size: int}>,
	 *   by_type: array<string, array{count: int, size: int}>,
	 *   by_usage: array<string, array{count: int, size: int}>,
	 *   orphaned_unused: array{count: int, size: int}
	 * }
	 */
	public function summary(): array {
		global $wpdb;
		$table = Schema::files_table();

		$group = static function (string $column) use ($wpdb, $table): array {
			$out = [];
			foreach ($wpdb->get_results("SELECT {$column} AS k, COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM {$table} GROUP BY {$column}") as $row) {
				$out[$row->k] = ['count' => (int) $row->c, 'size' => (int) $row->s];
			}
			return $out;
		};

		$total    = $wpdb->get_row("SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM {$table}");
		$orphaned = $wpdb->get_row("SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM {$table} WHERE attachment_id IS NULL AND usage_status = 'unused'");

		return [
			'total'           => ['count' => (int) $total->c, 'size' => (int) $total->s],
			'by_role'         => $group('file_role'),
			'by_type'         => $group('file_type'),
			'by_usage'        => $group('usage_status'),
			'orphaned_unused' => ['count' => (int) $orphaned->c, 'size' => (int) $orphaned->s],
		];
	}
}
