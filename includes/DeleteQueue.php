<?php

namespace LabelvierMediaUsage;

/**
 * Bulk deletions run in the background: a job is stored in an option and processed in
 * chunks by an Action Scheduler action. All safety checks stay in Deleter.
 */
class DeleteQueue {

	public const OPTION     = 'lvmu_delete_jobs';
	public const OPT_SEQ    = 'lvmu_delete_job_seq';
	public const HOOK       = 'lvmu_run_delete_job';
	public const CHUNK      = 50;
	public const TIME_BOX   = 20;
	public const WAIT       = 5 * MINUTE_IN_SECONDS;
	public const KEEP_DAYS  = 7;
	public const MAX_ITEMS  = 50;
	public const RECENT     = DAY_IN_SECONDS;
	/** A running job without progress for this long counts as stalled. */
	public const STALE_AFTER = 3 * MINUTE_IN_SECONDS;
	/** An in-progress action older than this no longer blocks a resume. */
	public const STUCK_AFTER = 10 * MINUTE_IN_SECONDS;
	public const MAX_ATTEMPTS = 3;
	public const WATCHDOG_THROTTLE = 'lvmu_delete_watchdog';

	private static bool $locked = false;

	public static function register(): void {
		add_action(self::HOOK, [self::class, 'run'], 10, 1);
		add_action('init', [self::class, 'watchdog'], 30);
	}

	/* ------------------------------------------------------------------ storage */

	/** @return array<int, array> Jobs keyed by id, finished jobs older than 7 days pruned. */
	public static function jobs(): array {
		$jobs = get_option(self::OPTION, []);
		$jobs = is_array($jobs) ? $jobs : [];
		$cut  = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		$dirty = false;
		foreach ($jobs as $id => $job) {
			if (!in_array($job['status'], ['pending', 'running'], true) && (int) ($job['finished_at'] ?? 0) < $cut) {
				unset($jobs[$id]);
				$dirty = true;
			}
		}
		if ($dirty) {
			update_option(self::OPTION, $jobs, false);
		}
		return $jobs;
	}

	public static function get(int $job_id): ?array {
		return self::jobs()[$job_id] ?? null;
	}

	private static function save(array $job): void {
		$jobs                = self::jobs();
		$jobs[(int) $job['id']] = $job;
		update_option(self::OPTION, $jobs, false);
	}

	/** Update only the progress counters of one running job (re-reads the option first). */
	private static function save_progress(int $job_id, array $fields): void {
		$jobs = self::jobs();
		if (!isset($jobs[$job_id]) || $jobs[$job_id]['status'] !== 'running') {
			return;
		}
		$jobs[$job_id] = array_merge($jobs[$job_id], $fields);
		update_option(self::OPTION, $jobs, false);
	}

	/** Remove a finished job from the list. */
	public static function dismiss(int $job_id): bool {
		$jobs = self::jobs();
		if (!isset($jobs[$job_id]) || in_array($jobs[$job_id]['status'], ['pending', 'running'], true)) {
			return false;
		}
		unset($jobs[$job_id]);
		update_option(self::OPTION, $jobs, false);
		return true;
	}

	/** @return array<int, array> Pending and running jobs. */
	public static function active_jobs(): array {
		return array_filter(self::jobs(), static fn($j) => in_array($j['status'], ['pending', 'running'], true));
	}

	public static function has_active(): bool {
		return (bool) self::active_jobs();
	}

	/**
	 * Ids of files still waiting for deletion in pending/running jobs.
	 *
	 * @return int[]
	 */
	public static function scheduled_ids(): array {
		$ids = [];
		foreach (self::active_jobs() as $job) {
			$ids[] = self::remaining($job);
		}
		return $ids ? array_values(array_unique(array_merge(...$ids))) : [];
	}

