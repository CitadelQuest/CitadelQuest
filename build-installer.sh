#!/bin/bash

# Exit on error
set -e

VERSION=$(php get-version.php)
RELEASE_DIR="installer-build"
RELEASE_FILE="citadelquest-installer-${VERSION}.zip"

echo "Building CitadelQuest Release B (Installation Package) ${VERSION}"
echo "========================================================"

# Create fresh release directory
rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"

# Inject version into .install and save it as install.php
echo "Preparing install.php with version ${VERSION}..."
sed "s/version = 'v[0-9]\+\.[0-9]\+\.[0-9]\+-[a-z]\+'/version = '${VERSION}'/" public/.install > "${RELEASE_DIR}/install.php"

# Create release zip
echo "Creating release archive..."
cd "$RELEASE_DIR"
zip -r "../$RELEASE_FILE" ./*

cd ..
rm -rf "$RELEASE_DIR"

echo "========================================================"
echo "Release B (Installation Package) created successfully!"
echo "File: $RELEASE_FILE"
echo "Size: $(du -h "$RELEASE_FILE" | cut -f1)"

echo -e "\nInstallation Instructions:"
echo "1. Upload install.php to your web server's public directory"
echo "2. Access install.php through your web browser"
echo "3. The installer will download and set up CitadelQuest automatically"
echo "4. Installation files will be removed after successful installation"
