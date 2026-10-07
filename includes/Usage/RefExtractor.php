<?php

namespace LabelvierMediaUsage\Usage;

/**
 * Pulls file references out of raw database values:
 * - path: path relative to the uploads dir (from any URL / path containing an uploads
 *   marker, or a bare YYYY/MM/file.ext),
 * - name: lowercased basename with a known extension,
 * - id:   attachment id (wp-image-N, block attributes, shortcodes, media-ish keys, ACF).
 *
 * Serialized PHP is never unserialized; serialized data and JSON are scanned as text.
 * Gutenberg block attributes are decoded with json_decode only.
 */
class RefExtractor {

	/** Characters that end a path segment or a file name. */
	private const DELIM = '\s"\'<>()\[\]{}\\\\|,;?#*\/=:&!`^';

	/** Key fragments that suggest a value holds an attachment id. */
	private const MEDIA_KEY = '~(?:image|img|logo|icon|thumbnail|thumb|media|file|attachment|photo|picture|avatar|background|banner|poster|video|audio|gallery|pdf|document)~i';

	/** Key suffixes that are dimensions/settings, not ids (thumbnail_size_w etc.). */
	private const NON_ID_KEY = '~(?:^|[_\-])(?:w|h|width|height|size|sizes|crop|quality|count|total|limit|per_page|max|min|columns|cols|position|pos|opacity|x|y|enabled|enable|version|ver|type|mode|style|align|ratio|fit|time|date|timestamp|views|order)$~i';

	/** Core blocks whose id / mediaId / ids attributes are attachment ids. */
	private const MEDIA_BLOCKS = ['image', 'gallery', 'cover', 'media-text', 'video', 'audio', 'file'];

	private string $path_re;
	private string $date_re;
	private string $name_re;
	private AcfFieldTypes $acf;

	/**
	 * @param string[] $extensions known file extensions for name refs
	 * @param string[] $markers    path prefixes that precede an upload path, e.g. 'uploads/'
	 */
	public function __construct(array $extensions, array $markers, AcfFieldTypes $acf) {
		$d    = self::DELIM;
		$s    = "[^{$d}]";
		$term = "(?=$|[{$d}]|\.(?![a-z0-9]))";
		$m    = implode('|', array_map(static fn($x) => preg_quote($x, '~'), $markers));
		usort($extensions, static fn($a, $b) => strlen($b) - strlen($a));
		$e = implode('|', array_map(static fn($x) => preg_quote($x, '~'), array_unique($extensions)));

		$this->path_re = "~(?<![a-z0-9_-])(?:{$m})((?:{$s}+/)*{$s}+?\.[a-z0-9]{1,8}){$term}~i";
		$this->date_re = "~(?<![a-z0-9_-])(\d{4}/\d{2}/{$s}{1,200}?\.[a-z0-9]{1,8}){$term}~i";
		$this->name_re = "~(?<![^{$d}])({$s}{1,200}?\.(?:{$e})){$term}~i";
		$this->acf     = $acf;
	}

	/**
	 * @param string      $raw value as stored in the database
	 * @param string|null $key meta_key / option_name of the row, enables the media-key heuristic
	 * @return array<int, array{0: string, 1: string}> unique [ref_type, ref_value] pairs
	 */
	public function extract(string $raw, ?string $key = null): array {
		$out = [];

		if ($key !== null && self::is_media_key($key)) {
			foreach (self::ids_from_value($raw) as $id) {
				$out['id:' . $id] = ['id', (string) $id];
			}
		}

		$has_dot   = str_contains($raw, '.');
		$has_digit = strpbrk($raw, '0123456789') !== false;
		if (strlen($raw) < 5 || (!$has_dot && !$has_digit)) {
			return array_values($out);
		}

		if (str_contains($raw, '<!-- wp:')) {
			$this->extract_blocks($raw, $out);
		}

		$text = self::normalize($raw);
		$this->extract_text($text, $out, $has_dot, $has_digit);
		if (stripos($text, '%2f') !== false) {
			$this->extract_text(rawurldecode($text), $out, true, $has_digit);
		}

		return array_values($out);
	}

