#!/bin/bash
# Build the plugin zip for GitHub release.
# The zip must contain folder "otm-update-logger" with the plugin files inside.
# Run from project root.

set -e
VERSION="${1:-1.0.0}"
OUTPUT="otm-update-logger-${VERSION}.zip"
BUILD_DIR="build-otm-update-logger"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/otm-update-logger"

cp otm-update-logger.php uninstall.php "$BUILD_DIR/otm-update-logger/"
cp -r plugin-update-checker "$BUILD_DIR/otm-update-logger/"

cd "$BUILD_DIR"
zip -r "../$OUTPUT" otm-update-logger
cd ..
rm -rf "$BUILD_DIR"

echo "Created $OUTPUT"
echo "Upload this file as an asset to your GitHub release (tag: v${VERSION})"
