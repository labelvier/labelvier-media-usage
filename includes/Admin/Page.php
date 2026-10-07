<?php

namespace LabelvierMediaUsage\Admin;

use LabelvierMediaUsage\Deleter;
use LabelvierMediaUsage\FileRepository;
use LabelvierMediaUsage\ScanState;
use LabelvierMediaUsage\Scheduler;

/**
 * Media > Media Usage admin screen: summary, indexing status and file list.
 */
class Page {

	public const SLUG = 'lvmu';
	public const CAP  = 'manage_options';

	private static string $hook = '';

	/** Ordered phases and their human labels. */
	public static function phases(): array {
		return [
			'scan_files'      => __('Scanning files on disk', 'labelvier-media-usage'),
			'map_attachments' => __('Linking files to Media Library items', 'labelvier-media-usage'),
			'scan_refs'       => __('Searching the database for references', 'labelvier-media-usage'),
			'resolve_usage'   => __('Resolving usage', 'labelvier-media-usage'),
			'finalize'        => __('Finishing up', 'labelvier-media-usage'),
		];
	}

	public static function register(): void {
		add_action('admin_menu', [self::class, 'menu']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
		add_action('wp_ajax_lvmu_status', [self::class, 'ajax_status']);
		add_action('admin_post_lvmu_start_scan', [self::class, 'handle_start_scan']);
		add_filter('set_screen_option_lvmu_per_page', static fn($keep, $option, $value) => max(1, min(500, (int) $value)), 10, 3);
	}

	public static function url(array $args = []): string {
		return add_query_arg($args, admin_url('upload.php?page=' . self::SLUG));
	}

	public static function menu(): void {
		self::$hook = (string) add_submenu_page(
			'upload.php',
			__('Media Usage', 'labelvier-media-usage'),
			__('Media Usage', 'labelvier-media-usage'),
			self::CAP,
			self::SLUG,
			[self::class, 'render']
		);
		add_action('load-' . self::$hook, [self::class, 'on_load']);
	}

	public static function enqueue(string $hook): void {
		if ($hook !== self::$hook) {
			return;
		}
		wp_enqueue_style('lvmu-admin', LVMU_URL . 'assets/admin.css', [], LVMU_VERSION);
		wp_enqueue_script('lvmu-admin', LVMU_URL . 'assets/admin.js', [], LVMU_VERSION, true);
		wp_localize_script('lvmu-admin', 'lvmuAdmin', [
			'ajaxUrl'  => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('lvmu_status'),
			'running'  => ScanState::is_running(),
			'interval' => 3000,
			'i18n'     => [
				'confirmBulk'    => __('Permanently delete %1$s files (%2$s)? This cannot be undone.', 'labelvier-media-usage'),
				'confirmBulkAll' => __('Permanently delete ALL %1$s matching files (%2$s)? This cannot be undone.', 'labelvier-media-usage'),
				'confirmUsed'    => __('Files marked as used are included. Deleting them may break your site. Continue?', 'labelvier-media-usage'),
				'confirmOne'     => __('Permanently delete this file? This cannot be undone.', 'labelvier-media-usage'),
				'nothing'        => __('Select at least one file first.', 'labelvier-media-usage'),
				'pageSelected'   => __('%s files on this page are selected.', 'labelvier-media-usage'),
				'allSelected'    => __('All %s matching files are selected.', 'labelvier-media-usage'),
				'selectAll'      => __('Select all %s matching files', 'labelvier-media-usage'),
				'clear'          => __('Clear selection', 'labelvier-media-usage'),
				'done'           => __('Index finished.', 'labelvier-media-usage'),
			],
		]);
	}

	/**
	 * Runs before output on the screen: screen options and delete handlers.
	 */
	public static function on_load(): void {
		add_screen_option('per_page', ['label' => __('Files per page', 'labelvier-media-usage'), 'default' => 50, 'option' => 'lvmu_per_page']);

		if (!current_user_can(self::CAP)) {
			return;
		}

		// Single delete.
		if (isset($_GET['lvmu_action']) && $_GET['lvmu_action'] === 'delete' && isset($_GET['file'])) {
			$id = absint($_GET['file']);
			check_admin_referer('lvmu_delete_' . $id);
			self::run_delete([$id], false);
		}

		// Bulk delete.
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
			$action = isset($_POST['action']) && $_POST['action'] !== '-1' ? sanitize_key($_POST['action']) : (isset($_POST['action2']) ? sanitize_key($_POST['action2']) : '');
			if ($action === 'lvmu_delete') {
				check_admin_referer('bulk-lvmu-files');
				$allow_used = !empty($_POST['lvmu_allow_used']);
				if (!empty($_POST['lvmu_select_all'])) {
					$ids = (new FileRepository())->ids(self::filters_from(wp_unslash($_POST)));
				} else {
					$ids = isset($_POST['file']) ? array_map('absint', (array) wp_unslash($_POST['file'])) : [];
				}
				self::run_delete($ids, $allow_used);
			}
		}
	}

