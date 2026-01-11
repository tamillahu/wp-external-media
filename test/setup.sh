#!/bin/bash

# Wait for database to be ready
echo "Waiting for WordPress to be ready..."
sleep 20

# Install WordPress
docker compose run --rm cli wp core install --url=http://localhost:8080 --title="External Media Test" --admin_user=admin --admin_password=password --admin_email=test@example.com --skip-email

# Patch .htaccess for Basic Auth
docker compose exec -T wordpress sh -c 'echo "SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1" >> /var/www/html/.htaccess'

# Patch .htaccess for Basic Auth
docker compose exec -T wordpress sh -c 'echo "SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1" >> /var/www/html/.htaccess'

# Activate plugin
docker compose run --rm cli wp plugin activate wp-external-media

# Create Application Password
echo "Creating Application Password..."
APP_PASSWORD=$(docker compose run --rm cli wp user application-password create admin "Test API Access" --porcelain)

echo "Setup Complete."
echo "URL: http://localhost:8080"
echo "Admin User: admin"
echo "Admin Password: password"
echo "Application Password: $APP_PASSWORD"
echo "Use the Application Password for Basic Auth."
