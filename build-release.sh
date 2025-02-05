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

# Clear Symfony cache
echo "Clearing Symfony cache..."
php bin/console cache:clear

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
rm -f "$RELEASE_DIR/.env.local"
rm -f "$RELEASE_DIR/.env.dev"

# Clear cache dir in release
echo "Clearing /var dir in release..."
rm -rf "$RELEASE_DIR/var"

# Create empty dir for user_databases in release
echo "Creating /var/user_databases, /var/log, /var/cache/prod dir in release..."
mkdir -p "$RELEASE_DIR/var/user_databases"
mkdir -p "$RELEASE_DIR/var/log"
mkdir -p "$RELEASE_DIR/var/cache/prod"

cd "$RELEASE_DIR"
# Create main database
echo "Creating main database..."
APP_ENV=prod DATABASE_URL="sqlite:///%kernel.project_dir%/var/main.db" php bin/console doctrine:schema:create

# Create release zip
echo "Creating release archive..."
zip -r "../$RELEASE_FILE" .

cd ..
rm -rf "$RELEASE_DIR"

echo "========================================================"
echo "Release A (Pre-built Package) created successfully!"
echo "File: $RELEASE_FILE"
echo "Size: $(du -h "$RELEASE_FILE" | cut -f1)"
