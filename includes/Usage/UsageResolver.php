<?php

namespace LabelvierMediaUsage\Usage;

use LabelvierMediaUsage\Indexer\PhaseInterface;
use LabelvierMediaUsage\ScanState;
use LabelvierMediaUsage\Schema;

/**
 * Phase 4: decide used / unused per file from the refs found in phase 3. Set based SQL,
 * batched by file id (orphans) and attachment id (Media Library files).
 *
 * Steps:
 * 1. direct:      reset every file to unused; orphans are used when a path ref equals
 *                 their path or a name ref equals their lowercased filename.
 * 2. attachments: an attachment is used when any of its files matches a path/name ref
 *                 or an id ref equals its id. Then ALL its files are used (srcset may
 *                 serve any subsize / webp). Refs from the attachment's own post /
 *                 postmeta never count (self references).
 * 3. webp:        an unused orphan .webp/.avif whose source image (foo.jpg.webp or
 *                 foo.webp next to foo.jpg/png/gif) is used becomes used.
 *
 * usage_count = number of distinct sources (row + field), usage_sources = first 10 as JSON
 * [{type, table, id, field}].
 */
class UsageResolver implements PhaseInterface {

	public const OPTION = 'lvmu_resolve_state';

	private const FILE_BATCH  = 2000;
	private const ATT_BATCH   = 500;
	private const MAX_SOURCES = 10;

	public function start(int $scan_id): void {
		global $wpdb;
		$files = Schema::files_table();
		$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$files} WHERE scan_id = %d", $scan_id));

