# Releasing OTM Care Plan Assistant

This plugin uses [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) to deliver updates via GitHub Releases. Client sites will see "Update available" in the WordPress Plugins screen.

## Setup (one-time)

1. **GitHub repo:** `https://github.com/oldtownmedia/otm-care-plan-assistant`

2. **Update the repo URL** in `otm-update-logger.php` only if you move the repo:
   ```php
   define( 'OTM_UL_GITHUB_REPO', 'https://github.com/oldtownmedia/otm-care-plan-assistant/' );
   ```

3. **Ensure the plugin folder name is `otm-update-logger`** when installed. The build script creates a zip with this structure.

## Creating a release

1. Update the version in `otm-update-logger.php` (header and `OTM_UL_VERSION`).

2. Build the zip:
   ```bash
   chmod +x build-release.sh
   ./build-release.sh 1.0.1
   ```

3. On GitHub: **Releases** → **Create a new release**:
   - Tag: `v1.0.1` (must match version, with `v` prefix)
   - Attach `otm-update-logger-1.0.1.zip` as an asset

4. Publish the release.

## How it works

- PUC checks the GitHub Releases API for the latest tag.
- When a newer version exists, WordPress shows the standard update notice.
- The zip is downloaded from the release asset URL.
- No WordPress.org listing required.

## Troubleshooting: "Could not determine if updates are available"

**404 errors** usually mean one of:

1. **Wrong repo URL** – Confirm the repo exists at `https://github.com/oldtownmedia/otm-care-plan-assistant` (or your actual org/user). Update `OTM_UL_GITHUB_REPO` if the URL is different.

2. **Private repo** – The GitHub API returns 404 for private repos without auth. Add a [Personal Access Token](https://github.com/settings/tokens) with `repo` scope and set it:
   ```php
   define( 'OTM_UL_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
   ```
   Do not commit the token to the repo; use a config or environment variable.

3. **Wrong default branch** – If your repo uses `master` instead of `main`, change `setBranch( 'main' )` to `setBranch( 'master' )` in the plugin.

4. **No release yet** – Create at least one release with a tag like `v1.0.0` and attach the plugin zip as an asset.
