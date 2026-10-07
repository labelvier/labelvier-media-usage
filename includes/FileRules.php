<?php

namespace LabelvierMediaUsage;

/**
 * Hard-coded rules for files that must never be deleted (protected) and files that
 * deserve a closer look (suspicious). Single source of truth for Deleter, the index
 * flags and the list table. All matching is case-insensitive.
 */
class FileRules {

	public const REASONS = ['config', 'guard', 'hidden', 'plugin_data'];

	/**
	 * Patterns for protected files. Sites can extend them with the `lvmu_protected_patterns` filter.
	 *
	 * @return array{names: array<string, string>, extensions: array<string, string>, folders: string[]}
	 *   names: exact basename => reason, extensions: extension => reason,
	 *   folders: folder name (fnmatch glob) at any depth => plugin_data.
	 */
	public static function patterns(): array {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$patterns = [
			'names'      => [
				'.htaccess'  => 'config',
				'.htpasswd'  => 'config',
				'.user.ini'  => 'config',
				'php.ini'    => 'config',
				'web.config' => 'config',
				'index.php'  => 'guard',
				'index.html' => 'guard',
				'index.htm'  => 'guard',
			],
			'extensions' => ['ini' => 'config', 'conf' => 'config', 'config' => 'config'],
			'folders'    => ['wc-logs', 'woocommerce_uploads', 'gravity_forms', 'wpforms', 'wp-migrate-db', 'backwpup-*', 'updraft', 'ai1wm-backups'],
		];
		$filtered = apply_filters('lvmu_protected_patterns', $patterns);
		$cache    = is_array($filtered) ? $filtered + $patterns : $patterns;
		return $cache;
	}

	/** Extensions of PHP and script files that are flagged as suspicious. */
	public const SUSPICIOUS_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh'];

	/**
	 * Reason key when the file is protected (never deletable), otherwise null.
	 */
	public static function protected(string $relative_path): ?string {
		$path = strtolower(trim(str_replace('\\', '/', $relative_path), '/'));
		if ($path === '') {
			return null;
		}
		$p        = self::patterns();
		$segments = explode('/', $path);
		$basename = array_pop($segments);

		if (isset($p['names'][$basename])) {
			return (string) $p['names'][$basename];
		}
		if (str_starts_with($basename, '.')) {
			return 'hidden';
		}
		$ext = pathinfo($basename, PATHINFO_EXTENSION);
		if ($ext !== '' && isset($p['extensions'][$ext])) {
			return (string) $p['extensions'][$ext];
		}
		foreach ($segments as $segment) {
			foreach ((array) $p['folders'] as $glob) {
				if (fnmatch(strtolower((string) $glob), $segment)) {
					return 'plugin_data';
				}
			}
		}
		// Anything in a folder outside WordPress' year/month structure belongs to a plugin.
		// Files directly in the uploads root have no directory part and follow the normal rules.
		if ($segments && !preg_match('#^\d{4}/\d{2}/#', $path)) {
			return 'plugin_data';
		}
		return null;
	}

	/** True for PHP and script files that are not already protected. */
	public static function suspicious(string $relative_path): bool {
		$ext = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
		return $ext !== '' && in_array($ext, self::SUSPICIOUS_EXTENSIONS, true) && self::protected($relative_path) === null;
	}

	/**
	 * Flag columns for a path: ['file_flag' => ''|'protected'|'suspicious', 'flag_reason' => string].
	 *
	 * @return array{file_flag: string, flag_reason: string}
	 */
	public static function flags(string $relative_path): array {
		$reason = self::protected($relative_path);
		if ($reason !== null) {
			return ['file_flag' => 'protected', 'flag_reason' => $reason];
		}
		if (self::suspicious($relative_path)) {
			return ['file_flag' => 'suspicious', 'flag_reason' => ''];
		}
		return ['file_flag' => '', 'flag_reason' => ''];
	}

	public static function reason_label(string $key): string {
		switch ($key) {
			case 'config':
				return __('Server configuration', 'labelvier-media-usage');
			case 'guard':
				return __('Folder protection', 'labelvier-media-usage');
			case 'hidden':
				return __('Hidden file', 'labelvier-media-usage');
			case 'plugin_data':
				return __('Plugin data', 'labelvier-media-usage');
		}
		return $key;
	}
}
