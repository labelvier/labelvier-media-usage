<?php

namespace LabelvierMediaUsage;

/**
 * Persists the state of the running (or last) index run in an option.
 *
 * Shape:
 * [
 *   'scan_id'     => int,
 *   'status'      => 'idle'|'running'|'done'|'failed',
 *   'phase'       => string, one of Scheduler::PHASES keys or '',
 *   'started_at'  => int|null unix,
 *   'finished_at' => int|null unix,
 *   'progress'    => array<string, int|string>, phase specific counters,
 *   'error'       => string,
 *   'last_progress_at' => int, unix time of the last progress() call (watchdog),
 * ]
 */
class ScanState {

	public const OPTION = 'lvmu_scan_state';

	public static function get(): array {
		$state = get_option(self::OPTION, []);
		return wp_parse_args(is_array($state) ? $state : [], [
			'scan_id'     => 0,
			'status'      => 'idle',
			'phase'       => '',
			'started_at'  => null,
			'finished_at' => null,
			'progress'    => [],
			'error'       => '',
			'last_progress_at' => 0,
		]);
	}

	public static function update(array $changes): array {
		$state = array_merge(self::get(), $changes);
		update_option(self::OPTION, $state, false);
		return $state;
	}

	/**
	 * Merge counters into the progress array of the current state.
	 */
	public static function progress(array $counters): void {
		$state = self::get();
		self::update([
			'progress'         => array_merge($state['progress'], $counters),
			'last_progress_at' => time(),
		]);
	}

	public static function is_running(): bool {
		return self::get()['status'] === 'running';
	}
}
