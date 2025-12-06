# CitadelQuest Docker Image
# Minimal base with PHP 8.2 + Apache, uses install.php for setup
FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    curl \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring exif pcntl bcmath gd zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Set global ServerName to prevent warnings
RUN echo "ServerName citadelquest.local" >> /etc/apache2/apache2.conf

# Copy Apache configuration
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set working directory
WORKDIR /var/www/html

# Create necessary directories with proper permissions
RUN mkdir -p /var/www/html/public \
    && mkdir -p /var/www/html/var \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Environment variables
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Expose port 80
EXPOSE 80

# Use custom entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
