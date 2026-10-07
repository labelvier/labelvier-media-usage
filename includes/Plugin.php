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
		Schema::maybe_install();
		if (class_exists(Scheduler::class)) {
			Scheduler::register();
		}

		if (is_admin() && class_exists(Page::class)) {
			Page::register();
		}

		if (defined('WP_CLI') && WP_CLI && class_exists(Cli::class)) {
			\WP_CLI::add_command('lvmu', Cli::class);
		}
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