	/**
	 * Undo JSON slash escaping (`\/`, `\u002f`) and HTML entities.
	 */
	public static function normalize(string $s): string {
		if (str_contains($s, '\\')) {
			$s = preg_replace('~\\\\+u002f~i', '/', $s) ?? $s;
			$s = preg_replace('~\\\\+/~', '/', $s) ?? $s;
		}
		if (str_contains($s, '&')) {
			$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return $s;
	}

	public static function is_media_key(string $key): bool {
		return (bool) preg_match(self::MEDIA_KEY, $key) && !preg_match(self::NON_ID_KEY, $key);
	}

	/**
	 * Attachment ids from a stored value: "12", "12,13", serialized list, JSON list.
	 * Serialized arrays only yield values of integer keys or media-ish keys.
	 *
	 * @return int[]
	 */
	public static function ids_from_value(string $value): array {
		$value = trim($value);
		if ($value === '') {
			return [];
		}
		if (ctype_digit($value)) {
			return self::valid_ids([(int) $value]);
		}
		if (preg_match('~^\d+(?:\s*,\s*\d+)+$~', $value)) {
			return self::valid_ids(array_map('intval', explode(',', $value)));
		}
		if (str_starts_with($value, 'a:')) {
			$ids = [];
			if (preg_match_all('~(?:i:(\d+)|s:\d+:"([^"]{0,100})");(?:i:(\d+);|s:\d+:"(\d+)";)~', $value, $m, PREG_SET_ORDER)) {
				foreach ($m as $pair) {
					$is_list = ($pair[1] ?? '') !== '';
					if ($is_list || self::is_media_key($pair[2] ?? '') || ($pair[2] ?? '') === 'id') {
						$ids[] = (int) (($pair[3] ?? '') !== '' ? $pair[3] : ($pair[4] ?? 0));
					}
				}
			}
			return self::valid_ids($ids);
		}
		if (str_starts_with($value, '[')) {
			$decoded = json_decode($value, true);
			return is_array($decoded) ? self::ids_from_mixed($decoded) : [];
		}
		return [];
	}

	/**
	 * Ids from a decoded (JSON) value: int, numeric string, "1,2" or a flat list.
	 *
	 * @return int[]
	 */
	public static function ids_from_mixed(mixed $value): array {
		if (is_int($value)) {
			return self::valid_ids([$value]);
		}
		if (is_string($value)) {
			return preg_match('~^\s*\d+(?:\s*,\s*\d+)*\s*$~', $value) ? self::valid_ids(array_map('intval', explode(',', $value))) : [];
		}
		if (is_array($value)) {
			$ids = [];
			foreach ($value as $item) {
				if (is_int($item) || (is_string($item) && ctype_digit($item))) {
					$ids[] = (int) $item;
				}
			}
			return self::valid_ids($ids);
		}
		return [];
	}

	private static function valid_ids(array $ids): array {
		return array_values(array_unique(array_filter($ids, static fn($id) => $id > 0 && $id < PHP_INT_MAX)));
	}

	private function add_id(array &$out, int $id): void {
		if ($id > 0) {
			$out['id:' . $id] = ['id', (string) $id];
		}
	}

	private function extract_text(string $t, array &$out, bool $has_dot, bool $has_digit): void {
		if ($has_dot) {
			if (preg_match_all($this->path_re, $t, $m)) {
				foreach ($m[1] as $p) {
					$this->add_path($out, $p);
				}
			}
			if ($has_digit && preg_match('~\d{4}/\d{2}/~', $t) && preg_match_all($this->date_re, $t, $m)) {
				foreach ($m[1] as $p) {
					$this->add_path($out, $p);
				}
			}
			if (preg_match_all($this->name_re, $t, $m)) {
				foreach ($m[1] as $n) {
					$n = mb_strtolower(rawurldecode($n));
					if (strlen($n) <= 255) {
						$out['name:' . $n] = ['name', $n];
					}
				}
			}
		}

		if (!$has_digit) {
			return;
		}

		foreach (['~wp-image-(\d+)~', '~\battachment_(\d+)\b~', '~data-(?:attachment-)?id=["\']?(\d+)~i', '~[?&]attachment_id=(\d+)~'] as $re) {
			if (preg_match_all($re, $t, $m)) {
				foreach ($m[1] as $id) {
					$this->add_id($out, (int) $id);
				}
			}
		}

		if (str_contains($t, '[') && preg_match_all('~\[(?:gallery|playlist)\b[^\]]*?\b(?:ids|include)\s*=\s*["\']?([\d,\s]+)~i', $t, $m)) {
			foreach ($m[1] as $list) {
				foreach (explode(',', $list) as $id) {
					$this->add_id($out, (int) trim($id));
				}
			}
		}

		// Serialized "media_key" => id pairs (theme mods, plugin settings).
		if (str_contains($t, 's:') && preg_match_all('~s:\d+:"([^"]{1,100})";(?:i:(\d+);|s:\d+:"(\d+)";)~', $t, $m, PREG_SET_ORDER)) {
			foreach ($m as $pair) {
				if (self::is_media_key($pair[1])) {
					$this->add_id($out, (int) (($pair[2] ?? '') !== '' ? $pair[2] : ($pair[3] ?? 0)));
				}
			}
		}

		// JSON "media_key": id and "media_key": [ids].
		if (str_contains($t, '":')) {
			if (preg_match_all('~"([a-z0-9_\-]{1,100})"\s*:\s*"?(\d+)\b~i', $t, $m, PREG_SET_ORDER)) {
				foreach ($m as $pair) {
					if (self::is_media_key($pair[1])) {
						$this->add_id($out, (int) $pair[2]);
					}
				}
			}
			if (preg_match_all('~"([a-z0-9_\-]{1,100})"\s*:\s*\[([\d\s,"]*)\]~i', $t, $m, PREG_SET_ORDER)) {
				foreach ($m as $pair) {
					if (self::is_media_key($pair[1])) {
						foreach (explode(',', str_replace('"', '', $pair[2])) as $id) {
							$this->add_id($out, (int) trim($id));
						}
					}
				}
			}
		}
	}

	private function add_path(array &$out, string $path): void {
		$path = rawurldecode($path);
		$path = preg_replace('~/{2,}~', '/', str_replace('\\', '/', $path)) ?? $path;
		$path = ltrim($path, '/');
		while (str_starts_with($path, './')) {
			$path = substr($path, 2);
		}
		if ($path === '' || str_contains($path, '../') || strlen($path) > 1024) {
			return;
		}
		$out['path:' . $path] = ['path', $path];
	}

	/**
	 * Block comment attributes: core media blocks, media-ish keys and ACF block data
	 * ("field": 12 with sibling "_field": "field_xxx", or "field_xxx": 12).
	 */
	private function extract_blocks(string $raw, array &$out): void {
		if (!preg_match_all('~<!--\s+wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)\s+(\{.*?\})\s+/?-->~s', $raw, $m, PREG_SET_ORDER)) {
			return;
		}
		foreach ($m as $block) {
			$attrs = json_decode($block[2], true);
			if (!is_array($attrs)) {
				continue;
			}
			$name = str_starts_with($block[1], 'core/') ? substr($block[1], 5) : $block[1];
			if (in_array($name, self::MEDIA_BLOCKS, true)) {
				foreach (['id', 'mediaId', 'ids'] as $attr) {
					foreach (self::ids_from_mixed($attrs[$attr] ?? null) as $id) {
						$this->add_id($out, $id);
					}
				}
			}
			$this->walk_attrs($attrs, $out, 0);
		}
	}

	private function walk_attrs(array $attrs, array &$out, int $depth): void {
		if ($depth > 10) {
			return;
		}
		foreach ($attrs as $key => $value) {
			if (is_string($key) && $key !== '' && $key[0] !== '_') {
				$field = $attrs['_' . $key] ?? null;
				$media = false;
				if (is_string($field) && str_starts_with($field, 'field_')) {
					$media = $this->acf->is_media($field);
				} elseif (str_starts_with($key, 'field_')) {
					$media = $this->acf->is_media($key);
				}
				if ($media || (!is_array($value) && self::is_media_key($key)) || (is_array($value) && array_is_list($value) && self::is_media_key($key))) {
					foreach (self::ids_from_mixed($value) as $id) {
						$this->add_id($out, $id);
					}
				}
			}
			if (is_array($value)) {
				$this->walk_attrs($value, $out, $depth + 1);
			}
		}
	}
}
