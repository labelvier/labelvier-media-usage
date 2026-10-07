<?php

namespace LabelvierMediaUsage\Admin;

use LabelvierMediaUsage\FileRepository;

if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table of indexed files.
 */
class FilesListTable extends \WP_List_Table {

	private array $filters;
	private FileRepository $repo;
	private int $matching_size = 0;
	private string $baseurl;

	public function __construct(array $filters) {
		parent::__construct(['singular' => 'file', 'plural' => 'lvmu-files', 'ajax' => false]);
		$this->filters = $filters;
		$this->repo    = new FileRepository();
		$this->baseurl = untrailingslashit(wp_get_upload_dir()['baseurl']);
	}

	public function get_columns(): array {
		return [
			'cb'        => '<input type="checkbox">',
			'thumb'     => '<span class="screen-reader-text">' . esc_html__('Preview', 'labelvier-media-usage') . '</span>',
			'file'      => __('File', 'labelvier-media-usage'),
			'file_type' => __('Type', 'labelvier-media-usage'),
			'role'      => __('Role', 'labelvier-media-usage'),
			'size'      => __('Size', 'labelvier-media-usage'),
			'media'     => __('Media item', 'labelvier-media-usage'),
			'usage'     => __('Usage', 'labelvier-media-usage'),
			'mtime'     => __('Modified', 'labelvier-media-usage'),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'file'  => ['path', false],
			'size'  => ['size', true],
			'mtime' => ['mtime', false],
			'usage' => ['usage_count', false],
		];
	}

	protected function get_bulk_actions(): array {
		return ['lvmu_delete' => __('Delete permanently', 'labelvier-media-usage')];
	}

	protected function get_views(): array {
		$defs = [
			'all'             => [__('All', 'labelvier-media-usage'), []],
			'unused'          => [__('Unused', 'labelvier-media-usage'), ['usage_status' => 'unused']],
			'orphaned'        => [__('Orphaned', 'labelvier-media-usage'), ['orphaned' => 1]],
			'orphaned_unused' => [__('Orphaned & unused', 'labelvier-media-usage'), ['orphaned' => 1, 'usage_status' => 'unused']],
			'originals'       => [__('Originals (pre-scaled)', 'labelvier-media-usage'), ['file_role' => 'original']],
			'webp'            => [__('WebP copies', 'labelvier-media-usage'), ['file_role' => 'webp']],
		];
		$current = Page::filter_args($this->filters);
		$views   = [];
		foreach ($defs as $key => [$label, $args]) {
			$q     = $this->repo->query(array_merge($args, ['per_page' => 1]) + ['orphaned' => !empty($args['orphaned'])]);
			$class = ($current == $args && empty($_GET['s'])) ? ' class="current" aria-current="page"' : '';
			$views[$key] = '<a href="' . esc_url(Page::url($args)) . '"' . $class . '>' . esc_html($label)
				. ' <span class="count">(' . esc_html(number_format_i18n($q['total'])) . ')</span></a>';
		}
		return $views;
	}

