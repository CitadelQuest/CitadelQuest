#!/bin/bash

# Exit on error
set -e

VERSION="v0.1.4-alpha"
RELEASE_DIR="release-build"
RELEASE_FILE="citadelquest-prebuilt-${VERSION}.zip"

echo "Building CitadelQuest Release A (Pre-built Package) ${VERSION}"
echo "========================================================"

# Create fresh release directory
rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"

# Build webpack assets
echo "Building webpack assets..."
npm install
npm run build

# Create template database
echo "Creating template database..."
rm -rf var/template.db
APP_ENV=prod DATABASE_URL="sqlite:///%kernel.project_dir%/var/template.db" php bin/console doctrine:schema:create

# Copy required files to release directory
echo "Copying files to release directory..."
cp -r \
    assets \
    bin \
    config \
    migrations \
    public \
    src \
    templates \
    vendor \
    var \
    .env \
    composer.json \
    composer.lock \
    package.json \
    package-lock.json \
    symfony.lock \
    webpack.config.js \
    README.md \
    "$RELEASE_DIR/"

# Remove development files
rm -f "$RELEASE_DIR/public/install.php"
rm -f "$RELEASE_DIR/public/install.htaccess"
rm -f "$RELEASE_DIR/.env.local"
rm -f "$RELEASE_DIR/.env.dev"

# Create release zip
echo "Creating release archive..."
cd "$RELEASE_DIR"
zip -r "../$RELEASE_FILE" ./*

cd ..
rm -rf "$RELEASE_DIR"

echo "========================================================"
echo "Release A (Pre-built Package) created successfully!"
echo "File: $RELEASE_FILE"
echo "Size: $(du -h "$RELEASE_FILE" | cut -f1)"