	/**
	 * Ids a job still has to handle. Jobs made by older versions have no 'remaining' list:
	 * they start over from all of their file_ids (the Deleter skips files that are gone).
	 *
	 * @return int[]
	 */
	public static function remaining(array $job): array {
		$ids = array_key_exists('remaining', $job) ? $job['remaining'] : ($job['file_ids'] ?? []);
		return array_values(array_map('intval', (array) $ids));
	}

	/** Timestamp of the last sign of life of a job. */
	public static function last_progress(array $job): int {
		return (int) ($job['last_progress_at'] ?? 0) ?: (int) ($job['started_at'] ?? 0) ?: (int) ($job['created_at'] ?? 0);
	}

	/** Running, but nothing happened for a while. */
	public static function is_stale(array $job): bool {
		return $job['status'] === 'running' && time() - self::last_progress($job) > self::STALE_AFTER;
	}

	/** Id of the job that is up next: jobs run one at a time, in created order. */
	public static function head_id(): int {
		$ids = array_keys(self::active_jobs());
		return $ids ? (int) min($ids) : 0;
	}

	/** A job that has to wait for another active job before it. */
	public static function is_waiting(array $job): bool {
		return in_array($job['status'], ['pending', 'running'], true) && (int) $job['id'] !== self::head_id();
	}

	/**
	 * Jobs run one at a time. A job that is not up next can still say 'running' (left over from an
	 * older version or a crash): put it back to pending, keeping its counters and remaining ids.
	 */
	public static function normalize(): void {
		$head = self::head_id();
		$jobs = self::jobs();
		$dirty = false;
		foreach ($jobs as $id => $job) {
			if ($job['status'] === 'running' && (int) $id !== $head) {
				$jobs[$id]['status'] = 'pending';
				$dirty = true;
			}
		}
		if ($dirty) {
			update_option(self::OPTION, $jobs, false);
		}
	}

	/* ------------------------------------------------------------------ schedule */

	/**
	 * @param int[] $file_ids Row ids from lvmu_files.
	 * @return int Job id.
	 */
	public static function schedule(array $file_ids, bool $allow_used, int $user_id): int {
		global $wpdb;
		$file_ids = array_values(array_unique(array_filter(array_map('intval', $file_ids))));

		$size  = 0;
		$table = Schema::files_table();
		foreach (array_chunk($file_ids, 1000) as $chunk) {
			$size += (int) $wpdb->get_var("SELECT COALESCE(SUM(size), 0) FROM {$table} WHERE id IN (" . implode(',', $chunk) . ')');
		}

		$seq = (int) get_option(self::OPT_SEQ, 0);
		$seq = max($seq, (int) max(array_keys(self::jobs()) ?: [0])) + 1;
		update_option(self::OPT_SEQ, $seq, false);

		$count = count($file_ids);
		self::save([
			'id'          => $seq,
			'created_at'  => time(),
			'user_id'     => $user_id,
			'file_ids'    => [],
			'remaining'   => $file_ids,
			'attempts'    => 0,
			'last_progress_at' => time(),
			'allow_used'  => $allow_used,
			'count'       => $count,
			'total_size'  => $size,
			'label'       => self::label($count, $size),
			'status'      => 'pending',
			'note'        => '',
			'processed'   => 0,
			'deleted'     => 0,
			'freed'       => 0,
			'skipped'     => [],
			'skipped_count' => 0,
			'errors'      => [],
			'error_count' => 0,
			'started_at'  => null,
			'finished_at' => null,
		]);
		// Jobs run one at a time; a job that has to wait is queued when the one before it ends.
		if (self::head_id() === $seq) {
			self::enqueue($seq);
		}
		return $seq;
	}

	public static function label(int $count, int $size): string {
		/* translators: 1: number of files, 2: size like 34 MB */
		return sprintf(_n('%1$s file, %2$s', '%1$s files, %2$s', $count, 'labelvier-media-usage'), number_format_i18n($count), size_format($size));
	}

