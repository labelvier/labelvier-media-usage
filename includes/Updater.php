<?php

namespace LabelvierMediaUsage;

/**
 * Automatic updates from the GitHub releases of the plugin repository.
 *
 * The release zip asset is installed on update and the release notes (the matching
 * section of CHANGELOG.md) are shown in the "View version x details" screen.
 */
class Updater {

	public const REPOSITORY = 'https://github.com/labelvier/labelvier-media-usage/';

	public static function register(): void {
		/**
		 * Filters whether the plugin checks GitHub for updates.
		 *
		 * @param bool $enabled Default true.
		 */
		if (!apply_filters('lvmu_update_checker_enabled', true)) {
			return;
		}

		$loader = LVMU_DIR . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
		if (!file_exists($loader)) {
			return;
		}
		require_once $loader;

		if (!class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPOSITORY,
			LVMU_FILE,
			'labelvier-media-usage'
		);
		// Install the zip attached to the release, not the GitHub source zipball.
		$checker->getVcsApi()->enableReleaseAssets('/\.zip$/i');
	}
}
