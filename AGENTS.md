# Agent instructions

All UI strings must be translatable and fully translated to Dutch (nl_NL) in Label Vier tone; run `npm run i18n` and `npm run i18n:check` after changing strings.

## Release

The plugin has its own public repo (`labelvier/labelvier-media-usage`) that mirrors this folder. Releases are made from the starterkit root with `npm run release:plugin` (`-- --dry-run` first):

1. Bump `Version` in the header and `LVMU_VERSION` in `labelvier-media-usage.php`, add a `## [x.y.z] - date` section to `CHANGELOG.md`, commit.
2. The script checks version/changelog, runs `i18n:check` and `build`, requires a clean tree, pushes `git subtree split` to `main` of the plugin repo (env `PLUGIN_REPO`, `FORCE=1` for force), tags `vX.Y.Z` and runs `gh release create` with `labelvier-media-usage.zip` and the CHANGELOG section as release notes.
3. Installed sites update through Plugin Update Checker (`includes/Updater.php`): the release body is the changelog in the update screen, the first `.zip` release asset is installed. Keep exactly one zip asset per release.

Files that must stay in the repo: `.github/screenshot.png` (README; excluded from the zip by the `.git*` rsync exclude), `LICENSE`, `vendor/` (committed).
