# Label Vier Media Usage

Indexes every file in `wp-content/uploads`, links files to Media Library items and checks
the whole database for references, so you can find and bulk delete unused and orphaned
files (including zips, sql dumps and logs).

## Usage

**Media → Media Usage** in wp-admin.

- **Index now** starts an index run through Action Scheduler. The page shows progress
  and reloads when done. While the page is open it also processes the queue itself, so
  runs finish on hosts where the Action Scheduler loopback request is blocked.
- A run is scheduled every night at 03:00 (site time).
- Filter on type, role, usage, orphaned and size. Bulk delete works on the selection or
  on all files matching the current filters. Files marked as used are only deleted when
  "Also delete files marked as used" is checked.
- Bulk deletes run in the background: they are scheduled through Action Scheduler and
  listed at the top of the page, with a **Cancel** link as long as a job has not started.
  Running jobs show their progress, finished jobs show the result (deleted, freed, skipped)
  and can be dismissed. A job waits while an index run is active. Files in a scheduled job
  are marked and cannot be selected again. Deleting a single file from its row stays immediate.

### File roles

| Role | Meaning |
|---|---|
| main | The attached file of a Media Library item (often `-scaled`) |
| original | `original_image`, the full size upload WordPress keeps next to `-scaled` |
| subsize | Generated image sizes |
| webp | `.webp` copies made by optimizer plugins (`foo.jpg.webp`, `foo.webp`) |
| orphan | Not part of any Media Library item |

### What counts as "used"

A file is used when its path or filename is referenced anywhere in the database (all
tables with the WordPress prefix), or when its Media Library item is referenced by id
(blocks, `wp-image-123`, galleries, featured image, ACF image/file/gallery fields,
site icon/logo, media-ish option and meta keys). All files of a used Media Library item
count as used, except the original: WordPress serves the `-scaled` file, so an original
is only used when it is referenced directly. Deleting an unused original keeps the
Media Library item working: regenerating thumbnails falls back to the `-scaled` file,
the image editor already works on the `-scaled` file, and the frontend never uses the
original. What you lose is the full resolution file itself (e.g. for downloads or
print) and the ability to generate sizes larger than the `-scaled` file (default max
2560px on the longest side). Revisions, trashed posts and "uploaded to" do not count
as usage.

Not detected: references in plugin code, external sites or newsletters. Check before
deleting files that might be linked from outside the site.

## Protected and suspicious files

Some files can never be deleted, not even with "Also delete files marked as used". They
stay in the list with their size and a "Protected" badge, but cannot be selected:

- Server configuration: `.htaccess`, `.htpasswd`, `.user.ini`, `php.ini`, `web.config`
  and files with the extension `ini`, `conf` or `config`.
- Folder protection: `index.php`, `index.html`, `index.htm`.
- Hidden files: every file whose name starts with a dot (such as `.DS_Store`).
- Plugin data: every file in a folder outside the WordPress `YYYY/MM` structure (for
  example `wc-logs/`, `gravity_forms/`, `elementor/css/`, `some-plugin-cache/`). These
  files belong to plugins, clean them up through the plugin itself. Files directly in the
  uploads root follow the normal rules.

PHP and script files (`php`, `php3`-`php8`, `phtml`, `phar`, `cgi`, `pl`, `py`, `sh`) are
not protected but flagged "Check this file": in uploads they can be a sign of a hacked
site. They can be deleted under the normal rules, after an extra warning in the confirm
dialog. The views "Protected" and "To check" list both groups.
Files in copy folders are not protected, see below.

Sites can extend the rules in code with the `lvmu_protected_patterns` filter (keys
`names`, `extensions` and `folders`). The rules live in `includes/FileRules.php`.

## Backups and copies

Some folders outside the `YYYY/MM` structure are not plugin data but copies of media. They
get the flag `copy` (category "Backups and copies" in the overview, badge "Copy" or
"Back-up") and can be deleted when unused, after an extra confirmation. Files that are
still referenced (old URLs) stay locked unless "Also delete files marked as used" is
checked. Files with an unknown usage are never deleted.

- `media_copy`: a path that does not start with `YYYY/MM/` but contains a `YYYY/MM/file`
  segment. Regex: `^(?!\d{4}/\d{2}/)(.+/)\d{4}/\d{2}/[^/]+$`. Examples:
  `uploads/2022/03/x.jpg` (nested uploads after a migration),
  `ShortpixelBackups/wp-content/uploads/2022/03/x.jpg`, `sites/2/2020/01/x.jpg`,
  `old-uploads/2019/05/x.jpg`. On a multisite, `sites/N/` is never treated as a copy.
- `optimizer_backup`: any folder named like an entry of the folder list, at any depth.
  Default list: `ShortpixelBackups` (source: ShortPixel knowledge base, "Where is the
  backup folder located": `wp-content/uploads/ShortpixelBackups`). Not included because the
  name could not be confirmed as a distinct uploads folder: Imagify stores its backups in
  `uploads/backup/YYYY/MM` (covered by the `media_copy` regex), EWWW keeps them in
  `wp-content/ewww` (outside uploads) and for Smush no folder name was found.
- Never a copy: protected names (`.htaccess`, `index.php`, ini/conf, dotfiles) and the
  protected plugin folders (`updraft`, `ai1wm-backups`, `backwpup-*`, `wc-logs`,
  `woocommerce_uploads`, `gravity_forms`, `wpforms`, `wp-migrate-db`).
- Precedence: protected by name/extension/dotfile, then copy, then plugin folder rule, then
  suspicious. Copies with a PHP or script extension also show the "Check this file" badge and
  count in the "To check" view.
- The flags are recomputed for all rows when the database version changes (now 3).

Filters: `lvmu_copy_folders` (array of folder names, fnmatch globs allowed) and
`lvmu_protected_patterns` (see above). The rules live in `includes/FileRules.php`.

## WP-CLI

```bash
wp lvmu index --sync          # run a full index inline
wp lvmu index                 # queue a run in Action Scheduler
wp lvmu status
wp lvmu summary
wp lvmu list --orphaned --usage_status=unused --limit=50
```

## Development

Action Scheduler is bundled with composer in `vendor/` (committed).

Build: `npm run build` in the repository root copies a clean plugin to
`build/labelvier-media-usage/` and creates `build/labelvier-media-usage-<version>.zip`
(version from the plugin header), ready for Plugins → Add New → Upload. The theme build
moved to `npm run build:theme` (used by the deploy scripts).

Test data:
`bin/seed-media-usage.sh` in the repository root, expected results in
`test/fixtures/EXPECTED.md`.

## Translations

All UI text must be translatable (text domain `labelvier-media-usage`) and fully
translated to Dutch (nl_NL). The source language is English.

1. After changing strings, run `npm run i18n`. It regenerates the `.pot`, merges it
   into the `.po` and rebuilds the `.mo` and `.l10n.php` (needs the Docker container).
2. Fill in the empty Dutch `msgstr` entries in `languages/labelvier-media-usage-nl_NL.po`
   in Label Vier tone (je/jij), and remove any `fuzzy` flags. Run `npm run i18n` again to rebuild the `.mo`.
3. Run `npm run i18n:check`. It fails on new msgids missing from the `.po`, empty or
   fuzzy translations and a `.mo` older than the `.po`.

`npm run build` refuses to build when translations are incomplete
(override with `SKIP_I18N_CHECK=1`).
