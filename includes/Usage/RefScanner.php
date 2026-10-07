<?php

namespace LabelvierMediaUsage\Usage;

use LabelvierMediaUsage\FileRepository;
use LabelvierMediaUsage\Indexer\PhaseInterface;
use LabelvierMediaUsage\ScanState;
use LabelvierMediaUsage\Schema;

/**
 * Phase 3: the global database search. Walks every text column of every table with the
 * WP prefix in primary key order and stores found references in lvmu_refs.
 *
 * Not counted as usage (rows are skipped):
 * - wp_posts with post_type attachment (guid / own title), revision, acf-field(-group)
 *   and post_status trash / auto-draft, plus the postmeta of those posts;
 * - postmeta keys in SKIP_META_KEYS (attachment bookkeeping, edit locks);
 * - options _transient_*, _site_transient_*, lvmu_*;
 * - spam / trashed comments;
 * - tables lvmu_*, actionscheduler_* and (multisite) sub-site tables;
 * - yoast_indexable rows describing attachments themselves.
 * Everything else counts, including caches like Yoast indexables / SEO links (safer).
 * References from an attachment's own postmeta are dropped later by the resolver.
 *
 * Text files inside uploads (css, html, js, svg, ...) are scanned too: a font or image that
 * is only referenced from such a file is in use (source_type file / source_table uploads).
 *
 * Query errors never silently skip a table: the batch is halved down to 1 row and if that
 * still fails an exception is thrown (the scan fails and deletes stay blocked), except for
 * tables matched by the filter `lvmu_ref_scan_error_tolerant_tables` (regex list).
 * A ref that cannot be stored throws as well.
 *
 * Work list items (kind): table (text scan), acf (ACF meta self-join), files (theme files),
 * uploads (text files in uploads).
 */
class RefScanner implements PhaseInterface {

	public const OPTION = 'lvmu_refs_state';

	public const SKIP_META_KEYS = [
		'_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes',
		'_wp_attachment_image_alt', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
		'_wp_trash_meta_status', '_wp_trash_meta_time', '_encloseme', '_pingme',
	];