	private static function run_delete(array $ids, bool $allow_used): void {
		$result = (new Deleter())->delete($ids, $allow_used);
		set_transient('lvmu_delete_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
		wp_safe_redirect(self::url(['lvmu_deleted' => 1]));
		exit;
	}

	/**
	 * Sanitised filters from a request array (GET or POST).
	 */
	public static function filters_from(array $src): array {
		$f = [];
		$t = isset($src['file_type']) ? sanitize_key($src['file_type']) : '';
		if (in_array($t, FileRepository::TYPES, true)) {
			$f['file_type'] = $t;
		}
		$r = isset($src['file_role']) ? sanitize_key($src['file_role']) : '';
		if (in_array($r, FileRepository::ROLES, true)) {
			$f['file_role'] = $r;
		}
		$u = isset($src['usage_status']) ? sanitize_key($src['usage_status']) : '';
		if (in_array($u, FileRepository::USAGES, true)) {
			$f['usage_status'] = $u;
		}
		if (!empty($src['orphaned'])) {
			$f['orphaned'] = true;
		}
		$m = isset($src['min_size']) ? absint($src['min_size']) : 0;
		if (in_array($m, self::min_sizes(), true) && $m > 0) {
			$f['min_size'] = $m;
		}
		$s = isset($src['s']) ? trim(sanitize_text_field($src['s'])) : '';
		if ($s !== '') {
			$f['search'] = $s;
		}
		return $f;
	}

	public static function min_sizes(): array {
		return [0, 100 * KB_IN_BYTES, MB_IN_BYTES, 5 * MB_IN_BYTES, 25 * MB_IN_BYTES];
	}

	/** Query-string form of filters (for links and hidden inputs). */
	public static function filter_args(array $f): array {
		$args = [];
		foreach (['file_type', 'file_role', 'usage_status', 'min_size'] as $k) {
			if (!empty($f[$k])) {
				$args[$k] = $f[$k];
			}
		}
		if (!empty($f['orphaned'])) {
			$args['orphaned'] = 1;
		}
		if (!empty($f['search'])) {
			$args['s'] = $f['search'];
		}
		return $args;
	}

	/* ------------------------------------------------------------------ actions */

	public static function handle_start_scan(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You are not allowed to do this.', 'labelvier-media-usage'), 403);
		}
		check_admin_referer('lvmu_start_scan');
		$restart = !empty($_POST['restart']);

		$started = false;
		if (class_exists('\LabelvierMediaUsage\Scheduler') && method_exists('\LabelvierMediaUsage\Scheduler', 'start_scan')) {
			$started = (bool) \LabelvierMediaUsage\Scheduler::start_scan($restart);
		}
		self::kick_runner();

		wp_safe_redirect(self::url(['lvmu_started' => $started ? 1 : 0]));
		exit;
	}

	/** Ask Action Scheduler to process its queue right away (async request). */
	private static function kick_runner(): void {
		if (class_exists('ActionScheduler') && method_exists('ActionScheduler', 'runner')) {
			$runner = \ActionScheduler::runner();
			if ($runner && method_exists($runner, 'maybe_dispatch_async_request')) {
				$runner->maybe_dispatch_async_request();
			}
		}
	}

