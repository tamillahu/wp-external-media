import os
import requests
import unittest
import xmlrunner  # Will use xmlrunner for JUnit output if available, else standard TextTestRunner

# Helper to run with/without xmlrunner if not installed
def run_tests():
    try:
        import xmlrunner
        runner = xmlrunner.XMLTestRunner(output='test-reports')
    except ImportError:
        print("xmlrunner not found, using standard runner. (pip install unittest-xml-reporting)")
        runner = unittest.TextTestRunner()
    
    unittest.main(testRunner=runner, verbosity=2)

class TestWPExternalMedia(unittest.TestCase):
    def setUp(self):
        self.base_url = os.environ.get('WP_BASE_URL', 'http://localhost:8080')
        self.auth_user = os.environ.get('WP_USER', 'admin')
        self.auth_pass = os.environ.get('WP_PASS') # Application Password

        if not self.auth_pass:
            self.fail("WP_PASS environment variable (Application Password) is required.")

        self.api_url = f"{self.base_url}?rest_route=/external-media/v1/import"
        self.session = requests.Session()
        self.session.auth = (self.auth_user, self.auth_pass)
        self.session.headers.update({'Content-Type': 'application/json'})

    def test_import_and_fetch_media(self):
        """
        Test flow:
        1. Import an external image.
        2. Verify API response success.
        3. Fetch the attachment via WP REST API to get the source URL.
        4. Verify the media details match (URL, Title).
        """
        
        # 1. Prepare Payload
        external_id = "e2e-test-01"
        external_url = "https://example.com/e2e-image.jpg"
        payload = [{
            "id": external_id,
            "title": "E2E Test Image",
            "mime_type": "image/jpeg",
            "urls": {
                "full": external_url,
                "thumbnail": "https://example.com/e2e-image-thumb.jpg"
            }
        }]

        # 2. Execute Import
        print(f"Importing media to {self.api_url}...")
        response = self.session.post(self.api_url, json=payload)
        self.assertEqual(response.status_code, 200, f"Import failed: {response.text}")
        data = response.json()
        self.assertTrue(data.get('success'), "API did not return success flag")

        # 3. Validation - Fetch from Media Library
        # searching by searching title or checking recent items
        # WP REST API: /wp/v2/media
        search_url = f"{self.base_url}?rest_route=/wp/v2/media"
        print(f"Searching media at {search_url}...")
        
        # We need a small delay or just search immediately. 
        # Using search param to find the specific item
        search_params = {
            'search': 'E2E Test Image',
            'context': 'view'
        }
        
        media_response = self.session.get(search_url, params=search_params)
        self.assertEqual(media_response.status_code, 200, "Failed to fetch media library")
        media_items = media_response.json()

        self.assertTrue(len(media_items) > 0, "No media items found with the test title")
        
        # Find our specific item (in case of other matches)
        found_item = None
        for item in media_items:
            # Check source_url. 
            # Note: WP REST API 'source_url' field should return the filtered URL if our plugin works.
            if item['source_url'] == external_url:
                found_item = item
                break
        
        self.assertIsNotNone(found_item, f"Could not find media item with source_url: {external_url}")
        print(f"Verified media item ID: {found_item['id']} has source_url: {found_item['source_url']}")

        # Verify ID match if we stored it (we store it in meta, but standard API doesn't show meta by default)
        
if __name__ == '__main__':
    run_tests()
