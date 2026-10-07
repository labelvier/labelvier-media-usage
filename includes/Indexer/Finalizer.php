<?php

namespace LabelvierMediaUsage\Indexer;

use LabelvierMediaUsage\FileRepository;
use LabelvierMediaUsage\ScanState;
use LabelvierMediaUsage\Schema;

/**
 * Phase 5: removes stale rows, stores the summary and marks the scan as done.
 */
class Finalizer implements PhaseInterface {

	public const SUMMARY_OPTION = 'lvmu_summary';

	public function start(int $scan_id): void {
	}

	public function run(int $scan_id, float $deadline): bool {
		global $wpdb;
		$wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::files_table() . ' WHERE scan_id < %d', $scan_id));
		$wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::refs_table() . ' WHERE scan_id < %d', $scan_id));

		$summary                 = (new FileRepository())->summary();
		$summary['generated_at'] = time();
		update_option(self::SUMMARY_OPTION, $summary, false);

		ScanState::update([
			'status'      => 'done',
			'phase'       => '',
			'finished_at' => time(),
		]);
		return true;
	}
}