	private const TEXT_TYPES      = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'];
	private const INT_TYPES       = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];
	private const POSTS_SKIP_COLS = ['post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_type', 'post_mime_type'];
	private const USERS_SKIP_COLS = ['user_pass', 'user_activation_key'];
	private const EXTRA_EXTENSIONS = ['psd', 'ai', 'eps', 'indd', 'svgz', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'vtt', 'srt', 'epub', 'jfif', 'dwg', 'swf'];
	private const THEME_FILE_EXTENSIONS = ['php', 'css', 'scss', 'sass', 'less', 'js', 'json', 'html', 'htm', 'twig'];

	private const UPLOAD_TEXT_EXTENSIONS = ['css', 'html', 'htm', 'js', 'json', 'svg', 'xml', 'txt'];
	private const UPLOAD_TEXT_MAX_BYTES = 2 * 1024 * 1024;
	private const UPLOAD_TEXT_MAX_FILES = 20000;

	private const TARGET_BYTES = 4 * 1024 * 1024;
	private const MIN_BATCH    = 10;
	private const MAX_BATCH    = 5000;
	private const INSERT_CHUNK = 500;

	private RefExtractor $extractor;
	private AcfFieldTypes $acf;

	public function start(int $scan_id): void {
		global $wpdb;
		$refs = Schema::refs_table();
		do {
			$deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$refs} WHERE scan_id = %d LIMIT 50000", $scan_id));
		} while ($deleted > 0);

		$acf   = AcfFieldTypes::build();
		$items = $this->build_work_list(!empty($acf) || function_exists('acf_get_field'));

		update_option(self::OPTION, [
			'scan_id' => $scan_id,
			'items'   => $items,
			'index'   => 0,
			'cursor'  => null,
			'offset'  => 0,
			'batch'   => 0,
			'found'   => 0,
			'rows'    => 0,
			'bytes'   => 0,
			'seconds' => 0.0,
			'skipped' => [],
			'acf'     => $acf,
		], false);

		ScanState::progress([
			'refs_table'        => $items[0]['table'] ?? '',
			'refs_tables_done'  => 0,
			'refs_tables_total' => count($items),
			'refs_found'        => 0,
		]);
	}

	public function run(int $scan_id, float $deadline): bool {
		$state = get_option(self::OPTION);
		if (!is_array($state) || ($state['scan_id'] ?? null) !== $scan_id) {
			$this->start($scan_id);
			$state = get_option(self::OPTION);
		}

		$this->acf       = new AcfFieldTypes($state['acf'] ?? []);
		$this->extractor = new RefExtractor(self::extensions(), self::markers(), $this->acf);

		do {
			$item = $state['items'][$state['index']] ?? null;
			if ($item === null) {
				break;
			}

			$started = microtime(true);
			$limit   = $state['batch'] ?: $item['batch'];
			if ($item['kind'] === 'files') {
				[$count, $bytes, $done] = $this->process_files($item, $state, $scan_id, $limit);
			} elseif ($item['kind'] === 'uploads') {
				[$count, $bytes, $done] = $this->process_uploads($item, $state, $scan_id, $limit);
			} else {
				[$count, $bytes, $done] = $this->process_table($item, $state, $scan_id, $limit);
				if (!$done && $state['batch'] && $state['batch'] < $limit) {
					$limit = $state['batch']; // reduced after a query error
				}
			}
			$elapsed = microtime(true) - $started;

			$state['rows']    += $count;
			$state['bytes']   += $bytes;
			$state['seconds'] += $elapsed;

			if ($done) {
				$state['index']++;
				$state['cursor'] = null;
				$state['offset'] = 0;
				$state['batch']  = 0;
			} elseif (!in_array($item['kind'], ['files', 'uploads'], true)) {
				// Adapt batch size to the amount of text per row.
				if ($bytes > self::TARGET_BYTES || $elapsed > 5) {
					$limit = max(self::MIN_BATCH, intdiv($limit, 2));
				} elseif ($bytes < self::TARGET_BYTES / 4 && $elapsed < 1) {
					$limit = min(self::MAX_BATCH, $limit * 2);
				}
				$state['batch'] = $limit;
			}

			update_option(self::OPTION, $state, false);
			ScanState::progress([
				'refs_table'        => $state['items'][$state['index']]['table'] ?? '',
				'refs_tables_done'  => $state['index'],
				'refs_tables_total' => count($state['items']),
				'refs_found'        => $state['found'],
			]);
		} while (microtime(true) < $deadline);

		if ($state['index'] >= count($state['items'])) {
			ScanState::progress([
				'refs_table'       => '',
				'refs_tables_done' => count($state['items']),
				'refs_rows'        => $state['rows'],
				'refs_skipped'     => implode('; ', $state['skipped']),
			]);
			return true;
		}
		return false;
	}

	/**
	 * Throughput numbers of the current / last run (rows, bytes, seconds).
	 */
	public static function stats(): array {
		$state = get_option(self::OPTION);
		return is_array($state) ? array_intersect_key($state, array_flip(['rows', 'bytes', 'seconds', 'found', 'skipped'])) : [];
	}

	public static function extensions(): array {
		return array_values(array_unique(array_merge(FileRepository::extensions(), self::EXTRA_EXTENSIONS)));
	}

	/**
	 * Path prefixes after which an uploads-relative path follows.
	 */
	public static function markers(): array {
		$markers = ['uploads/'];
		$path    = trim((string) wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH), '/');
		if ($path !== '' && basename($path) !== 'uploads') {
			$markers[] = $path . '/';
		}
		return $markers;
	}

	/**
	 * @return array{0: int, 1: int, 2: bool} rows processed, bytes scanned, table done
	 */
	private function process_table(array $item, array &$state, int $scan_id, int $limit): array {
		global $wpdb;

		$where = $item['where'] !== '' ? [$item['where']] : [];
		if ($item['pk'] !== '' && $state['cursor'] !== null) {
			$where[] = $item['pk_num']
				? $item['pk'] . ' > ' . (int) $state['cursor']
				: $wpdb->prepare($item['pk'] . ' > %s', $state['cursor']);
		}
		$base_sql = "SELECT {$item['select']} FROM {$item['from']}" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

		while (true) {
			$sql = $base_sql . ($item['pk'] !== ''
				? " ORDER BY {$item['pk']} LIMIT {$limit}"
				: " LIMIT {$limit} OFFSET " . (int) $state['offset']);

			$wpdb->last_error = '';
			$rows = $wpdb->get_results($sql, ARRAY_A);
			if ($wpdb->last_error === '') {
				break;
			}
			$error = $wpdb->last_error;
			if ($limit > 1) {
				$limit = max(1, intdiv($limit, 2));
				$state['batch'] = $limit;
				continue;
			}
			if ($this->error_tolerant($item['table'])) {
				$state['skipped'][] = $item['table'] . ' (' . $item['kind'] . '): ' . $error;
				return [0, 0, true];
			}
			throw new \RuntimeException(sprintf(
				/* translators: 1: table name, 2: kind of table, 3: database error */
				__('Reference scan failed on table %1$s (%2$s): %3$s', 'labelvier-media-usage'),
				$item['table'], $item['kind'],
				$error
			));
		}

		$refs  = [];
		$bytes = 0;
		foreach ($rows as $i => $row) {
			$parent = (string) ($row['__parent'] ?? ($state['offset'] + $i));
			$key    = isset($row['__key']) ? (string) $row['__key'] : null;

			if ($item['kind'] === 'acf') {
				$value  = (string) ($row['__val'] ?? '');
				$bytes += strlen($value);
				if ($this->acf->is_media((string) $row['__field'])) {
					foreach (RefExtractor::ids_from_value($value) as $id) {
						$refs[] = ['id', (string) $id, $item['source_type'], $item['table'], $parent, (string) $key];
					}
				}
				continue;
			}

			foreach ($item['cols'] as $col) {
				$value = $row[$col] ?? null;
				if ($value === null || $value === '') {
					continue;
				}
				$bytes += strlen($value);
				$field  = $key ?? $col;
				foreach ($this->extractor->extract((string) $value, $key) as [$type, $ref]) {
					$refs[] = [$type, $ref, $item['source_type'], $item['table'], $parent, $field];
				}
			}
		}

		$state['found'] += $this->insert($refs, $scan_id);

		$count = count($rows);
		if ($count && $item['pk'] !== '') {
			$state['cursor'] = $rows[$count - 1]['__pk'];
		}
		$state['offset'] += $count;

		return [$count, $bytes, $count < $limit];
	}

	private function error_tolerant(string $table): bool {
		foreach ((array) apply_filters('lvmu_ref_scan_error_tolerant_tables', []) as $re) {
			if (is_string($re) && @preg_match($re, $table)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Text files inside uploads (css, html, svg, ...): relative urls are resolved against
	 * the directory of the file. A referencing file counts even when it is unused itself.
	 */
	private function process_uploads(array $item, array &$state, int $scan_id, int $limit): array {
		$refs  = [];
		$bytes = 0;
		$base  = FileRepository::uploads_basedir();
		$files = array_slice($item['files'], $state['offset'], $limit);
		foreach ($files as $relative) {
			$content = @file_get_contents($base . '/' . $relative);
			if (!is_string($content) || $content === '') {
				continue;
			}
			$bytes += strlen($content);
			$dir    = str_contains($relative, '/') ? dirname($relative) : '';
			$self   = mb_strtolower(basename($relative));
			$found  = array_merge(
				$this->extractor->extract($content),
				$this->extractor->extract_relative($content, $dir)
			);
			foreach ($found as [$type, $ref]) {
				if (($type === 'path' && $ref === $relative) || ($type === 'name' && $ref === $self)) {
					continue; // a file referencing itself is not usage
				}
				$refs[] = [$type, $ref, 'file', 'uploads', $relative, 'content'];
			}
		}
		$state['found']  += $this->insert($refs, $scan_id);
		$state['offset'] += count($files);
		return [count($files), $bytes, $state['offset'] >= count($item['files'])];
	}

	/**
	 * Plugin internals (exports, caches) that live in uploads and never count as referencing.
	 */
	private static function is_internal(string $name): bool {
		return in_array($name, ['lvmu', 'lvmu-media-usage', 'labelvier-media-usage'], true) || str_starts_with($name, 'lvmu_');
	}

	/**
	 * Text files in uploads (not lvmu internals), relative to the uploads basedir.
	 *
	 * @return string[]
	 */
	private function uploads_text_files(): array {
		$base = FileRepository::uploads_basedir();
		if (!is_dir($base)) {
			return [];
		}
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
				static fn(\SplFileInfo $file) => !$file->isLink() && !self::is_internal(strtolower($file->getFilename()))
			)
		);
		foreach ($iterator as $file) {
			if (count($files) >= self::UPLOAD_TEXT_MAX_FILES) {
				break;
			}
			if ($file->isFile()
				&& $file->getSize() > 0
				&& $file->getSize() <= self::UPLOAD_TEXT_MAX_BYTES
				&& in_array(strtolower($file->getExtension()), self::UPLOAD_TEXT_EXTENSIONS, true)
			) {
				$files[] = substr(wp_normalize_path($file->getPathname()), strlen($base) + 1);
			}
		}
		return $files;
	}

	/**
	 * Theme files (php/css/js/...): images referenced from code are in use as well.
	 */
	private function process_files(array $item, array &$state, int $scan_id, int $limit): array {
		$refs  = [];
		$bytes = 0;
		$files = array_slice($item['files'], $state['offset'], $limit);
		foreach ($files as $relative) {
			$content = @file_get_contents(WP_CONTENT_DIR . '/' . $relative);
			if (!is_string($content) || $content === '') {
				continue;
			}
			$bytes += strlen($content);
			$parts  = explode('/', $relative, 3);
			foreach ($this->extractor->extract($content) as [$type, $ref]) {
				$refs[] = [$type, $ref, 'file', 'themes', $parts[1] ?? '', $parts[2] ?? $relative];
			}
		}
		$state['found']  += $this->insert($refs, $scan_id);
		$state['offset'] += count($files);
		return [count($files), $bytes, $state['offset'] >= count($item['files'])];
	}

	/**
	 * Multi-row insert into lvmu_refs, deduplicated per batch. Every value is scrubbed to
	 * valid UTF-8 and cut to its column length. A failing chunk is retried row by row; a row
	 * that still fails throws, so refs are never lost silently.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $refs
	 */
	private function insert(array $refs, int $scan_id): int {
		if (!$refs) {
			return 0;
		}
		$unique = [];
		foreach ($refs as [$type, $value, $source_type, $source_table, $source_id, $field]) {
			if (mb_strlen($value) > 1024) {
				continue;
			}
			$row = [
				mb_substr(mb_scrub($type, 'UTF-8'), 0, 10),
				mb_scrub($value, 'UTF-8'),
				mb_substr(mb_scrub($source_type, 'UTF-8'), 0, 20),
				mb_substr(mb_scrub($source_table, 'UTF-8'), 0, 64),
				mb_substr(mb_scrub($source_id, 'UTF-8'), 0, 64),
				mb_substr(mb_scrub($field, 'UTF-8'), 0, 191),
			];
			$unique[implode("\0", $row)] = $row;
		}

		foreach (array_chunk(array_values($unique), self::INSERT_CHUNK) as $chunk) {
			if ($this->insert_rows($chunk, $scan_id) !== false) {
				continue;
			}
			foreach ($chunk as $row) {
				if ($this->insert_rows([$row], $scan_id) !== false) {
					continue;
				}
				// utf8mb3 columns reject 4-byte characters: retry with those replaced.
				$row = array_map(static fn(string $v) => preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $v) ?? $v, $row);
				if ($this->insert_rows([$row], $scan_id) === false) {
					global $wpdb;
					throw new \RuntimeException(sprintf(
						/* translators: 1: reference value, 2: type, 3: database error */
						__('Could not store reference "%1$s" (%2$s): %3$s', 'labelvier-media-usage'),
						mb_substr($row[1], 0, 120), $row[0],
						$wpdb->last_error
					));
				}
			}
		}
		return count($unique);
	}

	/**
	 * @param array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $rows
	 * @return int|bool false on failure
	 */
	private function insert_rows(array $rows, int $scan_id) {
		global $wpdb;
		$table  = Schema::refs_table();
		$values = [];
		foreach ($rows as [$type, $value, $source_type, $source_table, $source_id, $field]) {
			$values[] = "('" . esc_sql($type) . "','" . esc_sql($value) . "','" . md5($type . ':' . $value) . "','"
				. esc_sql($source_type) . "','" . esc_sql($source_table) . "','" . esc_sql($source_id) . "','"
				. esc_sql($field) . "'," . $scan_id . ')';
		}
		$wpdb->last_error = '';
		return $wpdb->query("INSERT INTO {$table} (ref_type, ref_value, ref_hash, source_type, source_table, source_id, source_field, scan_id) VALUES " . implode(',', $values));
	}

	/**
	 * One item per table with text columns, then ACF sub steps, then theme files.
	 */
	private function build_work_list(bool $with_acf): array {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$like   = $wpdb->esc_like($prefix) . '%';

		$columns = $wpdb->get_results($wpdb->prepare(
			'SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c, c.DATA_TYPE AS d
			FROM information_schema.COLUMNS c
			INNER JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
			WHERE c.TABLE_SCHEMA = DATABASE() AND tb.TABLE_TYPE = %s AND c.TABLE_NAME LIKE %s
			ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION',
			'BASE TABLE',
			$like
		));
		$keys = $wpdb->get_results($wpdb->prepare(
			'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s AND TABLE_NAME LIKE %s',
			'PRIMARY',
			$like
		));

		$tables = [];
		foreach ($columns ?: [] as $col) {
			$tables[$col->t][$col->c] = strtolower($col->d);
		}
		$pks = [];
		foreach ($keys ?: [] as $key) {
			$pks[$key->t][] = $key->c;
		}

		$skip = apply_filters('lvmu_ref_scan_skip_tables', [
			'~^' . preg_quote($prefix, '~') . '(?:lvmu_|actionscheduler_)~',
			'~^' . preg_quote($prefix, '~') . '\d+_~',
		]);

		$items = [];
		foreach ($tables as $table => $cols) {
			if (!str_starts_with($table, $prefix)) {
				continue;
			}
			foreach ($skip as $re) {
				if (preg_match($re, $table)) {
					continue 2;
				}
			}
			$pk     = (isset($pks[$table]) && count($pks[$table]) === 1) ? $pks[$table][0] : null;
			$pk_num = $pk !== null && in_array($cols[$pk] ?? '', self::INT_TYPES, true);
			$item   = $this->table_item($table, $cols, $pk, $pk_num);
			if ($item) {
				$items[] = $item;
			}
		}

		if ($with_acf) {
			$items = array_merge($items, $this->acf_items(array_keys($tables)));
		}

		$files = $this->theme_files();
		if ($files) {
			$items[] = ['kind' => 'files', 'table' => 'theme files', 'files' => $files, 'batch' => 50];
		}

		$uploads = $this->uploads_text_files();
		if ($uploads) {
			$items[] = ['kind' => 'uploads', 'table' => 'uploads files', 'files' => $uploads, 'batch' => 50];
		}

		return $items;
	}

	private static function q(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	private function table_item(string $table, array $cols, ?string $pk, bool $pk_num): ?array {
		global $wpdb;
		$short = substr($table, strlen($wpdb->prefix));
		$text  = array_keys(array_filter($cols, static fn($type) => in_array($type, self::TEXT_TYPES, true)));
		$tq    = self::q($table);

		$item = [
			'kind'        => 'table',
			'table'       => $table,
			'source_type' => 'table',
			'from'        => $tq,
			'where'       => '',
			'pk'          => $pk !== null ? self::q($pk) : '',
			'pk_num'      => $pk_num,
			'parent'      => $pk !== null ? self::q($pk) : '',
			'key'         => '',
			'alias'       => '',
			'cols'        => $text,
			'batch'       => 500,
		];

		$skip_posts = "'attachment','revision','acf-field','acf-field-group'";
		$skip_stati = "'trash','auto-draft'";
		$meta = static function (string $source_type, string $parent) use (&$item) {
			$item['source_type'] = $source_type;
			$item['parent']      = self::q($parent);
			$item['key']         = '`meta_key`';
			$item['cols']        = ['meta_value'];
			$item['batch']       = 1000;
		};

		switch ($short) {
			case 'posts':
				$item['source_type'] = 'post';
				$item['cols']        = array_values(array_diff($text, self::POSTS_SKIP_COLS));
				$item['where']       = "post_type NOT IN ({$skip_posts}) AND post_status NOT IN ({$skip_stati})";
				$item['batch']       = 100;
				break;
			case 'postmeta':
				$meta('postmeta', 'post_id');
				$skip_keys     = implode(',', array_map(static fn($k) => "'" . esc_sql($k) . "'", self::SKIP_META_KEYS));
				$item['from']  = "{$tq} m INNER JOIN `{$wpdb->posts}` p ON p.ID = m.post_id";
				$item['alias'] = 'm.';
				$item['pk']    = 'm.`meta_id`';
				$item['parent'] = 'm.`post_id`';
				$item['key']   = 'm.`meta_key`';
				$item['where'] = "p.post_type NOT IN ('revision','acf-field','acf-field-group') AND p.post_status NOT IN ({$skip_stati}) AND m.meta_key NOT IN ({$skip_keys})";
				break;
			case 'termmeta':
				$meta('termmeta', 'term_id');
				break;
			case 'usermeta':
				$meta('usermeta', 'user_id');
				break;
			case 'commentmeta':
				$meta('commentmeta', 'comment_id');
				break;
			case 'options':
				$item['source_type'] = 'option';
				$item['key']         = '`option_name`';
				$item['cols']        = ['option_value'];
				$item['where']       = "LEFT(option_name, 11) <> '_transient_' AND LEFT(option_name, 16) <> '_site_transient_' AND LEFT(option_name, 5) <> 'lvmu_'";
				$item['batch']       = 200;
				break;
			case 'comments':
				$item['source_type'] = 'comment';
				$item['where']       = "comment_approved NOT IN ('spam','trash')";
				break;
			case 'terms':
			case 'term_taxonomy':
				$item['source_type'] = 'term';
				$item['parent']      = '`term_id`';
				break;
			case 'users':
				$item['source_type'] = 'user';
				$item['cols']        = array_values(array_diff($text, self::USERS_SKIP_COLS));
				break;
			default:
				if (isset($cols['meta_key'], $cols['meta_value']) && $pk !== null) {
					$item['key']  = '`meta_key`';
					$item['cols'] = ['meta_value'];
				}
				if (str_contains($short, 'yoast_indexable') && isset($cols['object_sub_type'])) {
					$item['where'] = "(object_sub_type IS NULL OR object_sub_type <> 'attachment')"
						. (isset($cols['post_status']) ? " AND (post_status IS NULL OR post_status NOT IN ({$skip_stati}))" : '');
				}
		}

		// Key column itself is not scanned for refs (it is the field name).
		if ($item['key'] !== '') {
			$item['cols'] = array_values(array_diff($item['cols'], ['meta_key', 'option_name']));
		}
		if (!$item['cols']) {
			return null;
		}

		$select = [];
		if ($item['pk'] !== '') {
			$select[] = $item['pk'] . ' AS __pk';
			$select[] = $item['parent'] . ' AS __parent';
		}
		if ($item['key'] !== '') {
			$select[] = $item['key'] . ' AS __key';
		}
		foreach ($item['cols'] as $col) {
			$select[] = $item['alias'] . self::q($col);
		}
		$item['select'] = implode(', ', $select);

		return $item;
	}

	/**
	 * ACF: value rows whose sibling "_{name}" row holds a field key of a media field.
	 * Works for nested repeater / flexible / group names (rows_0_image) too.
	 */
	private function acf_items(array $tables): array {
		global $wpdb;
		$items      = [];
		$skip_stati = "'trash','auto-draft'";

		$meta = [
			'postmeta'    => ['post_id', 'meta_id', 'postmeta'],
			'termmeta'    => ['term_id', 'meta_id', 'termmeta'],
			'usermeta'    => ['user_id', 'umeta_id', 'usermeta'],
			'commentmeta' => ['comment_id', 'meta_id', 'commentmeta'],
		];
		foreach ($meta as $short => [$parent, $pk, $source_type]) {
			$table = $wpdb->prefix . $short;
			if (!in_array($table, $tables, true)) {
				continue;
			}
			$from  = "`{$table}` k INNER JOIN `{$table}` m ON m.`{$parent}` = k.`{$parent}` AND m.meta_key = SUBSTRING(k.meta_key, 2)";
			$where = "LEFT(k.meta_key, 1) = '_' AND LEFT(k.meta_value, 6) = 'field_'";
			if ($short === 'postmeta') {
				$from  .= " INNER JOIN `{$wpdb->posts}` p ON p.ID = k.post_id";
				$where .= " AND p.post_type NOT IN ('revision','acf-field','acf-field-group') AND p.post_status NOT IN ({$skip_stati})";
			}
			$items[] = [
				'kind'        => 'acf',
				'table'       => $table,
				'source_type' => $source_type,
				'from'        => $from,
				'where'       => $where,
				'pk'          => "k.`{$pk}`",
				'pk_num'      => true,
				'select'      => "k.`{$pk}` AS __pk, k.`{$parent}` AS __parent, m.meta_key AS __key, m.meta_value AS __val, k.meta_value AS __field",
				'cols'        => [],
				'batch'       => 2000,
			];
		}

		if (in_array($wpdb->options, $tables, true)) {
			$items[] = [
				'kind'        => 'acf',
				'table'       => $wpdb->options,
				'source_type' => 'option',
				'from'        => "`{$wpdb->options}` k INNER JOIN `{$wpdb->options}` m ON m.option_name = SUBSTRING(k.option_name, 2)",
				'where'       => "LEFT(k.option_name, 1) = '_' AND LEFT(k.option_value, 6) = 'field_'",
				'pk'          => 'k.`option_id`',
				'pk_num'      => true,
				'select'      => 'k.`option_id` AS __pk, m.`option_id` AS __parent, m.option_name AS __key, m.option_value AS __val, k.option_value AS __field',
				'cols'        => [],
				'batch'       => 2000,
			];
		}

		return $items;
	}

	/**
	 * Text files of the active (parent + child) theme, relative to WP_CONTENT_DIR.
	 *
	 * @return string[]
	 */
	private function theme_files(): array {
		$dirs    = array_unique([get_stylesheet_directory(), get_template_directory()]);
		$content = wp_normalize_path(WP_CONTENT_DIR);
		$files   = [];

		foreach ($dirs as $dir) {
			$dir = wp_normalize_path($dir);
			if (!is_dir($dir) || !str_starts_with($dir, $content . '/')) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
					static fn(\SplFileInfo $file) => !($file->isDir() && in_array($file->getFilename(), ['node_modules', 'vendor', '.git'], true))
				)
			);
			foreach ($iterator as $file) {
				if (count($files) >= 5000) {
					break 2;
				}
				if ($file->isFile()
					&& $file->getSize() <= 2 * 1024 * 1024
					&& in_array(strtolower($file->getExtension()), self::THEME_FILE_EXTENSIONS, true)
				) {
					$files[] = substr(wp_normalize_path($file->getPathname()), strlen($content) + 1);
				}
			}
		}

		return $files;
	}
}
