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

	private static bool $locked = false;

	public static function register(): void {
		add_action(self::HOOK, [self::class, 'run'], 10, 1);
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
			$ids[] = array_slice($job['file_ids'], (int) $job['processed']);
		}
		return $ids ? array_values(array_unique(array_merge(...$ids))) : [];
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
			'file_ids'    => $file_ids,
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
		self::enqueue($seq);
		return $seq;
	}

	public static function label(int $count, int $size): string {
		/* translators: 1: number of files, 2: size like 34 MB */
		return sprintf(_n('%1$s file, %2$s', '%1$s files, %2$s', $count, 'labelvier-media-usage'), number_format_i18n($count), size_format($size));
	}

	private static function enqueue(int $job_id): void {
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action(self::HOOK, [$job_id], Scheduler::GROUP);
		}
	}

	private static function has_pending_action(int $job_id): bool {
		if (!function_exists('as_get_scheduled_actions')) {
			return false;
		}
		return (bool) as_get_scheduled_actions([
			'hook'     => self::HOOK,
			'args'     => [$job_id],
			'group'    => Scheduler::GROUP,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		], 'ids');
	}

	/* ------------------------------------------------------------------ cancel */

	/** Cancel a job that has not started yet. */
	public static function cancel(int $job_id): bool {
		$job = self::get($job_id);
		if (!$job || $job['status'] !== 'pending' || $job['started_at']) {
			return false;
		}
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::HOOK, [$job_id], Scheduler::GROUP);
		}
		$job['status']      = 'cancelled';
		$job['note']        = '';
		$job['file_ids']    = [];
		$job['finished_at'] = time();
		self::save($job);
		return true;
	}

	/* ------------------------------------------------------------------ run */

	public static function run(int $job_id): void {
		$job = self::get($job_id);
		if (!$job || !in_array($job['status'], ['pending', 'running'], true)) {
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
			if ($job) {
				$job['status']      = 'failed';
				$job['errors'][]    = ['path' => '', 'reason' => $e->getMessage()];
				$job['error_count']++;
				$job['finished_at'] = time();
				$job['file_ids']    = [];
				self::save($job);
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

		$job['status']     = 'running';
		$job['note']       = '';
		$job['started_at'] = $job['started_at'] ?: time();
		self::save($job);

		$deleter  = new Deleter();
		$total    = count($job['file_ids']);
		$deadline = microtime(true) + self::TIME_BOX;

		while ($job['processed'] < $total) {
			if (Deleter::disabled_reason() !== '') {
				self::wait($job);
				return;
			}
			$chunk = array_slice($job['file_ids'], (int) $job['processed'], self::CHUNK);
			$res   = $deleter->delete($chunk, (bool) $job['allow_used']);

			// Deleting got disabled between the check and the call: retry the chunk later.
			if (!$res['deleted'] && !$res['skipped'] && count($res['errors']) === 1 && $res['errors'][0]['path'] === '' && Deleter::disabled_reason() !== '') {
				self::wait($job);
				return;
			}

			$job['processed'] += count($chunk);
			$job['deleted']   += $res['deleted'];
			$job['freed']     += $res['freed'];
			foreach (['skipped', 'errors'] as $key) {
				$count_key        = $key === 'skipped' ? 'skipped_count' : 'error_count';
				$job[$count_key] += count($res[$key]);
				$room             = self::MAX_ITEMS - count($job[$key]);
				if ($room > 0) {
					$job[$key] = array_merge($job[$key], array_slice($res[$key], 0, $room));
				}
			}
			self::save($job);

			if ($job['processed'] < $total && microtime(true) >= $deadline) {
				if (!self::has_pending_action($job_id)) {
					self::enqueue($job_id);
				}
				return;
			}
		}

		$job['status']      = 'done';
		$job['finished_at'] = time();
		$job['file_ids']    = [];
		self::save($job);
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::HOOK, [$job_id], Scheduler::GROUP);
		}
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

	/**
	 * Process one due delete action in the current request (admin status poll), so jobs
	 * also run where the Action Scheduler loopback request is blocked. A job that waits for
	 * the index is started right away once deleting is allowed again.
	 */
	public static function run_pending_inline(): void {
		if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler')) {
			return;
		}
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
		foreach (self::active_jobs() as $job) {
			if ($job['status'] === 'pending' && $job['note'] !== '') {
				self::run((int) $job['id']);
				return;
			}
		}
	}
}