	public function prepare_items(): void {
		$per_page = (int) get_user_option('lvmu_per_page');
		if ($per_page < 1) {
			$per_page = 50;
		}
		$orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'size';
		$order   = isset($_GET['order']) && strtolower(sanitize_key(wp_unslash($_GET['order']))) === 'asc' ? 'asc' : 'desc';
		if (!isset($_GET['orderby'])) {
			$order = 'desc';
		}

		$result = $this->repo->query($this->filters + [
			'orderby'  => $orderby,
			'order'    => $order,
			'page'     => $this->get_pagenum(),
			'per_page' => $per_page,
		]);

		$this->items         = $result['items'];
		$this->matching_size = $result['total_size'];
		$this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'file'];
		$this->set_pagination_args(['total_items' => $result['total'], 'per_page' => $per_page]);
	}

	public function no_items(): void {
		esc_html_e('No files found.', 'labelvier-media-usage');
	}

	/** Gmail-style "select all matching" and the used-files override. */
	protected function extra_tablenav($which): void {
		if ($which !== 'top') {
			return;
		}
		$total = (int) $this->_pagination_args['total_items'];
		echo '<div class="alignleft actions lvmu-bulk-extra">';
		echo '<input type="hidden" name="lvmu_select_all" id="lvmu-select-all" value="0">';
		echo '<label class="lvmu-check"><input type="checkbox" name="lvmu_allow_used" id="lvmu-allow-used" value="1"> ' . esc_html__('Also delete files marked as used', 'labelvier-media-usage') . '</label>';
		echo '</div>';
		echo '<div class="lvmu-allow-warning notice notice-warning inline" id="lvmu-used-warning" hidden><p>' . esc_html__('Warning: files marked as used are referenced in your content. Deleting them will break images and links.', 'labelvier-media-usage') . '</p></div>';
		echo '<div class="lvmu-select-all-bar" id="lvmu-select-all-bar" hidden role="status" aria-live="polite"'
			. ' data-total="' . esc_attr((string) $total) . '"'
			. ' data-total-h="' . esc_attr(number_format_i18n($total)) . '"'
			. ' data-size="' . esc_attr((string) $this->matching_size) . '"'
			. ' data-size-h="' . esc_attr(size_format($this->matching_size)) . '"></div>';
	}

	protected function column_cb($item): void {
		printf(
			'<label class="screen-reader-text" for="lvmu-cb-%1$d">%3$s</label><input type="checkbox" id="lvmu-cb-%1$d" name="file[]" value="%1$d" data-size="%2$d">',
			(int) $item->id,
			(int) $item->size,
			esc_html($item->filename)
		);
	}

	private function file_url(object $item): string {
		$parts = array_map('rawurlencode', explode('/', $item->path));
		return $this->baseurl . '/' . implode('/', $parts);
	}

	protected function column_thumb($item): void {
		$previewable = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico'];
		if ($item->file_type === 'image' && in_array($item->extension, $previewable, true)) {
			echo '<img class="lvmu-thumb" src="' . esc_url($this->file_url($item)) . '" width="40" height="40" loading="lazy" alt="">';
			return;
		}
		$icons = [
			'image' => 'format-image', 'video' => 'video-alt3', 'audio' => 'format-audio', 'document' => 'media-document',
			'archive' => 'archive', 'database' => 'database', 'log' => 'text-page', 'code' => 'editor-code', 'other' => 'media-default',
		];
		echo '<span class="lvmu-thumb lvmu-thumb--icon dashicons dashicons-' . esc_attr($icons[$item->file_type] ?? 'media-default') . '" aria-hidden="true"></span>';
	}

	protected function column_file($item): void {
		$dir = dirname($item->path);
		echo '<strong class="lvmu-filename">' . esc_html($item->filename) . '</strong>';
		echo '<br><span class="lvmu-path">' . esc_html($dir === '.' ? '/' : $dir . '/') . '</span>';

		$actions = [
			'view' => '<a href="' . esc_url($this->file_url($item)) . '" target="_blank" rel="noopener">' . esc_html__('View', 'labelvier-media-usage') . '</a>',
		];
		if ($item->attachment_id) {
			$edit = get_edit_post_link((int) $item->attachment_id);
			if ($edit) {
				$actions['edit'] = '<a href="' . esc_url($edit) . '">' . esc_html__('Edit attachment', 'labelvier-media-usage') . '</a>';
			}
		}
		$del = wp_nonce_url(Page::url(['lvmu_action' => 'delete', 'file' => (int) $item->id]), 'lvmu_delete_' . (int) $item->id);
		$actions['delete'] = '<a class="submitdelete lvmu-confirm" href="' . esc_url($del) . '">' . esc_html__('Delete', 'labelvier-media-usage') . '</a>';
		echo $this->row_actions($actions); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	protected function column_file_type($item): void {
		$labels = Page::type_labels();
		echo esc_html($labels[$item->file_type] ?? $item->file_type);
		echo '<br><span class="description">.' . esc_html($item->extension) . '</span>';
	}

	protected function column_role($item): void {
		$labels = Page::role_labels();
		echo '<span class="lvmu-badge lvmu-badge--' . esc_attr($item->file_role) . '">' . esc_html($labels[$item->file_role] ?? $item->file_role) . '</span>';
	}

	protected function column_size($item): void {
		echo esc_html(size_format((int) $item->size, 1));
	}

	protected function column_media($item): void {
		if (!$item->attachment_id) {
			echo '<span aria-label="' . esc_attr__('Not in Media Library', 'labelvier-media-usage') . '">&mdash;</span>';
			return;
		}
		$title = get_the_title((int) $item->attachment_id);
		$edit  = get_edit_post_link((int) $item->attachment_id);
		$title = $title !== '' ? $title : sprintf(__('Attachment #%d', 'labelvier-media-usage'), (int) $item->attachment_id);
		if ($edit) {
			echo '<a href="' . esc_url($edit) . '">' . esc_html($title) . '</a>';
		} else {
			echo esc_html($title);
		}
	}

	protected function column_usage($item): void {
		$labels = [
			'used'    => __('Used', 'labelvier-media-usage'),
			'unused'  => __('Unused', 'labelvier-media-usage'),
			'unknown' => __('Unknown', 'labelvier-media-usage'),
		];
		$status = $item->usage_status;
		echo '<span class="lvmu-badge lvmu-badge--' . esc_attr($status) . '">' . esc_html($labels[$status] ?? $status) . '</span>';
		if ($status !== 'used') {
			return;
		}
		echo ' <span class="description">' . esc_html(number_format_i18n((int) $item->usage_count)) . '&times;</span>';

		if (class_exists('\LabelvierMediaUsage\Usage\UsageLookup') && method_exists('\LabelvierMediaUsage\Usage\UsageLookup', 'sources_for')) {
			$sources = \LabelvierMediaUsage\Usage\UsageLookup::sources_for($item);
			if ($sources) {
				echo '<details class="lvmu-details"><summary>' . esc_html__('Where used', 'labelvier-media-usage') . '</summary><ul>';
				foreach ($sources as $src) {
					echo '<li>';
					if (!empty($src['url'])) {
						echo '<a href="' . esc_url($src['url']) . '">' . esc_html($src['label']) . '</a>';
					} else {
						echo esc_html($src['label']);
					}
					echo '</li>';
				}
				echo '</ul></details>';
			}
		}
	}

	protected function column_mtime($item): void {
		$ts = (int) $item->mtime;
		echo '<span title="' . esc_attr(wp_date('Y-m-d H:i:s', $ts)) . '">' . esc_html(wp_date(get_option('date_format'), $ts)) . '</span>';
	}

	protected function column_default($item, $column_name): void {
		echo '';
	}
}
