<?php
/**
 * Removes the plugin tables and options when the plugin is deleted.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lvmu_files");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lvmu_refs");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lvmu\\_%'");
