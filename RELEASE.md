# Releasing OTM Update Logger

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
