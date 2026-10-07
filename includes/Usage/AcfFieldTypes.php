<?php

namespace LabelvierMediaUsage\Usage;

/**
 * Knows which ACF field keys hold attachment ids (image, file, gallery, ...).
 *
 * The map is built from the acf-field posts in the database (read as text, never
 * unserialized) and from every field group ACF knows about (local PHP / JSON groups
 * included). Unknown keys are resolved lazily with acf_get_field() when ACF is active.
 */
class AcfFieldTypes {

	/** Field types whose value is one or more attachment ids. */
	public const MEDIA_TYPES = ['image', 'file', 'gallery', 'image_crop', 'image_aspect_ratio_crop'];

	/** @var array<string, bool> field key => holds attachment ids */
	private array $map;

	public function __construct(array $map = []) {
		$this->map = $map;
	}

	/**
	 * Build the field key map once per scan.
	 *
	 * @return array<string, bool>
	 */
	public static function build(): array {
		global $wpdb;
		$map = [];

		$rows = $wpdb->get_results("SELECT post_name, post_content FROM {$wpdb->posts} WHERE post_type = 'acf-field'");
		foreach ($rows ?: [] as $row) {
			$map[$row->post_name] = self::settings_text_is_media((string) $row->post_content);
		}

		if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
			foreach (acf_get_field_groups() as $group) {
				$fields = acf_get_fields($group);
				self::collect(is_array($fields) ? $fields : [], $map);
			}
		}

		return $map;
	}

	public function is_media(string $key): bool {
		if (array_key_exists($key, $this->map)) {
			return $this->map[$key];
		}

		$result = false;
		if (function_exists('acf_get_field')) {
			$field = acf_get_field($key);
			if (is_array($field)) {
				$result = self::field_is_media($field);
			}
		}

		// Clone fields store composite keys: field_{clone}_field_{sub}.
		$pos = strrpos($key, '_field_');
		if (!$result && $pos !== false) {
			$result = $this->is_media(substr($key, $pos + 1));
		}

		return $this->map[$key] = $result;
	}

	public function map(): array {
		return $this->map;
	}

	public static function field_is_media(array $field): bool {
		$type = $field['type'] ?? '';
		if (in_array($type, self::MEDIA_TYPES, true)) {
			return true;
		}
		if (in_array($type, ['post_object', 'relationship'], true)) {
			return in_array('attachment', (array) ($field['post_type'] ?? []), true);
		}
		return false;
	}

	private static function collect(array $fields, array &$map): void {
		foreach ($fields as $field) {
			if (!is_array($field) || empty($field['key'])) {
				continue;
			}
			$map[$field['key']] = self::field_is_media($field);
			if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
				self::collect($field['sub_fields'], $map);
			}
			if (!empty($field['layouts']) && is_array($field['layouts'])) {
				foreach ($field['layouts'] as $layout) {
					if (!empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
						self::collect($layout['sub_fields'], $map);
					}
				}
			}
		}
	}

	/**
	 * Read the field type from serialized acf-field post_content without unserializing.
	 */
	private static function settings_text_is_media(string $content): bool {
		if (!preg_match('~s:4:"type";s:\d+:"([a-z0-9_]+)"~', $content, $m)) {
			return false;
		}
		if (in_array($m[1], self::MEDIA_TYPES, true)) {
			return true;
		}
		return in_array($m[1], ['post_object', 'relationship'], true) && str_contains($content, '"attachment"');
	}
}
