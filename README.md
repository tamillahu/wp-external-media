# External Media for WordPress

A lightweight WordPress plugin to import and manage external media files via API. It stores metadata as native WordPress attachments but serves the media files directly from external URLs, saving local storage space.

## Features

- **Authenticated REST API**: Secure endpoint for importing media metadata.
- **CDN/External URL Support**: Media items use the direct external URLs provided in the feed.
- **Native Integration**: Works seamlessly with the WordPress Media Library.
- **Sync Capability**: Supports idempotent updates (add, update, delete).
- **Maintenance Mode**: Automatically enables maintenance mode during import operations to ensure data integrity.

## Usage

### 1. Installation

1.  Download `wp-external-media.zip`.
2.  Upload to your WordPress Plugins directory or use the "Add New" button in the admin panel.
3.  Activate the plugin.

### 2. Authentication

The plugin uses standard **WordPress Application Passwords** (Basic Auth) for API access.

1.  Go to **Users > Profile** (or edit an Administrator user).
2.  Scroll down to **Application Passwords**.
3.  Create a new password (e.g., named "External Media Import").
4.  Use this password in your API client.

### 3. API Documentation

**Endpoint:** `POST /wp-json/external-media/v1/import`

**Headers:**
- `Content-Type: application/json`
- `Authorization: Basic <base64_encoded_credentials>` (User:Password)

> **Note**: The authenticated user must have `upload_files` capability (Author/Editor or higher).

### 4. JSON Data Structure

The API expects a JSON array of media objects.

**Example Payload:**

```json
[
  {
    "id": "ext-101",
    "title": "Sunset over Mountains",
    "mime_type": "image/jpeg",
    "urls": {
      "full": "https://cdn.example.com/images/sunset-full.jpg",
      "large": "https://cdn.example.com/images/sunset-large.jpg",
      "medium": "https://cdn.example.com/images/sunset-medium.jpg",
      "thumbnail": "https://cdn.example.com/images/sunset-thumb.jpg"
    },
    "metadata": {
      "width": 1920,
      "height": 1080,
      "file": "sunset-full.jpg",
      "sizes": {
        "medium": {
          "file": "sunset-medium.jpg",
          "width": 300,
          "height": 169,
          "mime-type": "image/jpeg"
        },
        "thumbnail": {
          "file": "sunset-thumb.jpg",
          "width": 150,
          "height": 150,
          "mime-type": "image/jpeg"
        }
      }
    }
  }
]
```

**Response Structure:**

The API returns a JSON object summarizing the synchronization results:

```json
{
  "success": true,
  "message": "Import completed successfully.",
  "results": {
    "created": ["ext-101", ...],
    "updated": ["ext-205", ...],
    "deleted": ["ext-099", ...],
    "unchanged": ["ext-300", ...]
  }
}
```

- `created`: List of new External IDs successfully imported.
- `updated`: List of existing External IDs that were updated (title, URLs, or metadata changed).
- `deleted`: List of External IDs that were removed from WordPress because they were missing from the feed.
- `unchanged`: List of existing External IDs that were present in the feed but required no updates (idempotent).

**Field Descriptions:**

| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `id` | String | **Yes** | Unique identifier from the external system. Used for sync and deduplication. |
| `urls` | Object | **Yes** | Key-value pairs of size names and URLs. **The first URL in this object is expected to be the highest resolution (original) version**, as it is used to determine the filename. Standard WP keys: `full`, `large`, `medium`, `thumbnail`. |
| `title` | String | No | The title of the attachment in WordPress. Defaults to "External Media [ID]". |
| `mime_type` | String | No | MIME type of the file. Defaults to `application/octet-stream`. |
| `metadata` | Object | No | Standard WordPress attachment metadata (width, height, sizes, etc.). Stored in `_wp_attachment_metadata` to allow plugins to recognize available sizes. |
| `metadata` | Object | No | Standard WordPress attachment metadata (width, height, sizes, etc.). Stored in `_wp_attachment_metadata` to allow plugins to recognize available sizes. |

