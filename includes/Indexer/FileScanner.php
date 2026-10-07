<?php

namespace LabelvierMediaUsage\Indexer;

use LabelvierMediaUsage\FileRepository;
use LabelvierMediaUsage\ScanState;

/**
 * Phase 1: walks the uploads folder and upserts one row per file.
 *
 * The cursor is a queue of relative directories in option lvmu_scan_dirs.
 */
class FileScanner implements PhaseInterface {

	public const OPTION = 'lvmu_scan_dirs';

	public function start(int $scan_id): void {
		update_option(self::OPTION, [''], false);
		ScanState::progress(['files_found' => 0, 'dirs_left' => 1]);
	}

	public function run(int $scan_id, float $deadline): bool {
		$repo  = new FileRepository();
		$base  = FileRepository::uploads_basedir();
		$queue = get_option(self::OPTION, []);
		$queue = is_array($queue) ? $queue : [];
		$found = (int) (ScanState::get()['progress']['files_found'] ?? 0);

		while ($queue) {
			$relative = array_pop($queue);
			$absolute = $relative === '' ? $base : $base . '/' . $relative;
			$entries  = is_dir($absolute) && !is_link($absolute) ? @scandir($absolute) : false;
			if ($entries === false) {
				continue;
			}

			$rows = [];
			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				$entry_relative = $relative === '' ? $entry : $relative . '/' . $entry;
				$entry_absolute = $base . '/' . $entry_relative;
				if (is_link($entry_absolute)) {
					continue;
				}
				if (is_dir($entry_absolute)) {
					$queue[] = $entry_relative;
					continue;
				}
				$size = @filesize($entry_absolute);
				if ($size === false) {
					continue;
				}
				$rows[] = [
					'path'      => $entry_relative,
					'size'      => $size,
					'mtime'     => (int) @filemtime($entry_absolute),
					'file_role' => 'orphan',
				];
				if (count($rows) >= 500) {
					$repo->upsert_many($rows, $scan_id);
					$found += count($rows);
					$rows   = [];
				}
			}
			$repo->upsert_many($rows, $scan_id);
			$found += count($rows);

			update_option(self::OPTION, $queue, false);
			ScanState::progress(['files_found' => $found, 'dirs_left' => count($queue)]);

			if (microtime(true) >= $deadline) {
				return !$queue;
			}
		}

		update_option(self::OPTION, [], false);
		ScanState::progress(['files_found' => $found, 'dirs_left' => 0]);
		return true;
	}
}
