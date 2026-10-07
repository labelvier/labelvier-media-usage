<?php

namespace LabelvierMediaUsage;

use LabelvierMediaUsage\Indexer\PhaseInterface;

/**
 * Drives the index pipeline through Action Scheduler.
 */
class Scheduler {

	public const GROUP      = 'lvmu';
	public const LOCK       = 'lvmu_phase_lock';
	public const HOOK_START = 'lvmu_start_scan';
	public const HOOK_PHASE = 'lvmu_run_phase';
	private const STALE_AFTER = 6 * HOUR_IN_SECONDS;
	private const WATCHDOG_IDLE = 10 * MINUTE_IN_SECONDS;
	private const WATCHDOG_MAX  = 3;
	public const OPT_BATCH_FACTOR = 'lvmu_batch_factor';
	public const OPT_WATCHDOG     = 'lvmu_watchdog_attempts';

	/** @var bool Whether this request holds the phase lock. */
	private static bool $locked = false;

	public const PHASES = [
		'scan_files'      => Indexer\FileScanner::class,
		'map_attachments' => Indexer\AttachmentMapper::class,
		'scan_refs'       => Usage\RefScanner::class,
		'resolve_usage'   => Usage\UsageResolver::class,
		'finalize'        => Indexer\Finalizer::class,
	];

	/**
	 * Multisite is not supported: uploads/sites/N is indexed but the other sites' tables
	 * are not scanned, so all subsite files would show up as unused orphans.
	 */
	public static function is_supported(): bool {
		return !is_multisite();
	}

	public static function unsupported_message(): string {
		return 'Label Vier Media Usage does not support multisite installations.';
	}

	public static function register(): void {
		if (!self::is_supported()) {
			return;
		}
		add_action(self::HOOK_START, static function () {
			self::start_scan();
		});
		add_action(self::HOOK_PHASE, [self::class, 'run_phase'], 10, 2);
		add_action('init', [self::class, 'ensure_nightly'], 20);
		add_action('init', [self::class, 'watchdog'], 30);
	}

	/**
	 * Make sure the nightly 03:00 (site time) scan is scheduled. Action Scheduler
	 * evaluates cron expressions in UTC, so the expression is built from the UTC
	 * equivalent of 03:00 in the site timezone.
	 */
	public static function ensure_nightly(): void {
		if (!self::is_supported()) {
			return;
		}
		if (!function_exists('as_schedule_cron_action') || !function_exists('as_has_scheduled_action') || !did_action('action_scheduler_init')) {
			return;
		}
		if (!get_option('lvmu_needs_schedule') && as_has_scheduled_action(self::HOOK_START, null, self::GROUP)) {
			return;
		}

		$next = new \DateTimeImmutable('today 03:00', wp_timezone());
		if ($next->getTimestamp() <= time()) {
			$next = $next->modify('+1 day');
		}
		$utc = $next->setTimezone(new \DateTimeZone('UTC'));
		$cron = sprintf('%d %d * * *', (int) $utc->format('i'), (int) $utc->format('G'));

		as_unschedule_all_actions(self::HOOK_START, [], self::GROUP);
		as_schedule_cron_action($next->getTimestamp(), $cron, self::HOOK_START, [], self::GROUP);
		delete_option('lvmu_needs_schedule');
	}

	/**
	 * Begin a new run and queue the first phase.
	 */
	public static function start_scan(bool $force = false): bool {
		if (!self::is_supported() || !self::begin($force)) {
			return false;
		}
		self::cancel_pending_phases();
		self::enqueue(self::first_phase(), ScanState::get()['scan_id']);
		return true;
	}

	private static function cancel_pending_phases(): void {
		if (!class_exists('ActionScheduler') || !\ActionScheduler::is_initialized()) {
			return;
		}
		$store = \ActionScheduler::store();
		$ids   = $store->query_actions([
			'hook'     => self::HOOK_PHASE,
			'group'    => self::GROUP,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		]);
		foreach ($ids as $id) {
			$store->cancel_action($id);
		}
	}

