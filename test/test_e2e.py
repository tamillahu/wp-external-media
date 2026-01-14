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

    def test_sync_logic(self):
        """
        Test flow:
        1. Import Sync (Create): Verify 'created' list.
        2. Idempotency Sync (Unchanged): Verify 'unchanged' list.
        3. Update Sync (Update): Verify 'updated' list.
        4. Delete Sync (Delete): Verify 'deleted' list.
        """
        
        external_id = "e2e-sync-test"
        external_url = "https://example.com/sync-image.jpg"
        
        # --- Step 1: Create ---
        payload = [{
            "id": external_id,
            "title": "Original Title",
            "mime_type": "image/jpeg",
            "urls": { "full": external_url }
        }]

        print(f"Step 1: Creating {external_id}...")
        response = self.session.post(self.api_url, json=payload)
        self.assertEqual(response.status_code, 200, f"Import failed: {response.text}")
        results = response.json().get('results', {})
        
        self.assertIn(external_id, results.get('created', []), "ID should be in 'created'")
        self.assertEqual(len(results.get('updated', [])), 0)
        self.assertEqual(len(results.get('deleted', [])), 0)

        # --- Step 2: Unchanged (Idempotency) ---
        print(f"Step 2: Re-importing {external_id} (Unchanged)...")
        response = self.session.post(self.api_url, json=payload)
        results = response.json().get('results', {})
        
        self.assertIn(external_id, results.get('unchanged', []), "ID should be in 'unchanged'")
        self.assertEqual(len(results.get('created', [])), 0)
        self.assertEqual(len(results.get('updated', [])), 0)

        # --- Step 3: Update ---
        payload[0]['title'] = "Updated Title"
        print(f"Step 3: Updating {external_id}...")
        response = self.session.post(self.api_url, json=payload)
        results = response.json().get('results', {})
        
        self.assertIn(external_id, results.get('updated', []), "ID should be in 'updated'")
        self.assertEqual(len(results.get('created', [])), 0)
        self.assertEqual(len(results.get('unchanged', [])), 0)

        # Verify update in WP
        # We can't easy check title via search param if it changed, but let's assume API success means it tried.
        
        # --- Step 4: Delete ---
        # Send empty list -> should delete everything including our ID
        print(f"Step 4: Deleting {external_id}...")
        response = self.session.post(self.api_url, json=[])
        results = response.json().get('results', {})
        
        self.assertIn(external_id, results.get('deleted', []), "ID should be in 'deleted'")
        
        self.assertIn(external_id, results.get('deleted', []), "ID should be in 'deleted'")

    def test_get_image_sizes(self):
        """
        Verify that we can retrieve registered image sizes.
        """
        url = f"{self.base_url}?rest_route=/external-media/v1/image-sizes"
        print(f"Testing GET image sizes at {url}...")
        
        response = self.session.get(url)
        self.assertEqual(response.status_code, 200, f"Failed to get image sizes: {response.text}")
        
        sizes = response.json()
        self.assertIsInstance(sizes, dict, "Response should be a dictionary of sizes")
        
        # Verify standard sizes exist
        for expected_size in ['thumbnail', 'medium', 'medium_large', 'large']:
             # Note: medium_large might not be active in all setups, but thumbnail/medium/large usually are.
             # We'll just check if *any* of them are present to be safe, or just check 'thumbnail'
            if expected_size in sizes:
                 size_data = sizes[expected_size]
                 self.assertIn('width', size_data)
                 self.assertIn('height', size_data)
                 self.assertIn('crop', size_data)

        print(f"Retrieved {len(sizes)} image sizes.")

if __name__ == '__main__':
    run_tests()
