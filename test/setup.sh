#!/bin/bash

# Wait for database to be ready
echo "Waiting for WordPress to be ready..."
sleep 20

# Install WordPress
docker compose run --rm cli wp core install --url=http://localhost:8080 --title="External Media Test" --admin_user=admin --admin_password=password --admin_email=test@example.com --skip-email

# Generate .htaccess with rewrite rules
docker compose run --rm cli wp rewrite structure '/%postname%/' --hard

# Patch .htaccess for Basic Auth and Verify
docker compose exec -T wordpress sh -c 'echo "SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1" >> /var/www/html/.htaccess && cat /var/www/html/.htaccess'

# Activate plugin
docker compose run --rm cli wp plugin activate wp-external-media

# Install WooCommerce (needed for functionality testing)
echo "Installing WooCommerce..."
docker compose run --rm --user root cli wp plugin install woocommerce --activate --allow-root

# Fix Uploads Directory Permissions for Test Environment
echo "Fixing uploads directory permissions..."
docker compose exec -u root wordpress sh -c "mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content/uploads"

# Restart WordPress to ensure new plugins are loaded
docker compose restart wordpress
# Wait for it to be ready again
echo "Waiting for WordPress to restart..."
sleep 10

# Create Application Password
echo "Creating Application Password..."
APP_PASSWORD=$(docker compose run --rm cli wp user application-password create admin "Test API Access" --porcelain)

echo "Setup Complete."
echo "URL: http://localhost:8080"
echo "Admin User: admin"
echo "Admin Password: password"
echo "Application Password: $APP_PASSWORD"
echo "Use the Application Password for Basic Auth."