	public static function run_phase(string $phase, int $scan_id): void {
		$state = ScanState::get();
		if ($scan_id !== (int) $state['scan_id'] || $state['status'] !== 'running' || !isset(self::PHASES[$phase])) {
			return;
		}

		// Only one runner at a time (WP cron, async request and the admin poll can overlap).
		// A runner that loses the lock queues the same phase again, so the scan never stalls.
		if (!self::lock()) {
			self::requeue_later($phase, $scan_id);
			return;
		}

		try {
			// The state may have changed while waiting for the lock (restart, cancel).
			$state = ScanState::get();
			if ($scan_id !== (int) $state['scan_id'] || $state['status'] !== 'running') {
				return;
			}
			self::run_phase_locked($phase, $scan_id, $state);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Schedule the same phase again in 30 seconds unless an identical action is pending.
	 */
	private static function requeue_later(string $phase, int $scan_id): void {
		if (!function_exists('as_schedule_single_action')) {
			return;
		}
		$args = [$phase, $scan_id];
		if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK_PHASE, $args, self::GROUP)) {
			return;
		}
		as_schedule_single_action(time() + 30, self::HOOK_PHASE, $args, self::GROUP);
	}

	private static function run_phase_locked(string $phase, int $scan_id, array $state): void {
		$instance = self::instance($phase);
		if (!$instance) {
			self::skip($phase);
			self::advance($phase, $scan_id);
			return;
		}

		try {
			if (($state['progress']['phase_started'] ?? '') !== $phase) {
				ScanState::update(['phase' => $phase]);
				ScanState::progress(['phase_started' => $phase]);
				$instance->start($scan_id);
			}
			$done = $instance->run($scan_id, microtime(true) + 20);
		} catch (\Throwable $e) {
			self::fail($e);
			return;
		}

		if ($done) {
			self::advance($phase, $scan_id);
		} else {
			self::enqueue($phase, $scan_id);
		}
	}

	private static function lock_name(): string {
		global $wpdb;
		return substr('lvmu_phase_' . $wpdb->prefix, 0, 64);
	}

