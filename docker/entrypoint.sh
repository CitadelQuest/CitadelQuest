#!/bin/bash
set -e

echo "=== CitadelQuest Docker Entrypoint ==="

# Check if CitadelQuest is already installed
if [ -f "/var/www/html/config/bundles.php" ] && [ -f "/var/www/html/vendor/autoload.php" ]; then
    echo "CitadelQuest is already installed."
else
    echo "CitadelQuest not found. Running installer..."
    
    echo "Detecting latest CitadelQuest version..."
    cd /var/www/html
    
    # Get the latest version from GitHub releases (follows redirect to get tag)
    LATEST_URL=$(curl -sI "https://github.com/CitadelQuest/CitadelQuest/releases/latest" | grep -i "^location:" | sed 's/\r$//' | awk '{print $2}')
    VERSION=$(echo "$LATEST_URL" | grep -oP 'v[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9-]+)?')
    
    if [ -z "$VERSION" ]; then
        echo "ERROR: Could not detect latest version from GitHub"
        exit 1
    fi
    
    echo "Latest version: $VERSION"
    
    # Download the pre-built release
    RELEASE_URL="https://github.com/CitadelQuest/CitadelQuest/releases/download/${VERSION}/citadelquest-prebuilt-${VERSION}.zip"
    echo "Downloading from: $RELEASE_URL"
    
    curl -L -o release.zip "$RELEASE_URL"
    
    echo "Extracting release..."
    unzip -o release.zip
    rm release.zip
    
    echo "Configuring environment..."
    # Create .env file
    cat > .env << EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 16)
DATABASE_URL="sqlite:///%kernel.project_dir%/var/main.db"
EOF
    
    echo "Setting permissions..."
    mkdir -p var/cache var/log var/tmp var/user_databases var/user_backups var/data
    chown -R www-data:www-data /var/www/html
    chmod -R 755 /var/www/html
    chmod -R 777 var/
    
    # Set database permissions if it exists
    if [ -f "var/main.db" ]; then
        chmod 666 var/main.db
    fi
    
    echo "Installation complete!"
fi

# Ensure proper permissions on every start (in case of volume mounts)
echo "Setting up permissions..."
mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/var/tmp
mkdir -p /var/www/html/var/user_databases /var/www/html/var/user_backups /var/www/html/var/data
chown -R www-data:www-data /var/www/html/var
chmod -R 775 /var/www/html/var

# Clear and warm up cache
if [ -f "/var/www/html/bin/console" ]; then
    echo "Warming up cache..."
    php /var/www/html/bin/console cache:clear --no-interaction --env=prod 2>/dev/null || true
    php /var/www/html/bin/console cache:warmup --no-interaction --env=prod 2>/dev/null || true
    chown -R www-data:www-data /var/www/html/var
fi

echo "=== Starting Apache ==="
exec apache2-foreground
