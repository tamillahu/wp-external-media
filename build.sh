#!/bin/bash

PLUGIN_SLUG="wp-external-media"
ZIP_NAME="$PLUGIN_SLUG.zip"

echo "Creating deployable archive: $ZIP_NAME"

# Remove existing zip if it exists
if [ -f "$ZIP_NAME" ]; then
    rm "$ZIP_NAME"
fi

# Create zip file excluding development files
# We zip specific files/folders to ensure clean structure inside the zip
# or we exclude patterns.
# Best practice for WP plugins: folder inside zip matching slug.
# So we create a temporary directory

TEMP_DIR="build_tmp/$PLUGIN_SLUG"
mkdir -p "$TEMP_DIR"

# Copy files
echo "Copying files..."
cp external-media-plugin.php "$TEMP_DIR/"
cp README.md "$TEMP_DIR/"
cp -r includes "$TEMP_DIR/"

# Zip the directory
echo "Zipping..."
cd build_tmp
zip -r "../$ZIP_NAME" "$PLUGIN_SLUG"
cd ..

# Cleanup
rm -rf build_tmp

echo "Done! Archive created at: $(pwd)/$ZIP_NAME"