### 5. Large File Imports (Local Drop Zone)

For very large libraries that exceed the server's HTTP request body limit (Status 413), you can use the **Local Drop Zone** method.

1.  **Upload JSON**: securely upload your JSON import file to the designated import directory on the server:
    -   Path: `wp-content/uploads/external-media-imports/`
2.  **Trigger Import**: Call the API with the `local_file` parameter (filename only).

**Example Payload:**

```json
{
  "local_file": "my-large-library.json"
}
```

The plugin will read the file from the import directory, process it, and **automatically delete it** upon completion.

#### Security Recommendation

To prevent unauthorized access to your import files, create a `.htaccess` file in the import directory (`wp-content/uploads/external-media-imports/.htaccess`) with the following content:

```apache
# Deny all direct access to files in this directory
Order Deny,Allow
Deny from all
```
### 5. Synchronization Logic

The import process acts as a full synchronization of the external media state:

1.  **Create**: New `id`s in the feed are created as attachments.
2.  **Update**: Existing `id`s are updated with new titles, URLs, and metadata.
3.  **Delete**: Any previously imported external media items that are **missing** from the current feed will be **permanently deleted** from WordPress.

> **Warning**: Ensure your feed is complete. Omitted items will be removed.

### 6. Retrieve Registered Image Sizes

**Endpoint:** `GET /wp-json/external-media/v1/image-sizes`

**Headers:**
- `Authorization: Basic <base64_encoded_credentials>` (User:Password)

**Response Structure:**

Returns a JSON object where keys are image size names (e.g., `thumbnail`, `medium`) and values describe dimensions and cropping.

```json
{
  "thumbnail": {
    "width": 150,
    "height": 150,
    "crop": true
  },
  "medium": {
    "width": 300,
    "height": 300,
    "crop": false
  },
  "large": {
    "width": 1024,
    "height": 1024,
    "crop": false
  }
}
```

### 7. Import WooCommerce Products (CSV)

**Endpoint:** `POST /wp-json/external-media/v1/import-products`

This endpoint allows you to import and synchronize WooCommerce products using a CSV file. It performs a **dual-run import**:
1.  **Create:** Adds new products from the CSV.
2.  **Update:** Updates existing products (and newly created ones) with the latest data from the CSV.

**Headers:**
-   `Authorization: Basic <base64_encoded_credentials>`
-   `Content-Type: multipart/form-data`

> **Note**: The authenticated user must have `manage_woocommerce` capability (Shop Manager or Administrator).

**Parameters:**
-   `file`: The CSV file to import (Multipart).
-   `local_file`: (Optional) Filename of a CSV file already uploaded to `wp-content/uploads/external-media-imports/` (JSON Body). This is useful for large files to avoid HTTP 413 errors.

**Large File Example:**

Instead of uploading the file via POST, upload it to the server's drop zone and call:

```json
{
  "local_file": "my-large-products.csv"
}
```

**Supported CSV Headers:**
The importer expects a standard CSV format. Key headers include:
-   `sku` (Required for syncing)
-   `name`
-   `description`
-   `short_description`
-   `regular_price`
-   `stock` (Mapped to `stock_quantity`)
-   `manage_stock` (1 or 0)
-   `stock_status` (`instock`, `outofstock`)
-   `categories` (Comma-separated IDs, e.g., `12,15`)
-   `images` (Comma-separated URLs)
-   `crosssell_ids`
-   `upsell_ids`

**Curl Example:**

```bash
curl -X POST https://your-site.com/wp-json/external-media/v1/import-products \
  -H "Authorization: Basic <base64_token>" \
  -F "file=@/path/to/products.csv"
```

**Response Structure:**

```json
{
  "created": 5,
  "updated": 12,
  "failed": 0,
  "skipped": 0,
  "errors": []
}
```