	/** Queue the job unless an action for it is already waiting. */
	private static function enqueue(int $job_id): void {
		if (function_exists('as_enqueue_async_action') && !self::has_pending_action($job_id)) {
			as_enqueue_async_action(self::HOOK, [$job_id], Scheduler::GROUP);
		}
	}

	private static function has_pending_action(int $job_id): bool {
		return self::has_action($job_id, [\ActionScheduler_Store::STATUS_PENDING]);
	}

	/** @param string[] $statuses */
	private static function has_action(int $job_id, array $statuses): bool {
		if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler_Store')) {
			return false;
		}
		foreach ($statuses as $status) {
			if (as_get_scheduled_actions([
				'hook'     => self::HOOK,
				'args'     => [$job_id],
				'group'    => Scheduler::GROUP,
				'status'   => $status,
				'per_page' => 1,
			], 'ids')) {
				return true;
			}
		}
		return false;
	}

	private static function unschedule(int $job_id): void {
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::HOOK, [$job_id], Scheduler::GROUP);
		}
	}

	/** Start the next job in line (after a job ended). */
	private static function kick_next(): void {
		$next = self::head_id();
		if (!$next) {
			return;
		}
		$job = self::get($next);
		if ($job && $job['status'] === 'running') {
			$job['last_progress_at'] = time();
			self::save($job);
		}
		self::enqueue($next);
	}

	/* ------------------------------------------------------------------ cancel */

	/** Cancel a job that has not started yet, or one that stalled. Counters are kept. */
	public static function cancel(int $job_id): bool {
		$job = self::get($job_id);
		if (!$job) {
			return false;
		}
		$waiting = ($job['status'] === 'pending' && !$job['started_at']) || self::is_waiting($job);
		if (!$waiting && !self::is_stale($job)) {
			return false;
		}
		self::unschedule($job_id);
		$job['status']      = 'cancelled';
		$job['note']        = '';
		$job['file_ids']    = [];
		$job['remaining']   = [];
		$job['finished_at'] = time();
		self::save($job);
		self::kick_next();
		return true;
	}

	/** Resume a stalled or waiting job right now: queue it again and run it in this request. */
	public static function resume(int $job_id): bool {
		$job = self::get($job_id);
		if (!$job || !in_array($job['status'], ['pending', 'running'], true) || self::is_waiting($job)) {
			return false; // jobs run one at a time: only the job that is up next can be resumed
		}
		$job['last_progress_at'] = time();
		$job['note']             = '';
		self::save($job);
		self::enqueue($job_id);
		self::prepare_inline();
		self::run($job_id, true);
		return true;
	}

	/* ------------------------------------------------------------------ watchdog */

	/**
	 * Recover jobs whose runner died (fatal error, aborted request, killed process) and left
	 * no action behind. Only the job that is up next is looked at.
	 */
	public static function watchdog(bool $throttled = true): void {
		if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler') || !\ActionScheduler::is_initialized()) {
			return;
		}
		if (!self::has_active()) {
			return;
		}
		self::normalize();
		if ($throttled) {
			if (get_transient(self::WATCHDOG_THROTTLE)) {
				return;
			}
			set_transient(self::WATCHDOG_THROTTLE, 1, 2 * MINUTE_IN_SECONDS);
		}

		$job = self::get(self::head_id());
		if (!$job) {
			return;
		}
		$id = (int) $job['id'];
		if (self::has_pending_action($id)) {
			return;
		}

		if ($job['status'] === 'pending') {
			self::enqueue($id);
			return;
		}
		if (!self::is_stale($job)) {
			return;
		}
		// A runner may still be busy; Action Scheduler fails actions that hang for 5 minutes.
		$idle = time() - self::last_progress($job);
		if ($idle < self::STUCK_AFTER && self::has_action($id, [\ActionScheduler_Store::STATUS_RUNNING])) {
			return;
		}

		$attempts = (int) ($job['attempts'] ?? 0);
		if ($attempts >= self::MAX_ATTEMPTS) {
			$left = count(self::remaining($job));
			self::fail($job, sprintf(
				/* translators: %s: number of files left */
				_n('Stopped repeatedly; %s file left.', 'Stopped repeatedly; %s files left.', $left, 'labelvier-media-usage'),
				number_format_i18n($left)
			));
			self::kick_next();
			return;
		}
		$job['attempts']         = $attempts + 1;
		$job['last_progress_at'] = time();
		self::save($job);
		self::enqueue($id);
	}

	/** Mark a job failed, keeping its counters. */
	private static function fail(array $job, string $reason): void {
		$job['status']      = 'failed';
		$job['errors'][]    = ['path' => '', 'reason' => $reason];
		$job['error_count']++;
		$job['error']       = $reason;
		$job['finished_at'] = time();
		$job['file_ids']    = [];
		$job['remaining']   = [];
		self::save($job);
		self::unschedule((int) $job['id']);
	}

	/* ------------------------------------------------------------------ run */

	public static function run(int $job_id, bool $force = false): void {
		$job = self::get($job_id);
		if (!$job || !in_array($job['status'], ['pending', 'running'], true)) {
			return;
		}
		// Jobs run one at a time, oldest first; the next one is queued when this one ends.
		if (!$force && self::head_id() !== $job_id) {
			self::normalize();
			return;
		}
		// One runner at a time (Action Scheduler and the admin poll can overlap).
		if (!self::lock()) {
			return;
		}
		try {
			self::run_locked($job_id);
		} catch (\Throwable $e) {
			$job = self::get($job_id);
			if ($job && in_array($job['status'], ['pending', 'running'], true)) {
				self::fail($job, $e->getMessage());
				self::kick_next();
			}
		} finally {
			self::unlock();
		}
	}

	private static function run_locked(int $job_id): void {
		$job = self::get($job_id);
		if (!$job || !in_array($job['status'], ['pending', 'running'], true)) {
			return;
		}
		if (Deleter::disabled_reason() !== '') {
			self::wait($job);
			return;
		}

		$job['status']           = 'running';
		$job['note']             = '';
		$job['started_at']       = $job['started_at'] ?: time();
		$job['last_progress_at'] = time();
		$job['remaining']        = self::remaining($job); // jobs from older versions: start from all ids
		$job['file_ids']         = [];
		$job['attempts']         = (int) ($job['attempts'] ?? 0);
		self::save($job);

		$deleter   = new Deleter();
		$total     = (int) $job['count'];
		$deadline  = microtime(true) + self::TIME_BOX;
		$remaining = $job['remaining'];

		while ($remaining) {
			if (Deleter::disabled_reason() !== '') {
				self::wait($job);
				return;
			}
			$chunk = array_slice($remaining, 0, self::CHUNK);
			$base  = ['deleted' => (int) $job['deleted'], 'freed' => (int) $job['freed'], 'skipped' => (int) $job['skipped_count'], 'errors' => (int) $job['error_count']];
			$last  = 0.0;
			$res   = $deleter->delete($chunk, (bool) $job['allow_used'], static function (array $running, int $done, array $handled) use ($job_id, $base, $remaining, $total, $deadline, &$last): bool {
				// Live progress for the admin and for a resume: at most one write per second.
				if (microtime(true) - $last >= 1) {
					$last = microtime(true);
					$left = array_values(array_diff($remaining, $handled));
					self::save_progress($job_id, [
						'remaining'     => $left,
						'processed'     => $total - count($left),
						'deleted'       => $base['deleted'] + $running['deleted'],
						'freed'         => $base['freed'] + $running['freed'],
						'skipped_count' => $base['skipped'] + count($running['skipped']),
						'error_count'   => $base['errors'] + count($running['errors']),
						'last_progress_at' => time(),
					]);
				}
				return microtime(true) < $deadline;
			});

			// Deleting got disabled between the check and the call: retry the chunk later.
			if (!$res['handled'] && Deleter::disabled_reason() !== '') {
				self::wait($job);
				return;
			}
			if (!$res['handled']) {
				throw new \RuntimeException($res['errors'][0]['reason'] ?? __('Nothing could be processed.', 'labelvier-media-usage'));
			}

			// Cancelled (or failed) while this chunk was running: leave the job alone.
			$fresh = self::get($job_id);
			if (!$fresh || $fresh['status'] !== 'running') {
				return;
			}
			$job = $fresh;

			$remaining      = array_values(array_diff($remaining, $res['handled']));
			$job['remaining'] = $remaining;
			$job['processed'] = $total - count($remaining);
			$job['deleted']   = $base['deleted'] + $res['deleted'];
			$job['freed']     = $base['freed'] + $res['freed'];
			$job['attempts']  = 0;
			$job['last_progress_at'] = time();
			foreach (['skipped', 'errors'] as $key) {
				$count_key        = $key === 'skipped' ? 'skipped_count' : 'error_count';
				$job[$count_key]  = $base[$key] + count($res[$key]);
				$room             = self::MAX_ITEMS - count($job[$key]);
				if ($room > 0) {
					$job[$key] = array_merge($job[$key], array_slice($res[$key], 0, $room));
				}
			}
			self::save($job);

			if ($remaining && microtime(true) >= $deadline) {
				self::enqueue($job_id);
				return;
			}
		}

		$job['status']      = 'done';
		$job['finished_at'] = time();
		$job['file_ids']    = [];
		$job['remaining']   = [];
		self::save($job);
		self::unschedule($job_id);
		self::kick_next();
	}

	/** Deleting is disabled (index running): keep the job pending and retry in 5 minutes. */
	private static function wait(array $job): void {
		$job['status'] = 'pending';
		$job['note']   = __('Waiting for index to finish', 'labelvier-media-usage');
		self::save($job);
		if (function_exists('as_schedule_single_action') && !self::has_pending_action((int) $job['id'])) {
			as_schedule_single_action(time() + self::WAIT, self::HOOK, [(int) $job['id']], Scheduler::GROUP);
		}
	}

	private static function lock_name(): string {
		global $wpdb;
		return substr('lvmu_delete_' . $wpdb->prefix, 0, 64);
	}

	private static function lock(): bool {
		global $wpdb;
		$got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name()));
		self::$locked = ((int) $got === 1);
		return self::$locked;
	}

	private static function unlock(): void {
		global $wpdb;
		if (self::$locked) {
			self::$locked = false;
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
		}
	}

	/** Let the request finish its job when the browser goes away (page reload, closed tab). */
	public static function prepare_inline(): void {
		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(self::TIME_BOX + 40); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Process one due delete action in the current request (admin status poll), so jobs
	 * also run where the Action Scheduler loopback request is blocked. A job that waits for
	 * the index is started right away once deleting is allowed again.
	 */
	public static function run_pending_inline(): void {
		if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler')) {
			return;
		}
		self::prepare_inline();
		self::watchdog(false);
		$ids = as_get_scheduled_actions([
			'hook'         => self::HOOK,
			'group'        => Scheduler::GROUP,
			'status'       => \ActionScheduler_Store::STATUS_PENDING,
			'date'         => as_get_datetime_object(),
			'date_compare' => '<=',
			'per_page'     => 1,
			'orderby'      => 'date',
			'order'        => 'ASC',
		], 'ids');
		if ($ids) {
			\ActionScheduler::runner()->process_action((int) $ids[0], 'LVMU admin poll');
			return;
		}
		if (Deleter::disabled_reason() !== '') {
			return;
		}
		$head = self::get(self::head_id());
		if ($head && $head['status'] === 'pending' && $head['note'] !== '') {
			self::run((int) $head['id']);
		}
	}
}
