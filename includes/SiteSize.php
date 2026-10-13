<?php

namespace LabelvierMediaUsage;

/**
 * Measures how much disk space a site takes: uploads, database and plugins.
 *
 * WordPress core and the theme are not measured but covered by a fixed
 * allowance, which gives a good estimate of the total size of the site.
 *
 * The admin page never measures itself: the last measurement is stored in an option and
 * refreshed in the background through Action Scheduler (after every index run and when
 * the stored measurement is missing or older than a day).
 */
class SiteSize {

	/**
	 * Fixed allowance for WordPress core, themes and other files, in bytes (300 MB).
	 */
	public const BASE_ALLOWANCE = 300 * 1024 * 1024;

	/** Option with the last measurement (see measure()). */
	public const OPTION = 'lvmu_site_size';

	/** Action Scheduler hook that measures and stores the size. */
	public const HOOK = 'lvmu_measure_site_size';

	/** A stored measurement older than this is refreshed, in seconds (24 hours). */
	public const MAX_AGE = 24 * 60 * 60;

	public static function register(): void {
		add_action(self::HOOK, [self::class, 'measure_and_store']);
	}

	/**
	 * Measure the site and store the result as the last measurement.
	 *
	 * @return array{uploads: int, database: int, plugins: int, base: int, total: int, measured_at: string}
	 */
	public static function measure_and_store(): array {
		$size = self::measure();
		update_option(self::OPTION, $size, false);
		return $size;
	}

	/**
	 * The last stored measurement, or null when the site has not been measured yet.
	 *
	 * @return array{uploads: int, database: int, plugins: int, base: int, total: int, measured_at: string}|null
	 */
	public static function stored(): ?array {
		$size = get_option(self::OPTION);
		return is_array($size) && isset($size['total'], $size['measured_at']) ? $size : null;
	}

	/** Unix time of a measurement (0 when unknown). */
	public static function measured_at(array $size): int {
		$time = strtotime((string) ($size['measured_at'] ?? ''));
		return $time ? (int) $time : 0;
	}

	public static function is_stale(?array $size): bool {
		return $size === null || self::measured_at($size) < time() - self::MAX_AGE;
	}

	/**
	 * Queue a background measurement unless one is already pending or running.
	 *
	 * @return bool Whether a measurement is queued (now or before).
	 */
	public static function schedule(): bool {
		if (!function_exists('as_enqueue_async_action') || !function_exists('as_has_scheduled_action') || !class_exists('ActionScheduler') || !\ActionScheduler::is_initialized()) {
			return false;
		}
		if (as_has_scheduled_action(self::HOOK, null, Scheduler::GROUP)) {
			return true;
		}
		return (bool) as_enqueue_async_action(self::HOOK, [], Scheduler::GROUP);
	}

	/**
	 * Measure the site.
	 *
	 * @return array{uploads: int, database: int, plugins: int, base: int, total: int, measured_at: string}
	 */
	public static function measure(): array {
		$uploads  = self::directory_size(FileRepository::uploads_basedir());
		$database = self::database_size();
		$plugins  = self::directory_size(WP_PLUGIN_DIR);
		$base     = (int) apply_filters('lvmu_site_size_base_allowance', self::BASE_ALLOWANCE);

		return [
			'uploads'     => $uploads,
			'database'    => $database,
			'plugins'     => $plugins,
			'base'        => $base,
			'total'       => $uploads + $database + $plugins + $base,
			'measured_at' => gmdate('c'),
		];
	}

	/**
	 * Size of all tables with the WordPress prefix (data and indexes), in bytes.
	 *
	 * @return int
	 */
	public static function database_size(): int {
		global $wpdb;

		$size = $wpdb->get_var($wpdb->prepare(
			'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
			DB_NAME,
			$wpdb->esc_like($wpdb->prefix) . '%'
		));

		return (int) $size;
	}

	/**
	 * Total size of all files below a directory, in bytes. Symlinks are not followed.
 * Never throws: unreadable or vanished entries are skipped.
	 *
	 * @param string $dir
	 * @return int
	 */
	public static function directory_size(string $dir): int {
		if (!is_dir($dir)) {
			return 0;
		}

		$size = 0;
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ($iterator as $file) {
				try {
					if ($file->isFile() && !$file->isLink()) {
						$size += (int) $file->getSize();
					}
				} catch (\RuntimeException $e) {
					continue; // Unreadable or removed while measuring.
				}
			}
		} catch (\UnexpectedValueException | \RuntimeException $e) {
			// Unreadable directory: return what was measured so far.
		}

		return $size;
	}
}
