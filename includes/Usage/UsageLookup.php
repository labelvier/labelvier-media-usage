<?php

namespace LabelvierMediaUsage\Usage;

/**
 * Turns the usage_sources JSON of a lvmu_files row into readable items for the admin UI.
 * Labels are plain text: escape them on output.
 */
class UsageLookup {

	/**
	 * @param object $file lvmu_files row (needs usage_sources)
	 * @return array<int, array{label: string, url: string|null}>
	 */
	public static function sources_for(object $file): array {
		$sources = json_decode((string) ($file->usage_sources ?? ''), true);
		if (!is_array($sources)) {
			return [];
		}

		$items = [];
		foreach ($sources as $source) {
			if (is_array($source)) {
				$items[] = self::describe($source);
			}
		}
		return $items;
	}

	/**
	 * @param array{type?: string, table?: string, id?: string|int, field?: string} $source
	 * @return array{label: string, url: string|null}
	 */
	private static function describe(array $source): array {
		$type  = (string) ($source['type'] ?? '');
		$table = (string) ($source['table'] ?? '');
		$id    = (string) ($source['id'] ?? '');
		$field = (string) ($source['field'] ?? '');
		$num   = ctype_digit($id) ? (int) $id : 0;

		switch ($type) {
			case 'post':
			case 'postmeta':
				$post = $num ? get_post($num) : null;
				if (!$post) {
					return ['label' => sprintf(__('Post #%d', 'labelvier-media-usage'), $num) . self::field($field), 'url' => null];
				}
				$type_object = get_post_type_object($post->post_type);
				$type_label  = $type_object ? $type_object->labels->singular_name : $post->post_type;
				$title       = $post->post_title !== '' ? $post->post_title : '#' . $post->ID;
				$skip_field  = $type === 'post' && $field === 'post_content';
				return [
					'label' => $type_label . ': ' . $title . ($skip_field ? '' : self::field($field)),
					'url'   => get_edit_post_link($post->ID, 'raw') ?: null,
				];

			case 'term':
			case 'termmeta':
				$term = $num ? get_term($num) : null;
				if (!$term || is_wp_error($term)) {
					return ['label' => sprintf(__('Term #%d', 'labelvier-media-usage'), $num) . self::field($field), 'url' => null];
				}
				$link = get_edit_term_link($term);
				return [
					'label' => sprintf(__('Term: %1$s (%2$s)', 'labelvier-media-usage'), $term->name, $term->taxonomy) . self::field($field),
					'url'   => is_string($link) ? $link : null,
				];

			case 'user':
			case 'usermeta':
				$user = $num ? get_userdata($num) : false;
				return [
					'label' => ($user ? sprintf(__('User: %s', 'labelvier-media-usage'), $user->display_name) : sprintf(__('User #%d', 'labelvier-media-usage'), $num)) . self::field($field),
					'url'   => $user ? (get_edit_user_link($user->ID) ?: null) : null,
				];

			case 'comment':
			case 'commentmeta':
				$link = $num ? get_edit_comment_link($num) : null;
				return [
					'label' => sprintf(__('Comment #%d', 'labelvier-media-usage'), $num) . self::field($field),
					'url'   => is_string($link) && $link !== '' ? $link : null,
				];

			case 'option':
				return ['label' => sprintf(__('Option: %s', 'labelvier-media-usage'), $field), 'url' => null];

			case 'file':
				return ['label' => sprintf(__('Theme file: %1$s/%2$s', 'labelvier-media-usage'), $id, $field), 'url' => null];

			default:
				return [
					'label' => sprintf(__('Table %1$s #%2$s', 'labelvier-media-usage'), $table, $id) . self::field($field),
					'url'   => null,
				];
		}
	}

	private static function field(string $field): string {
		return $field !== '' ? ' (' . $field . ')' : '';
	}
}
