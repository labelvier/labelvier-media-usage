<?php
/**
 * Plugin Name: Label Vier Media Usage
 * Description: Indexes every file in the uploads folder, links files to Media Library items and finds unused and orphaned files.
 * Version: 0.4.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Label Vier
 * Author URI: https://labelvier.nl
 * License: GPL-2.0-or-later
 * Text Domain: labelvier-media-usage
 * Domain Path: /languages
 */

use LabelvierMediaUsage\Plugin;

if (!defined('ABSPATH')) {
	exit;
}

define('LVMU_VERSION', '0.4.0');
define('LVMU_FILE', __FILE__);
define('LVMU_DIR', __DIR__);
define('LVMU_URL', plugin_dir_url(__FILE__));

// Action Scheduler must be loaded directly from the main plugin file, not on a hook.
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

/**
 * Entry point for autoloading Label Vier Media Usage classes.
 */
spl_autoload_register(static function ($class_name) {
	// project-specific namespace prefix.
	$prefix = 'LabelvierMediaUsage\\';

	// base directory for the namespace prefix.
	$base_dir = __DIR__ . '/includes/';

	// does the class use the namespace prefix?
	$len = strlen($prefix);

	// return if not using the prefix
	if (strncmp($prefix, $class_name, $len) !== 0) {
		return;
	}

	$relative_class = substr($class_name, $len);

	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

	// if the file exists, require it.
	if (file_exists($file)) {
		require $file;
	}
});

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function () {
	Plugin::instance()->boot();
});
