<?php

namespace LabelvierMediaUsage;

use LabelvierMediaUsage\Admin\Page;

/**
 * Wires all parts of the plugin together.
 */
class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		add_action('init', static function () {
			load_plugin_textdomain('labelvier-media-usage', false, dirname(plugin_basename(LVMU_FILE)) . '/languages');
		});
		Schema::maybe_install();
		Updater::register();
		if (is_multisite()) {
			add_action('admin_notices', [self::class, 'multisite_notice']);
			add_action('network_admin_notices', [self::class, 'multisite_notice']);
		} else {
			if (class_exists(Scheduler::class)) {
				Scheduler::register();
			}
			if (class_exists(DeleteQueue::class)) {
				DeleteQueue::register();
			}

			if (is_admin() && class_exists(Page::class)) {
				Page::register();
			}
		}

		if (defined('WP_CLI') && WP_CLI && class_exists(Cli::class)) {
			\WP_CLI::add_command('lvmu', Cli::class);
		}
	}

	public static function multisite_notice(): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && !in_array($screen->id, ['plugins', 'plugins-network'], true)) {
			return;
		}
		if (!current_user_can('activate_plugins')) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__('Label Vier Media Usage does not support multisite installations and is disabled, because files of other sites would be reported as unused.', 'labelvier-media-usage')
			. '</p></div>';
	}

	public static function activate(): void {
		Schema::install();
		// Action Scheduler may not be initialised yet on activation, the nightly
		// action is (re)scheduled on the next request by Scheduler::ensure_nightly().
		update_option('lvmu_needs_schedule', 1, false);
	}

	public static function deactivate(): void {
		if (function_exists('as_unschedule_all_actions') && class_exists(Scheduler::class)) {
			as_unschedule_all_actions('', [], Scheduler::GROUP);
		}
		delete_option('lvmu_needs_schedule');
	}
}
