<?php
/**
 * Removes the plugin tables, options and scheduled actions when the plugin is deleted.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lvmu_files");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lvmu_refs");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lvmu\\_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lvmu\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lvmu\\_%'");

// All actions of the plugin (index, delete jobs, lvmu_measure_site_size) are in the group lvmu.
// Deactivation already unschedules them; this covers Action Scheduler loaded by another plugin.
if (function_exists('as_unschedule_all_actions')) {
	as_unschedule_all_actions('', [], 'lvmu');
}