	/**
	 * Atomic, connection scoped lock (released automatically when the process dies).
	 * Falls back to an option based lock when GET_LOCK is unavailable.
	 */
	private static function lock(): bool {
		global $wpdb;
		$got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name()));
		if ($got !== null && $got !== '') {
			self::$locked = ((int) $got === 1);
			return self::$locked;
		}

		// Fallback: option lock with takeover after 10 minutes.
		if (add_option(self::LOCK, time(), '', false)) {
			self::$locked = true;
			return true;
		}
		if ((int) get_option(self::LOCK) < time() - 10 * MINUTE_IN_SECONDS) {
			update_option(self::LOCK, time(), false);
			self::$locked = true;
			return true;
		}
		return false;
	}

	private static function unlock(): void {
		global $wpdb;
		if (!self::$locked) {
			return;
		}
		self::$locked = false;
		$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
		delete_option(self::LOCK);
	}

	/**
	 * Batch size multiplier for phases that adapt their batch size (halves on every
	 * watchdog retry, reset when a new scan starts).
	 */
	public static function batch_factor(): float {
		$factor = (float) get_option(self::OPT_BATCH_FACTOR, 1.0);
		return $factor > 0 && $factor <= 1 ? $factor : 1.0;
	}

	/**
	 * Recover a scan whose phase died (fatal error, OOM, max_execution_time) and left no
	 * action queued. Staleness is measured from the last progress update.
	 */
	public static function watchdog(): void {
		if (!self::is_supported() || !function_exists('as_has_scheduled_action') || !class_exists('ActionScheduler') || !\ActionScheduler::is_initialized()) {
			return;
		}
		$state = ScanState::get();
		if ($state['status'] !== 'running') {
			return;
		}
		if (get_transient('lvmu_watchdog_throttle')) {
			return;
		}
		set_transient('lvmu_watchdog_throttle', 1, 5 * MINUTE_IN_SECONDS);

		$last = (int) ($state['last_progress_at'] ?: $state['started_at']);
		if (time() - $last <= self::WATCHDOG_IDLE) {
			return;
		}
		if (self::has_phase_action()) {
			return;
		}

		$phase    = (string) ($state['phase'] ?: self::first_phase());
		$attempts = (int) get_option(self::OPT_WATCHDOG, 0) + 1;
		if ($attempts > self::WATCHDOG_MAX) {
			ScanState::update(['status' => 'failed', 'error' => "Phase {$phase} crashed repeatedly", 'finished_at' => time()]);
			return;
		}
		update_option(self::OPT_WATCHDOG, $attempts, false);
		update_option(self::OPT_BATCH_FACTOR, max(0.05, self::batch_factor() / 2), false);
		ScanState::update(['last_progress_at' => time()]);
		self::enqueue($phase, (int) $state['scan_id']);
	}

	private static function has_phase_action(): bool {
		foreach ([\ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING] as $status) {
			$ids = \ActionScheduler::store()->query_actions([
				'hook'     => self::HOOK_PHASE,
				'group'    => self::GROUP,
				'status'   => $status,
				'per_page' => 1,
			]);
			if ($ids) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Process the next pending phase action in the current request. Used by the admin
	 * status poll, so a run also progresses on hosts where the Action Scheduler loopback
	 * request is blocked (and in local Docker, where WP_HOME's port does not exist inside
	 * the container).
	 */
	public static function run_pending_inline(): void {
		if (!self::is_supported()) {
			return;
		}
		self::watchdog();
		if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler')) {
			return;
		}
		$ids = as_get_scheduled_actions([
			'hook'     => 'lvmu_run_phase',
			'group'    => self::GROUP,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
			'orderby'  => 'date',
			'order'    => 'ASC',
		], 'ids');
		if ($ids) {
			\ActionScheduler::runner()->process_action((int) $ids[0], 'LVMU admin poll');
		}
	}

	/**
	 * Run the whole pipeline inline (WP-CLI --sync, tests).
	 *
	 * @param callable|null $log Receives a string message per step.
	 */
	public static function run_sync(?callable $log = null, bool $force = true): bool {
		$log ??= static function (string $message): void {
		};
		if (!self::is_supported()) {
			$log(self::unsupported_message());
			return false;
		}
		if (!self::begin($force)) {
			$log('A scan is already running.');
			return false;
		}
		$scan_id = (int) ScanState::get()['scan_id'];

		foreach (array_keys(self::PHASES) as $phase) {
			$instance = self::instance($phase);
			if (!$instance) {
				self::skip($phase);
				$log("Skipping {$phase}: class missing.");
				continue;
			}
			$log("Phase {$phase}");
			try {
				ScanState::update(['phase' => $phase]);
				ScanState::progress(['phase_started' => $phase]);
				$instance->start($scan_id);
				do {
					$done = $instance->run($scan_id, microtime(true) + 60);
					$log('  ' . self::format_progress(ScanState::get()['progress']));
				} while (!$done);
			} catch (\Throwable $e) {
				self::fail($e);
				$log('Failed: ' . $e->getMessage());
				return false;
			}
		}

		// Finalizer normally marks the scan done; cover the case where it is missing.
		if (ScanState::get()['status'] === 'running') {
			ScanState::update(['status' => 'done', 'phase' => '', 'finished_at' => time()]);
		}
		return true;
	}

	private static function begin(bool $force): bool {
		$state = ScanState::get();
		if ($state['status'] === 'running' && !$force && $state['started_at'] && time() - (int) $state['started_at'] < self::STALE_AFTER) {
			return false;
		}
		ScanState::update([
			'scan_id'     => (int) $state['scan_id'] + 1,
			'status'      => 'running',
			'phase'       => self::first_phase(),
			'started_at'  => time(),
			'finished_at' => null,
			'progress'    => [],
			'error'       => '',
			'last_progress_at' => time(),
		]);
		update_option(self::OPT_BATCH_FACTOR, 1.0, false);
		update_option(self::OPT_WATCHDOG, 0, false);
		return true;
	}

	private static function first_phase(): string {
		return (string) array_key_first(self::PHASES);
	}

	private static function instance(string $phase): ?PhaseInterface {
		$class = self::PHASES[$phase];
		return class_exists($class) ? new $class() : null;
	}

	private static function skip(string $phase): void {
		ScanState::progress(['skipped_' . $phase => 'class missing']);
	}

	private static function advance(string $phase, int $scan_id): void {
		$keys = array_keys(self::PHASES);
		$next = $keys[array_search($phase, $keys, true) + 1] ?? null;
		if ($next === null) {
			if (ScanState::get()['status'] === 'running') {
				ScanState::update(['status' => 'done', 'phase' => '', 'finished_at' => time()]);
			}
			return;
		}
		update_option(self::OPT_WATCHDOG, 0, false);
		ScanState::update(['phase' => $next, 'last_progress_at' => time()]);
		self::enqueue($next, $scan_id);
	}

	private static function enqueue(string $phase, int $scan_id): void {
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action(self::HOOK_PHASE, [$phase, $scan_id], self::GROUP);
		}
	}

	private static function fail(\Throwable $e): void {
		ScanState::update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => time()]);
	}

	private static function format_progress(array $progress): string {
		$parts = [];
		foreach ($progress as $key => $value) {
			$parts[] = "{$key}={$value}";
		}
		return implode(' ', $parts);
	}
}
