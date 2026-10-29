<?php

namespace LabelvierMediaUsage;

/**
 * The mutually exclusive categories of indexed files, in display order.
 *
 * A file belongs to exactly one category: protected wins, then copy, then the file role.
 * Every category is defined here once (SQL, labels, view texts, visibility), so the admin,
 * the repository and WP-CLI never keep their own lists.
 */
enum Category: string {

	case Media     = 'media';
	case Original  = 'original';
	case Subsize   = 'subsize';
	case Webp      = 'webp';
	case Orphan    = 'orphan';
	case Copy      = 'copy';
	case Protected = 'protected';

	/**
	 * Whether the category is shown in the overview, the file list and WP-CLI summaries.
	 * Protected files (server configuration, dotfiles, plugin data) are not media and stay
	 * out of sight; they are still indexed so they are never deleted.
	 */
	public function is_visible(): bool {
		return $this !== self::Protected;
	}

	/**
	 * Categories that are shown, in display order.
	 *
	 * @return self[]
	 */
	public static function visible(): array {
		return array_values(array_filter(self::cases(), static fn(self $category): bool => $category->is_visible()));
	}

	/** The visible category for a request value, or null for unknown and hidden categories. */
	public static function from_request(string $value): ?self {
		$category = self::tryFrom($value);
		return $category?->is_visible() ? $category : null;
	}

	/** SQL condition for the files in this category. */
	public function sql(): string {
		return match ($this) {
			self::Protected => "file_flag = 'protected'",
			self::Copy      => "file_flag = 'copy'",
			default         => "(file_role = '" . $this->file_role() . "' AND file_flag NOT IN ('protected','copy'))",
		};
	}

	/** SQL expression that returns the category value of a row. */
	public static function case_sql(): string {
		return "CASE WHEN file_flag = 'protected' THEN 'protected' WHEN file_flag = 'copy' THEN 'copy' WHEN file_role = 'main' THEN 'media' ELSE file_role END";
	}

	/** The file role of a role based category ('main' for media items). */
	public function file_role(): string {
		return $this === self::Media ? 'main' : $this->value;
	}

	/** Key of the explanation shown above the file list for this category (see Admin\Page::view_info()). */
	public function view_key(): string {
		return match ($this) {
			self::Media     => 'main',
			self::Original  => 'originals',
			self::Subsize   => 'subsize',
			self::Webp      => 'webp',
			self::Orphan    => 'orphaned',
			self::Copy      => 'copies',
			self::Protected => 'protected',
		};
	}

	public function label(): string {
		return match ($this) {
			self::Media     => __('Media items', 'labelvier-media-usage'),
			self::Original  => __('Originals', 'labelvier-media-usage'),
			self::Subsize   => __('Generated sizes', 'labelvier-media-usage'),
			self::Webp      => __('WebP copies', 'labelvier-media-usage'),
			self::Orphan    => __('Loose files', 'labelvier-media-usage'),
			self::Copy      => __('Backups and copies', 'labelvier-media-usage'),
			self::Protected => __('Protected', 'labelvier-media-usage'),
		};
	}

	public function hint(): string {
		return match ($this) {
			self::Media     => __('The file your site shows, one per item in the Media Library', 'labelvier-media-usage'),
			self::Original  => __('Original upload next to the -scaled file, not visible in the Media Library', 'labelvier-media-usage'),
			self::Subsize   => __('Thumbnails and other sizes', 'labelvier-media-usage'),
			self::Webp      => __('Copies made by optimizer plugins', 'labelvier-media-usage'),
			self::Orphan    => __('Not linked to the Media Library', 'labelvier-media-usage'),
			self::Copy      => __('Copies of media and backups of optimization plugins. Check before you delete.', 'labelvier-media-usage'),
			self::Protected => __('Configuration and plugin data, never deletable', 'labelvier-media-usage'),
		};
	}
}