	public static function ajax_status(): void {
		check_ajax_referer('lvmu_status', 'nonce');
		if (!current_user_can(self::CAP)) {
			wp_send_json_error(null, 403);
		}
		$state = ScanState::get();
		if ($state['status'] === 'running' && method_exists(Scheduler::class, 'run_pending_inline')) {
			Scheduler::run_pending_inline();
			$state = ScanState::get();
		}
		wp_send_json_success(self::status_payload($state));
	}

	public static function status_payload(array $state): array {
		$phases = self::phases();
		$keys   = array_keys($phases);
		$idx    = array_search($state['phase'], $keys, true);
		$percent = 0;
		$detail  = [];

		foreach ((array) $state['progress'] as $k => $v) {
			if (is_scalar($v) && $k !== 'phase_started' && $v !== '') {
				$detail[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . (is_numeric($v) ? number_format_i18n((float) $v) : $v);
			}
		}

		if ($state['status'] === 'done') {
			$percent = 100;
		} elseif ($state['status'] === 'running' && $idx !== false) {
			$p    = (array) $state['progress'];
			$frac = 0.0;
			// Done/total counter pairs written by each phase.
			$pairs = [
				'map_attachments' => ['attachments_mapped', 'attachments_total'],
				'scan_refs'       => ['refs_tables_done', 'refs_tables_total'],
				'resolve_usage'   => ['resolve_done', 'resolve_total'],
			];
			[$done_key, $total_key] = $pairs[$state['phase']] ?? ['done', 'total'];
			if (isset($p[$done_key], $p[$total_key]) && is_numeric($p[$done_key]) && is_numeric($p[$total_key]) && $p[$total_key] > 0) {
				$frac = min(1, $p[$done_key] / $p[$total_key]);
			}
			$percent = (int) floor((($idx + $frac) / count($keys)) * 100);
		}

		$out = [
			'status'      => $state['status'],
			'phase'       => $state['phase'],
			'phase_label' => $phases[$state['phase']] ?? '',
			'step'        => $idx === false ? 0 : $idx + 1,
			'steps'       => count($keys),
			'percent'     => $percent,
			'detail'      => implode(' · ', $detail),
			'error'       => (string) $state['error'],
		];
		$out['text'] = self::progress_text($out);
		return $out;
	}

	/* ------------------------------------------------------------------ render */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You are not allowed to access this page.', 'labelvier-media-usage'), 403);
		}
		$repo    = new FileRepository();
		$state   = ScanState::get();
		$summary = $repo->summary();
		$filters = self::filters_from(wp_unslash($_GET));

		echo '<div class="wrap lvmu-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__('Media Usage', 'labelvier-media-usage') . '</h1><hr class="wp-header-end">';

		self::render_result_notice();
		self::render_status($state, $summary);
		if ($summary['total']['count'] > 0) {
			self::render_summary($summary);
		}

		$table = new FilesListTable($filters);
		$table->prepare_items();

		echo '<h2 class="screen-reader-text">' . esc_html__('Files', 'labelvier-media-usage') . '</h2>';
		$table->views();
		self::render_filters($filters, $table);

		echo '<form method="post" id="lvmu-bulk-form" action="' . esc_url(self::url()) . '">';
		foreach (self::filter_args($filters) as $k => $v) {
			echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
		}
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	private static function render_result_notice(): void {
		if (isset($_GET['lvmu_started'])) {
			if ($_GET['lvmu_started'] === '1') {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Indexing started. This page updates automatically.', 'labelvier-media-usage') . '</p></div>';
			} else {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Could not start indexing (already running or the scheduler is unavailable).', 'labelvier-media-usage') . '</p></div>';
			}
		}

		if (empty($_GET['lvmu_deleted'])) {
			return;
		}
		$key    = 'lvmu_delete_result_' . get_current_user_id();
		$result = get_transient($key);
		delete_transient($key);
		if (!is_array($result)) {
			return;
		}
		$class = $result['errors'] ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr($class) . ' is-dismissible lvmu-result"><p><strong>';
		/* translators: 1: number of files, 2: freed size */
		echo esc_html(sprintf(__('Deleted %1$s files, freed %2$s.', 'labelvier-media-usage'), number_format_i18n($result['deleted']), size_format((int) $result['freed'])));
		echo '</strong>';
		if (!empty($result['remaining'])) {
			/* translators: %s: number of files */
			echo ' ' . esc_html(sprintf(__('%s more files were not processed in this request; repeat the action to continue.', 'labelvier-media-usage'), number_format_i18n($result['remaining'])));
		}
		echo '</p>';
		foreach (['skipped' => __('Skipped (%s)', 'labelvier-media-usage'), 'errors' => __('Errors (%s)', 'labelvier-media-usage')] as $k => $label) {
			if (empty($result[$k])) {
				continue;
			}
			echo '<details class="lvmu-details"><summary>' . esc_html(sprintf($label, number_format_i18n(count($result[$k])))) . '</summary><ul>';
			foreach (array_slice($result[$k], 0, 200) as $item) {
				echo '<li><code>' . esc_html($item['path']) . '</code> &mdash; ' . esc_html($item['reason']) . '</li>';
			}
			if (count($result[$k]) > 200) {
				echo '<li>&hellip;</li>';
			}
			echo '</ul></details>';
		}
		echo '</div>';
	}

