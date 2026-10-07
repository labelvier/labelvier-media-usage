<?php

namespace LabelvierMediaUsage;

/**
 * Creates and upgrades the custom tables.
 */
class Schema {

	public const DB_VERSION = '3';
	public const OPTION = 'lvmu_db_version';

	public static function files_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lvmu_files';
	}

	public static function refs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lvmu_refs';
	}

	/**
	 * Run dbDelta when the stored version differs.
	 */
	public static function maybe_install(): void {
		if (get_option(self::OPTION) === self::DB_VERSION) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$files   = self::files_table();
		$refs    = self::refs_table();

		dbDelta("CREATE TABLE {$files} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(1024) NOT NULL,
			path_hash char(32) NOT NULL,
			filename varchar(255) NOT NULL,
			extension varchar(20) NOT NULL DEFAULT '',
			file_type varchar(20) NOT NULL DEFAULT 'other',
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			mtime int(10) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NULL DEFAULT NULL,
			file_role varchar(20) NOT NULL DEFAULT 'orphan',
			usage_status varchar(10) NOT NULL DEFAULT 'unknown',
			usage_count int(10) unsigned NOT NULL DEFAULT 0,
			usage_sources text NULL,
			scan_id int(10) unsigned NOT NULL DEFAULT 0,
			indexed_at datetime NOT NULL,
			file_flag varchar(20) NOT NULL DEFAULT '',
			flag_reason varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY attachment_id (attachment_id),
			KEY file_role (file_role),
			KEY file_type (file_type),
			KEY usage_status (usage_status),
			KEY size (size),
			KEY scan_id (scan_id),
			KEY file_flag (file_flag),
			KEY filename (filename(191))
		) {$charset};");

		dbDelta("CREATE TABLE {$refs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ref_type varchar(10) NOT NULL,
			ref_value varchar(1024) NOT NULL,
			ref_hash char(32) NOT NULL,
			source_type varchar(20) NOT NULL,
			source_table varchar(64) NOT NULL,
			source_id varchar(64) NOT NULL,
			source_field varchar(191) NOT NULL,
			scan_id int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY ref_hash (ref_hash),
			KEY scan_id (scan_id)
		) {$charset};");

		$previous = get_option(self::OPTION);
		update_option(self::OPTION, self::DB_VERSION, false);
		if ($previous !== false && version_compare((string) $previous, self::DB_VERSION, '<')) {
			// Flag rules changed (v2: flag columns, v3: copies): recompute the flags of all existing rows.
			(new FileRepository())->backfill_flags();
		}
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query('DROP TABLE IF EXISTS ' . self::files_table());
		$wpdb->query('DROP TABLE IF EXISTS ' . self::refs_table());
		delete_option(self::OPTION);
	}
}
