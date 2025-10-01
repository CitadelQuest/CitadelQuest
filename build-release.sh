#!/bin/bash

# Exit on error
set -e

VERSION=$(php get-version.php)
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
    translations \
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
rm -f "$RELEASE_DIR/public/.install"
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
# Create and migrate main database
echo "Creating and migrating main database..."
php -r '
$dbPath = __DIR__ . "/var/main.db";
$pdo = new PDO("sqlite:" . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create doctrine_migration_versions table
$pdo->exec("CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
    version VARCHAR(191) NOT NULL PRIMARY KEY,
    executed_at DATETIME DEFAULT NULL,
    execution_time INTEGER DEFAULT NULL
)");

// Get all migration files
$migrationsDir = __DIR__ . "/migrations";
$migrations = [];
foreach (new DirectoryIterator($migrationsDir) as $file) {
    if ($file->isDot() || $file->isDir()) continue;
    if (preg_match("/^Version(\d+)\.php$/", $file->getFilename(), $matches)) {
        $migrations[$matches[1]] = $file->getPathname();
    }
}
ksort($migrations);

// Run migrations
foreach ($migrations as $version => $file) {
    $migrationVersion = "DoctrineMigrations\\Version" . $version;
    
    // Check if already applied
    $stmt = $pdo->prepare("SELECT version FROM doctrine_migration_versions WHERE version = ?");
    $stmt->execute([$migrationVersion]);
    if ($stmt->fetch()) {
        echo "Migration Version{$version} already applied\n";
        continue;
    }
    
    echo "Running migration: Version{$version}\n";
    require_once $file;
    $migration = new $migrationVersion();
    
    $pdo->beginTransaction();
    try {
        $startTime = microtime(true);
        $migration->up($pdo);
        $executionTime = round((microtime(true) - $startTime) * 1000);
        
        $stmt = $pdo->prepare("INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES (?, datetime(\"now\"), ?)");
        $stmt->execute([$migrationVersion, $executionTime]);
        
        $pdo->commit();
        echo "✓ Migration Version{$version} completed ({$executionTime}ms)\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Migration Version{$version} failed: " . $e->getMessage());
    }
}
echo "✓ All migrations completed\n";
'

# Create release zip
echo "Creating release archive..."
zip -r "../$RELEASE_FILE" .

cd ..
rm -rf "$RELEASE_DIR"

echo "========================================================"
echo "Release A (Pre-built Package) created successfully!"
echo "File: $RELEASE_FILE"
echo "Size: $(du -h "$RELEASE_FILE" | cut -f1)"
