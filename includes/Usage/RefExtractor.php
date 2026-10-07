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
	private string $marker_re = '';
	/** @var array<string, true> */
	private array $ext_set = [];

	/** Attribute / key names (besides media-ish keys) that hold attachment ids. */
	private const ID_ATTR = '~(?:_ids?|ids|images?|gallery)$~i';

	/** Unified id list: "12", "12,13", "12|13". */
	private const ID_LIST = '\d+(?:\s*[,|]\s*\d+)*';

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
		$this->marker_re = "~(?<![a-z0-9_-])(?:{$m})(.+)\$~is";
		$this->ext_set   = array_fill_keys(array_map('strtolower', $extensions), true);
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
	 * Attribute / JSON key that (probably) holds attachment ids: media-ish keys plus
	 * *_id, *_ids, ids, image(s), gallery.
	 */
	public static function is_id_key(string $key): bool {
		if (self::is_media_key($key)) {
			return true;
		}
		return (bool) preg_match(self::ID_ATTR, $key) && !preg_match(self::NON_ID_KEY, $key);
	}

	/**
	 * Split "1,2" / "1|2" / "1, 2" into ints.
	 *
	 * @return int[]
	 */
	private static function split_ids(string $list): array {
		return array_map('intval', preg_split('~\s*[,|]\s*~', trim($list)) ?: []);
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
		if (preg_match('~^' . self::ID_LIST . '$~', $value)) {
			return self::valid_ids(self::split_ids($value));
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
		if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
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
			return preg_match('~^\s*' . self::ID_LIST . '\s*$~', $value) ? self::valid_ids(self::split_ids($value)) : [];
		}
		if (is_array($value)) {
			$ids = [];
			foreach (['id', 'ID', 'attachment_id'] as $k) {
				if (isset($value[$k]) && !is_array($value[$k])) {
					$ids = array_merge($ids, self::ids_from_mixed($value[$k]));
				}
			}
			foreach ($value as $item) {
				if (is_int($item) || (is_string($item) && ctype_digit($item))) {
					$ids[] = (int) $item;
				} elseif (is_string($item)) {
					$ids = array_merge($ids, self::ids_from_mixed($item));
				} elseif (is_array($item)) {
					// Media objects: {"id": 12, "url": ...} / {"ID": 12}.
					foreach (['id', 'ID', 'attachment_id'] as $k) {
						if (isset($item[$k])) {
							$ids = array_merge($ids, self::ids_from_mixed($item[$k]));
						}
					}
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
					$this->add_name($out, $n);
				}
			}
			if (str_contains($t, '=') && preg_match_all('~=\s*(?:"([^"<>]{1,1100})"|\'([^\'<>]{1,1100})\')~', $t, $m, PREG_SET_ORDER)) {
				foreach ($m as $pair) {
					$v = $pair[1] !== '' ? $pair[1] : ($pair[2] ?? '');
					if (str_contains($v, '.')) {
						$this->add_attribute_value($out, $v);
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

		if (str_contains($t, '[') && preg_match_all('~\[(?:gallery|playlist)\b[^\]]*?\b(?:ids|include)\s*=\s*\\\\?["\']?(' . self::ID_LIST . ')~i', $t, $m)) {
			foreach ($m[1] as $list) {
				foreach (self::split_ids($list) as $id) {
					$this->add_id($out, $id);
				}
			}
		}

		// Generic attribute="12" / attribute="12,13": [vc_single_image image="1"], [et_pb_gallery gallery_ids="1,2"].
		if (str_contains($t, '=') && preg_match_all('~\b([\w-]+)\s*=\s*\\\\*["\']?(' . self::ID_LIST . ')~', $t, $m, PREG_SET_ORDER)) {
			foreach ($m as $pair) {
				if (self::is_id_key($pair[1])) {
					foreach (self::split_ids($pair[2]) as $id) {
						$this->add_id($out, $id);
					}
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
			if (preg_match_all('~"([a-z0-9_\-]{1,100})"\s*:\s*"?(' . self::ID_LIST . ')~i', $t, $m, PREG_SET_ORDER)) {
				foreach ($m as $pair) {
					if (self::is_id_key($pair[1])) {
						foreach (self::split_ids($pair[2]) as $id) {
							$this->add_id($out, $id);
						}
					}
				}
			}
			if (preg_match_all('~"([a-z0-9_\-]{1,100})"\s*:\s*\[([\d\s,"]*)\]~i', $t, $m, PREG_SET_ORDER)) {
				foreach ($m as $pair) {
					if (self::is_id_key($pair[1])) {
						foreach (explode(',', str_replace('"', '', $pair[2])) as $id) {
							foreach (self::split_ids($id) as $one) {
								$this->add_id($out, $one);
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Decoded variants of an encoded string: rawurldecode, urldecode (plus as space) and,
	 * when decoding gives invalid UTF-8 (latin1 `caf%E9.pdf`), the latin1 -> UTF-8
	 * conversion plus the raw form.
	 *
	 * @return string[]
	 */
	private static function variants(string $s): array {
		$v = [];
		foreach ([rawurldecode($s), urldecode($s)] as $d) {
			if (mb_check_encoding($d, 'UTF-8')) {
				$v[$d] = true;
			} else {
				$v[mb_convert_encoding($d, 'UTF-8', 'ISO-8859-1')] = true;
				$v[$s] = true;
			}
		}
		return array_keys($v);
	}

	private static function lower(string $s): string {
		return mb_strtolower(mb_scrub($s, 'UTF-8'));
	}

	private function add_name(array &$out, string $name): void {
		foreach (self::variants($name) as $n) {
			$n = self::lower($n);
			if ($n !== '' && strlen($n) <= 255) {
				$out['name:' . $n] = ['name', $n];
			}
		}
	}

	private function add_path(array &$out, string $path): void {
		foreach (self::variants($path) as $p) {
			$p = preg_replace('~/{2,}~', '/', str_replace('\\', '/', $p)) ?? $p;
			$p = ltrim($p, '/');
			while (str_starts_with($p, './')) {
				$p = substr($p, 2);
			}
			if ($p === '' || str_contains($p, '../') || strlen($p) > 1024) {
				continue;
			}
			$out['path:' . $p] = ['path', $p];
		}
	}

	/**
	 * A full quoted attribute value (may contain unencoded spaces): path and name refs.
	 */
	private function add_attribute_value(array &$out, string $value): void {
		$value = trim($value);
		if ($value === '' || strlen($value) > 1024) {
			return;
		}
		foreach (array_merge([$value], self::variants($value)) as $v) {
			$v = preg_replace('~[?#].*$~s', '', $v) ?? $v;
			$v = str_replace('\\', '/', $v);
			if ($v === '') {
				continue;
			}
			$base = strtolower(pathinfo(basename($v), PATHINFO_EXTENSION));
			if (!isset($this->ext_set[$base])) {
				continue;
			}
			if ($this->marker_re !== '' && preg_match($this->marker_re, $v, $m)) {
				$this->add_path($out, $m[1]);
			} elseif (preg_match('~(?<![0-9])(\d{4}/\d{2}/.+)$~s', $v, $m)) {
				$this->add_path($out, $m[1]);
			}
			$name = self::lower(basename($v));
			if ($name !== '' && strlen($name) <= 255) {
				$out['name:' . $name] = ['name', $name];
			}
		}
	}

	/**
	 * Refs for relative urls inside a file stored in uploads (css url(), html src/href),
	 * resolved against the directory of that file (uploads-relative, no leading slash).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function extract_relative(string $content, string $dir): array {
		$out = [];
		if (!preg_match_all('~(?:url\(\s*["\']?|@import\s+["\']|\b(?:src|href|poster|data|data-src|xlink:href)\s*=\s*["\'])([^"\')>]{1,1000})~i', $content, $m)) {
			return [];
		}
		foreach ($m[1] as $url) {
			$url = trim($url);
			if ($url === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/|#)~i', $url)) {
				continue;
			}
			$url = preg_replace('~[?#].*$~s', '', $url) ?? $url;
			foreach (self::variants($url) as $u) {
				$parts = $dir === '' ? [] : explode('/', trim($dir, '/'));
				foreach (explode('/', $u) as $seg) {
					if ($seg === '' || $seg === '.') {
						continue;
					}
					if ($seg === '..') {
						if (!$parts) {
							continue 2;
						}
						array_pop($parts);
						continue;
					}
					$parts[] = $seg;
				}
				$path = implode('/', $parts);
				if ($path !== '' && strlen($path) <= 1024) {
					$out['path:' . $path] = ['path', $path];
				}
			}
		}
		return array_values($out);
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
