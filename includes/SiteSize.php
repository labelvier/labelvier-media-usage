<?php

namespace LabelvierMediaUsage;

/**
 * Measures how much disk space a site takes: uploads, database and plugins.
 *
 * WordPress core and the theme are not measured but covered by a fixed
 * allowance, which gives a good estimate of the total size of the site.
 */
class SiteSize {

	/**
	 * Fixed allowance for WordPress core, themes and other files, in bytes (300 MB).
	 */
	public const BASE_ALLOWANCE = 300 * 1024 * 1024;

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