	private static function render_status(array $state, array $summary): void {
		$running  = $state['status'] === 'running';
		$summ_opt = get_option('lvmu_summary');
		$last     = $state['finished_at'] ?: (is_array($summ_opt) ? ($summ_opt['generated_at'] ?? 0) : 0);
		if (is_string($last) && !is_numeric($last)) {
			$last = strtotime($last . ' UTC');
		}
		$never = !$last && $summary['total']['count'] === 0;
		$payload = self::status_payload($state);

		if ($never && !$running) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__('The uploads folder has never been indexed. Click "Index now" to build the file index.', 'labelvier-media-usage') . '</p></div>';
		}
		if ($state['status'] === 'failed') {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('The last index run failed.', 'labelvier-media-usage') . '</strong> ' . esc_html($state['error']) . '</p></div>';
		}

		echo '<div class="lvmu-box lvmu-status">';
		echo '<div class="lvmu-status__info">';
		echo '<p class="lvmu-status__last"><span class="dashicons dashicons-update" aria-hidden="true"></span> ';
		if ($last) {
			/* translators: 1: relative time, 2: exact time */
			echo esc_html(sprintf(__('Last indexed %1$s ago', 'labelvier-media-usage'), human_time_diff((int) $last)));
			echo ' <span class="description">(' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last)) . ')</span>';
		} else {
			echo esc_html__('Never indexed', 'labelvier-media-usage');
		}
		echo '</p>';

		echo '<div id="lvmu-progress" class="lvmu-progress" ' . ($running ? '' : 'hidden') . '>';
		echo '<p id="lvmu-progress-text" class="lvmu-progress__text" role="status" aria-live="polite">' . esc_html(self::progress_text($payload)) . '</p>';
		echo '<div class="lvmu-bar" role="progressbar" aria-label="' . esc_attr__('Indexing progress', 'labelvier-media-usage') . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $payload['percent'] . '"><span style="width:' . (int) $payload['percent'] . '%"></span></div>';
		echo '</div>';
		echo '<p class="description">' . esc_html__('Indexes automatically every night at 03:00 via Action Scheduler.', 'labelvier-media-usage') . '</p>';
		echo '</div>';

		echo '<form class="lvmu-status__actions" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="lvmu_start_scan">';
		wp_nonce_field('lvmu_start_scan');
		if ($running) {
			echo '<input type="hidden" name="restart" value="1">';
			echo '<button type="submit" class="button" data-lvmu-restart>' . esc_html__('Restart index', 'labelvier-media-usage') . '</button>';
		} else {
			echo '<button type="submit" class="button button-primary">' . esc_html__('Index now', 'labelvier-media-usage') . '</button>';
		}
		echo '</form>';
		echo '</div>';
	}

	private static function progress_text(array $p): string {
		$text = $p['phase_label'] ? sprintf(
			/* translators: 1: step, 2: total steps, 3: phase label */
			__('Step %1$d of %2$d: %3$s', 'labelvier-media-usage'),
			$p['step'],
			$p['steps'],
			$p['phase_label']
		) : __('Starting…', 'labelvier-media-usage');
		if ($p['detail']) {
			$text .= ' (' . $p['detail'] . ')';
		}
		return $text . ' - ' . $p['percent'] . '%';
	}

	private static function card(string $class, string $title, array $stat, array $args, array $sub = []): void {
		echo '<div class="lvmu-card ' . esc_attr($class) . '">';
		echo '<h3 class="lvmu-card__title"><a href="' . esc_url(self::url($args)) . '">' . esc_html($title) . '</a></h3>';
		echo '<p class="lvmu-card__num">' . esc_html(number_format_i18n($stat['count'])) . ' <span>' . esc_html__('files', 'labelvier-media-usage') . '</span></p>';
		echo '<p class="lvmu-card__size">' . esc_html(size_format($stat['size'])) . '</p>';
		if ($sub) {
			echo '<ul class="lvmu-card__sub">';
			foreach ($sub as $s) {
				echo '<li><a href="' . esc_url(self::url($s['args'])) . '">' . esc_html($s['label']) . '</a> <span>' . esc_html(number_format_i18n($s['count'])) . ' &middot; ' . esc_html(size_format($s['size'])) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private static function render_summary(array $s): void {
		$role = static fn(string $r): array => $s['by_role'][$r] ?? ['count' => 0, 'size' => 0];
		$lib  = ['count' => 0, 'size' => 0];
		$sub  = [];
		$role_labels = [
			'main'     => __('Main files', 'labelvier-media-usage'),
			'original' => __('Originals (pre-scaled)', 'labelvier-media-usage'),
			'subsize'  => __('Generated sizes', 'labelvier-media-usage'),
			'webp'     => __('WebP copies', 'labelvier-media-usage'),
		];
		foreach ($role_labels as $r => $label) {
			$st            = $role($r);
			$lib['count'] += $st['count'];
			$lib['size']  += $st['size'];
			$sub[]         = ['label' => $label, 'args' => ['file_role' => $r], 'count' => $st['count'], 'size' => $st['size']];
		}
		$unused = $s['by_usage']['unused'] ?? ['count' => 0, 'size' => 0];

		echo '<div class="lvmu-cards">';
		self::card('lvmu-card--total', __('Total on disk', 'labelvier-media-usage'), $s['total'], []);
		self::card('', __('Media Library files', 'labelvier-media-usage'), $lib, ['file_role' => 'main'], $sub);
		self::card('', __('Orphaned files', 'labelvier-media-usage'), $role('orphan'), ['orphaned' => 1]);
		self::card('', __('Unused (all)', 'labelvier-media-usage'), $unused, ['usage_status' => 'unused']);
		self::card('lvmu-card--reclaim', __('Orphaned & unused', 'labelvier-media-usage'), $s['orphaned_unused'], ['orphaned' => 1, 'usage_status' => 'unused']);
		echo '</div>';

		// Size share by type.
		$types = $s['by_type'];
		uasort($types, static fn($a, $b) => $b['size'] <=> $a['size']);
		$total = max(1, $s['total']['size']);
		$labels = self::type_labels();

		echo '<div class="lvmu-box lvmu-types"><h3>' . esc_html__('Size by file type', 'labelvier-media-usage') . '</h3>';
		echo '<div class="lvmu-stack" role="img" aria-label="' . esc_attr__('Size share by file type', 'labelvier-media-usage') . '">';
		foreach ($types as $type => $st) {
			$pct = $st['size'] / $total * 100;
			if ($pct <= 0) {
				continue;
			}
			echo '<span class="lvmu-t-' . esc_attr($type) . '" style="width:' . esc_attr(number_format($pct, 2, '.', '')) . '%" title="' . esc_attr(($labels[$type] ?? $type) . ': ' . size_format($st['size'])) . '"></span>';
		}
		echo '</div><ul class="lvmu-legend">';
		foreach ($types as $type => $st) {
			echo '<li><span class="lvmu-swatch lvmu-t-' . esc_attr($type) . '" aria-hidden="true"></span><a href="' . esc_url(self::url(['file_type' => $type])) . '">' . esc_html($labels[$type] ?? $type) . '</a> ';
			echo '<span class="description">' . esc_html(number_format_i18n($st['count'])) . ' &middot; ' . esc_html(size_format($st['size'])) . ' &middot; ' . esc_html(number_format_i18n($st['size'] / $total * 100, 1)) . '%</span></li>';
		}
		echo '</ul></div>';
	}

	public static function type_labels(): array {
		return [
			'image'    => __('Images', 'labelvier-media-usage'),
			'video'    => __('Video', 'labelvier-media-usage'),
			'audio'    => __('Audio', 'labelvier-media-usage'),
			'document' => __('Documents', 'labelvier-media-usage'),
			'archive'  => __('Archives', 'labelvier-media-usage'),
			'database' => __('Databases', 'labelvier-media-usage'),
			'log'      => __('Logs', 'labelvier-media-usage'),
			'code'     => __('Code', 'labelvier-media-usage'),
			'other'    => __('Other', 'labelvier-media-usage'),
		];
	}

	public static function role_labels(): array {
		return [
			'main'     => __('Main', 'labelvier-media-usage'),
			'original' => __('Original', 'labelvier-media-usage'),
			'subsize'  => __('Size', 'labelvier-media-usage'),
			'webp'     => __('WebP', 'labelvier-media-usage'),
			'orphan'   => __('Orphan', 'labelvier-media-usage'),
		];
	}

	private static function render_filters(array $f, FilesListTable $table): void {
		echo '<form method="get" class="lvmu-filters" action="' . esc_url(admin_url('upload.php')) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
		foreach (['orderby', 'order'] as $k) {
			if (!empty($_GET[$k])) {
				echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(sanitize_key(wp_unslash($_GET[$k]))) . '">';
			}
		}

		self::select('lvmu-f-type', 'file_type', __('Type', 'labelvier-media-usage'), __('All types', 'labelvier-media-usage'), self::type_labels(), $f['file_type'] ?? '');
		self::select('lvmu-f-role', 'file_role', __('Role', 'labelvier-media-usage'), __('All roles', 'labelvier-media-usage'), self::role_labels(), $f['file_role'] ?? '');
		self::select('lvmu-f-usage', 'usage_status', __('Usage', 'labelvier-media-usage'), __('Any usage', 'labelvier-media-usage'), [
			'used'    => __('Used', 'labelvier-media-usage'),
			'unused'  => __('Unused', 'labelvier-media-usage'),
			'unknown' => __('Unknown', 'labelvier-media-usage'),
		], $f['usage_status'] ?? '');

		$sizes = [];
		foreach (self::min_sizes() as $b) {
			if ($b > 0) {
				/* translators: %s: size like 1 MB */
				$sizes[(string) $b] = sprintf(__('%s or larger', 'labelvier-media-usage'), size_format($b));
			}
		}
		self::select('lvmu-f-size', 'min_size', __('Minimum size', 'labelvier-media-usage'), __('Any size', 'labelvier-media-usage'), $sizes, isset($f['min_size']) ? (string) $f['min_size'] : '');

		echo '<label class="lvmu-check"><input type="checkbox" name="orphaned" value="1" ' . checked(!empty($f['orphaned']), true, false) . '> ' . esc_html__('Orphaned only', 'labelvier-media-usage') . '</label>';
		echo '<label class="screen-reader-text" for="lvmu-f-search">' . esc_html__('Search path', 'labelvier-media-usage') . '</label>';
		echo '<input type="search" id="lvmu-f-search" name="s" value="' . esc_attr($f['search'] ?? '') . '" placeholder="' . esc_attr__('Search path…', 'labelvier-media-usage') . '">';
		echo '<button type="submit" class="button">' . esc_html__('Filter', 'labelvier-media-usage') . '</button>';
		if ($f) {
			echo ' <a class="button-link" href="' . esc_url(self::url()) . '">' . esc_html__('Reset', 'labelvier-media-usage') . '</a>';
		}
		echo '</form>';
	}

	private static function select(string $id, string $name, string $label, string $any, array $options, string $current): void {
		echo '<div class="lvmu-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
		echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"><option value="">' . esc_html($any) . '</option>';
		foreach ($options as $value => $text) {
			echo '<option value="' . esc_attr((string) $value) . '" ' . selected($current, (string) $value, false) . '>' . esc_html($text) . '</option>';
		}
		echo '</select></div>';
	}
}
