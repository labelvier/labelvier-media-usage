<?php

namespace LabelvierMediaUsage\Indexer;

/**
 * One step of the index pipeline. The Scheduler calls run() from an Action Scheduler
 * action and re-queues the same phase until run() returns true.
 */
interface PhaseInterface {

	/**
	 * Called once when the phase starts, before the first run(). Reset cursors here.
	 */
	public function start(int $scan_id): void;

	/**
	 * Process work until done or until microtime(true) passes $deadline.
	 * Keep cursors in options so the next call continues where this one stopped.
	 *
	 * @return bool True when the phase is finished.
	 */
	public function run(int $scan_id, float $deadline): bool;
}