		update_option(self::OPTION, ['scan_id' => $scan_id, 'step' => 'direct', 'cursor' => 0, 'done' => 0, 'total' => $total], false);
		ScanState::progress(['resolve_step' => 'direct', 'resolve_done' => 0, 'resolve_total' => $total]);
	}

	public function run(int $scan_id, float $deadline): bool {
		global $wpdb;
		$state = get_option(self::OPTION);
		if (!is_array($state) || ($state['scan_id'] ?? null) !== $scan_id) {
			$this->start($scan_id);
			$state = get_option(self::OPTION);
		}

		$wpdb->query('SET SESSION group_concat_max_len = 1048576');

		do {
			switch ($state['step']) {
				case 'direct':
					if (!$this->direct_batch($scan_id, $state)) {
						$state['step']   = 'attachments';
						$state['cursor'] = 0;
					}
					break;
				case 'attachments':
					if (!$this->attachment_batch($scan_id, $state)) {
						$state['step'] = 'webp';
					}
					break;
				case 'webp':
					$this->webp($scan_id);
					$state['step'] = 'done';
					break;
			}

			update_option(self::OPTION, $state, false);
			ScanState::progress(['resolve_step' => $state['step'], 'resolve_done' => $state['done'], 'resolve_total' => $state['total']]);
		} while ($state['step'] !== 'done' && microtime(true) < $deadline);

		return $state['step'] === 'done';
	}

	/**
	 * Reset a range of files and resolve the orphans in it.
	 */
	private function direct_batch(int $scan_id, array &$state): bool {
		global $wpdb;
		$files = Schema::files_table();
		$refs  = Schema::refs_table();
		$lo    = (int) $state['cursor'];

		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT id FROM {$files} WHERE scan_id = %d AND id > %d ORDER BY id LIMIT %d",
			$scan_id,
			$lo,
			self::FILE_BATCH
		));
		if (!$ids) {
			return false;
		}
		$hi = (int) end($ids);

		$wpdb->query($wpdb->prepare(
			"UPDATE {$files} SET usage_status = 'unused', usage_count = 0, usage_sources = NULL WHERE scan_id = %d AND id > %d AND id <= %d",
			$scan_id,
			$lo,
			$hi
		));

		$range = $wpdb->prepare('f.scan_id = %d AND f.id > %d AND f.id <= %d AND f.attachment_id IS NULL', $scan_id, $lo, $hi);
		$rs    = (int) $scan_id;
		$src   = self::src_sql();
		$rows  = $wpdb->get_results(
			"SELECT x.k, COUNT(DISTINCT x.src) AS c, GROUP_CONCAT(DISTINCT x.src ORDER BY x.src SEPARATOR '\\n') AS s FROM (
				SELECT f.id AS k, {$src} AS src FROM {$files} f
				INNER JOIN {$refs} r ON r.ref_hash = MD5(CONCAT('path:', f.path)) AND r.ref_type = 'path' AND r.scan_id = {$rs}
				WHERE {$range}
				UNION ALL
				SELECT f.id AS k, {$src} AS src FROM {$files} f
				INNER JOIN {$refs} r ON r.ref_hash = MD5(CONCAT('name:', LOWER(f.filename))) AND r.ref_type = 'name' AND r.scan_id = {$rs}
				WHERE {$range}
			) x GROUP BY x.k"
		);
		$this->apply('id', $rows ?: [], $scan_id);

		$state['cursor'] = $hi;
		$state['done']  += count($ids);
		return true;
	}

	/**
	 * Resolve a batch of attachments and propagate the result to all of their files.
	 */
	private function attachment_batch(int $scan_id, array &$state): bool {
		global $wpdb;
		$files = Schema::files_table();
		$refs  = Schema::refs_table();

		$aids = array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT attachment_id FROM {$files} WHERE scan_id = %d AND attachment_id > %d ORDER BY attachment_id LIMIT %d",
			$scan_id,
			(int) $state['cursor'],
			self::ATT_BATCH
		)));
		if (!$aids) {
			return false;
		}

		$in = implode(',', $aids);

		// Attachments sharing a file with another attachment (WPML/Polylang duplicates,
		// duplicate imports) are not linked to that file; an id ref to such a duplicate
		// counts for the attachment that owns the file.
		$dups = get_option(\LabelvierMediaUsage\Indexer\AttachmentMapper::DUP_OPTION, []);
		$dups = is_array($dups) ? array_filter($dups, static fn($owner) => in_array((int) $owner, $aids, true)) : [];
		$hash_ids = $aids;
		$map_cases = '';
		if ($dups) {
			$hash_ids = array_merge($aids, array_map('intval', array_keys($dups)));
			$cases    = [];
			foreach ($dups as $dup => $owner) {
				$cases[] = 'WHEN ' . (int) $dup . ' THEN ' . (int) $owner;
			}
			$map_cases = 'CASE CAST(r.ref_value AS UNSIGNED) ' . implode(' ', $cases) . ' ELSE CAST(r.ref_value AS UNSIGNED) END';
		}
		$id_key = $map_cases ?: 'CAST(r.ref_value AS UNSIGNED)';
		$hashes = implode(',', array_map(static fn($id) => "'" . md5('id:' . $id) . "'", $hash_ids));
		$rs     = (int) $scan_id;
		$src    = self::src_sql();
		$self   = "NOT (r.source_type IN ('post','postmeta') AND r.source_id = CAST(f.attachment_id AS CHAR))";
		$range  = "f.scan_id = {$rs} AND f.attachment_id IN ({$in})";

		$rows = $wpdb->get_results(
			"SELECT x.k, COUNT(DISTINCT x.src) AS c, GROUP_CONCAT(DISTINCT x.src ORDER BY x.src SEPARATOR '\\n') AS s FROM (
				SELECT f.attachment_id AS k, {$src} AS src FROM {$files} f
				INNER JOIN {$refs} r ON r.ref_hash = MD5(CONCAT('path:', f.path)) AND r.ref_type = 'path' AND r.scan_id = {$rs}
				WHERE {$range} AND {$self}
				UNION ALL
				SELECT f.attachment_id AS k, {$src} AS src FROM {$files} f
				INNER JOIN {$refs} r ON r.ref_hash = MD5(CONCAT('name:', LOWER(f.filename))) AND r.ref_type = 'name' AND r.scan_id = {$rs}
				WHERE {$range} AND {$self}
				UNION ALL
				SELECT {$id_key} AS k, {$src} AS src FROM {$refs} r
				WHERE r.ref_hash IN ({$hashes}) AND r.ref_type = 'id' AND r.scan_id = {$rs}
				AND NOT (r.source_type IN ('post','postmeta') AND r.source_id = r.ref_value)
			) x GROUP BY x.k"
		);
		$this->apply('attachment_id', $rows ?: [], $scan_id);

		$state['cursor'] = end($aids);
		$state['done']  += (int) $wpdb->get_var("SELECT COUNT(*) FROM {$files} f WHERE {$range}");
		return true;
	}

	/**
	 * Orphan webp/avif copies of used images are used too.
	 */
	private function webp(int $scan_id): void {
		global $wpdb;
		$files = Schema::files_table();
		$base  = 'LEFT(w.path, CHAR_LENGTH(w.path) - 5)';
		$cands = ["MD5({$base})"];
		foreach (['.jpg', '.jpeg', '.png', '.gif', '.JPG', '.JPEG', '.PNG', '.GIF'] as $ext) {
			$cands[] = "MD5(CONCAT({$base}, '{$ext}'))";
		}
		$wpdb->query($wpdb->prepare(
			"UPDATE {$files} w INNER JOIN {$files} o ON o.path_hash IN (" . implode(', ', $cands) . ") AND o.scan_id = w.scan_id AND o.usage_status = 'used'
			SET w.usage_status = 'used', w.usage_count = o.usage_count, w.usage_sources = o.usage_sources
			WHERE w.scan_id = %d AND w.usage_status = 'unused' AND w.attachment_id IS NULL AND w.extension IN ('webp', 'avif')",
			$scan_id
		));
	}

	private static function src_sql(): string {
		return 'CONCAT_WS(CHAR(9), r.source_type, r.source_table, r.source_id, r.source_field)';
	}

	/**
	 * Mark rows used with count + sources, one UPDATE per batch (CASE on $column).
	 *
	 * @param array<int, object{k: string, c: string, s: string|null}> $rows
	 */
	private function apply(string $column, array $rows, int $scan_id): void {
		global $wpdb;
		if (!$rows) {
			return;
		}
		$files   = Schema::files_table();
		$keys    = [];
		$counts  = [];
		$sources = [];
		foreach ($rows as $row) {
			$key       = (int) $row->k;
			$keys[]    = $key;
			$counts[]  = 'WHEN ' . $key . ' THEN ' . (int) $row->c;
			$sources[] = 'WHEN ' . $key . " THEN '" . esc_sql(wp_json_encode(self::parse_sources((string) $row->s))) . "'";
		}
		$wpdb->query(
			"UPDATE {$files} SET usage_status = 'used',
			usage_count = CASE {$column} " . implode(' ', $counts) . " END,
			usage_sources = CASE {$column} " . implode(' ', $sources) . " END
			WHERE scan_id = " . (int) $scan_id . " AND {$column} IN (" . implode(',', $keys) . ')'
		);
	}

	/**
	 * GROUP_CONCAT output (one "type\ttable\tid\tfield" per line) to max 10 source items.
	 */
	private static function parse_sources(string $concat): array {
		$items = [];
		foreach (explode("\n", $concat) as $line) {
			$parts = explode("\t", $line);
			if (count($parts) !== 4) {
				continue;
			}
			$items[] = ['type' => $parts[0], 'table' => $parts[1], 'id' => $parts[2], 'field' => $parts[3]];
			if (count($items) >= self::MAX_SOURCES) {
				break;
			}
		}
		return $items;
	}
}
