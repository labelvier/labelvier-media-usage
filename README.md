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
Media Library item working; you lose the full size upload (used for regenerating
thumbnails at a larger size and in the image editor). Revisions, trashed posts and
"uploaded to" do not count as usage.

Not detected: references in plugin code, external sites or newsletters. Check before
deleting files that might be linked from outside the site.

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
