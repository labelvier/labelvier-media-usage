# Changelog

All notable changes to this plugin are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Release this as 0.4.0: it adds features and changes what wp-admin and WP-CLI show.

### Added
- `wp lvmu size` command: size of the uploads folder, the database and the plugins, plus
  a fixed 300 MB allowance for core and themes (`lvmu_site_size_base_allowance` filter).
  With `--format=json` the sizes are in bytes, for the Label Vier dashboard. It always
  measures live and stores the result.
- Size of the site on the Media Usage page: media, database and plugins (sizes only), plus
  300 MB for WordPress and the theme. Measured in the background through Action Scheduler
  after every index run and when the last measurement is older than a day, so the page
  never waits for it.

### Changed
- wp-admin shows media only: images, video, audio and documents. Archives, database dumps,
  logs, code and other files in uploads are no longer shown in the overview, the type
  filter, the size per type, the file list or bulk deletes. The "PHP or script files"
  warning and the "To check" view are gone from wp-admin.
- Protected files (server configuration such as `.htaccess`, dotfiles and plugin data) are
  no longer shown anywhere. They are still never deleted.
- WP-CLI: `wp lvmu list` and `wp lvmu summary` still show all file types, but no longer
  include protected files (the `protected` category is gone from `summary --format=json`).

### Fixed
- Measuring the site size no longer fails on unreadable folders or files that disappear
  during the measurement.

## [0.3.0] - 2026-10-08

### Added
- Automatic updates from GitHub releases (with the changelog in the "View version details"
  screen). Disable with the `lvmu_update_checker_enabled` filter.
- Public README with screenshot and a LICENSE file (GPL-2.0-or-later).

## [0.2.2] - 2026-10-08

### Fixed
- Delete jobs waiting for an earlier job showed as "stalled"; they now show as queued
  ("starts when the job before it is done") and their stored status is corrected.

## [0.2.1] - 2026-10-08

### Fixed
- Delete jobs got stuck on "running" when the browser closed or reloaded while a status
  poll was running the job; jobs could also stay "scheduled" without ever starting. Jobs
  now keep running when the browser goes away, resume from the remaining files and a
  watchdog re-queues stalled jobs (failing them after 3 attempts).
- Delete job counters no longer count files that were already gone.

### Added
- Delete job progress per file.
- "Resume now" and "Cancel" for stalled delete jobs.
- `wp lvmu summary` shows the same categories as the admin overview.

## [0.2.0] - 2026-10-07

### Added
- Overview table with one category per file (media items, originals, generated sizes,
  WebP copies, loose files, backups and copies, protected) and total, used and unused per
  category, all linking to the file list.
- Category "Backups and copies": media copies outside the year/month structure (nested
  `uploads/`, `sites/N/`, ShortPixel backups), deletable when unused with an extra
  confirmation.
- Protected files: server config, dotfiles, folder guards and plugin folders outside the
  year/month structure are listed but never deletable.
- PHP and script files in uploads are flagged "Check this file" and need an extra
  confirmation to delete.
- Bulk deletes are scheduled with Action Scheduler, with a jobs box at the top of the page.
- Explanation per view, view counts with size, and links that jump to the file list.
- Dutch translation, `npm run i18n` and `npm run i18n:check`; the build refuses
  incomplete translations.

### Changed
- Originals (pre `-scaled`) only count as used when something links to them directly.
- Files marked as used are locked in the list until "Also delete files marked as used" is
  checked; "select all matching" only counts files that can be deleted.
- `wp lvmu summary` shows the same categories as the admin overview.

### Fixed
- Resolving usage on large sites: stale table statistics made one query run for over 15
  minutes. Tables are analyzed first, the join order is fixed and queries time out after
  120 seconds.
- Progress bar no longer jumps back and forth; the status icon spins while indexing.
- Browser cache: asset versions include the file time.

### Security
- Deleting only works after a complete index, re-checks usage right before deleting and
  refuses files shared with other Media Library items.

## [0.1.0] - 2026-10-07

### Added
- Index of every file in `wp-content/uploads`, linked to Media Library items (main,
  original, generated sizes, WebP copies, loose files).
- Database-wide search for references (paths, file names, attachment ids, ACF fields,
  page builders) to decide whether a file is used.
- Media > Media Usage page with filters and bulk delete.
- Nightly index at 03:00 via Action Scheduler and WP-CLI commands (`wp lvmu`).
