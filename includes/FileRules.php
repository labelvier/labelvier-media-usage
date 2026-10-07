<?php

namespace LabelvierMediaUsage;

/**
 * Hard-coded rules for files that must never be deleted (protected) and files that
 * deserve a closer look (suspicious). Single source of truth for Deleter, the index
 * flags and the list table. All matching is case-insensitive.
 */
class FileRules {

	public const REASONS = ['config', 'guard', 'hidden', 'plugin_data'];

	/** Reasons for the 'copy' flag. */
	public const COPY_REASONS = ['media_copy', 'optimizer_backup'];

	/** Path outside YYYY/MM that contains a YYYY/MM/file segment: a copy of the media structure. */
	private const MEDIA_COPY_REGEX = '#^(?!\d{4}/\d{2}/)(.+/)\d{4}/\d{2}/[^/]+$#';

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
	 * Folders of optimizer plugins that keep back-ups of original images (fnmatch glob, any depth).
	 * Confirmed from plugin documentation: ShortPixel stores them in uploads/ShortpixelBackups.
	 * Extend with the `lvmu_copy_folders` filter.
	 *
	 * @return string[]
	 */
	public static function copy_folders(): array {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$filtered = apply_filters('lvmu_copy_folders', ['ShortpixelBackups']);
		$cache    = is_array($filtered) ? array_values(array_map('strval', $filtered)) : ['ShortpixelBackups'];
		return $cache;
	}

	/** Normalised path: lowercase, forward slashes, no leading or trailing slash. */
	private static function normalize(string $relative_path): string {
		return strtolower(trim(str_replace('\\', '/', $relative_path), '/'));
	}

	/** Reason for protection by file name, extension or dotfile rule, otherwise null. */
	private static function name_reason(string $basename): ?string {
		$p = self::patterns();
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
		return null;
	}

	/** True when a folder segment matches one of the explicitly protected plugin folders. */
	private static function in_protected_folder(array $segments): bool {
		$p = self::patterns();
		foreach ($segments as $segment) {
			foreach ((array) $p['folders'] as $glob) {
				if (fnmatch(strtolower((string) $glob), $segment)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Reason key ('media_copy' or 'optimizer_backup') when the path is a copy or back-up of
	 * media that is not protected by name or by an explicitly protected plugin folder.
	 */
	public static function copy_reason(string $relative_path): ?string {
		$path = self::normalize($relative_path);
		if ($path === '') {
			return null;
		}
		$segments = explode('/', $path);
		$basename = array_pop($segments);
		if (!$segments || self::name_reason($basename) !== null || self::in_protected_folder($segments)) {
			return null;
		}
		foreach ($segments as $segment) {
			foreach (self::copy_folders() as $glob) {
				if (fnmatch(strtolower($glob), $segment)) {
					return 'optimizer_backup';
				}
			}
		}
		if (preg_match(self::MEDIA_COPY_REGEX, $path)) {
			if (function_exists('is_multisite') && is_multisite() && preg_match('#^sites/\d+/#', $path)) {
				return null;
			}
			return 'media_copy';
		}
		return null;
	}

	/**
	 * Reason key when the file is protected (never deletable), otherwise null.
	 * Precedence: name/extension/dotfile rules, then copies (not protected), then plugin folders.
	 */
	public static function protected(string $relative_path): ?string {
		$path = self::normalize($relative_path);
		if ($path === '') {
			return null;
		}
		$segments = explode('/', $path);
		$basename = array_pop($segments);

		$reason = self::name_reason($basename);
		if ($reason !== null) {
			return $reason;
		}
		if (self::copy_reason($path) !== null) {
			return null;
		}
		if (self::in_protected_folder($segments)) {
			return 'plugin_data';
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
	 * Flag columns for a path: ['file_flag' => ''|'protected'|'copy'|'suspicious', 'flag_reason' => string].
	 * Precedence: protected, copy, suspicious.
	 *
	 * @return array{file_flag: string, flag_reason: string}
	 */
	public static function flags(string $relative_path): array {
		$reason = self::protected($relative_path);
		if ($reason !== null) {
			return ['file_flag' => 'protected', 'flag_reason' => $reason];
		}
		$copy = self::copy_reason($relative_path);
		if ($copy !== null) {
			return ['file_flag' => 'copy', 'flag_reason' => $copy];
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

	public static function copy_label(string $reason): string {
		return $reason === 'optimizer_backup' ? __('Back-up', 'labelvier-media-usage') : __('Copy', 'labelvier-media-usage');
	}

	public static function copy_tip(string $reason): string {
		return $reason === 'optimizer_backup'
			? __('Back-up of an optimization plugin. Check that you no longer need to restore originals before you delete it.', 'labelvier-media-usage')
			: __('Copy of a media file in a folder outside the year/month structure, for example after a migration. Check that nothing still uses it before you delete it.', 'labelvier-media-usage');
	}
}
