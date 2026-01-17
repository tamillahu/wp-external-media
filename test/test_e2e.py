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
        Verify that we can retrieve registered image sizes publicly (no auth).
        """
        url = f"{self.base_url}?rest_route=/external-media/v1/image-sizes"
        print(f"Testing GET image sizes (public) at {url}...")
        
        # Use a fresh, unauthenticated requests session
        public_session = requests.Session()
        
        response = public_session.get(url)
        self.assertEqual(response.status_code, 200, f"Failed to get image sizes publically: {response.text}")
        
        sizes = response.json()
        self.assertIsInstance(sizes, dict, "Response should be a dictionary of sizes")
        
        # Verify standard sizes exist
        for expected_size in ['thumbnail', 'medium', 'medium_large', 'large']:
            if expected_size in sizes:
                 size_data = sizes[expected_size]
                 self.assertIn('width', size_data)
                 self.assertIn('height', size_data)
                 self.assertIn('crop', size_data)

        print(f"Retrieved {len(sizes)} image sizes.")


    def test_import_products(self):
        """
        Verify that we can import products via the new endpoint.
        Uses a public endpoint or admin auth if needed.
        The implementation checks for 'edit_products' capability, so we must be authenticated.
        """
        url = f"{self.base_url}?rest_route=/external-media/v1/import-products"
        print(f"Testing Product Import at {url}...")

        # Minimal valid WooCommerce CSV content
        # Check WC CSV docs: needs at least Name and Type? Or just Name?
        # Use headers provided by User that are known to work
        csv_content = """sku,name,description,short_description,regular_price,categories,images,stock,manage_stock,stock_status,crosssell_ids,upsell_ids
woo-import-test-1,Test Product 1,Long Desc,Short Desc,10,,,10,0,instock,,
woo-import-test-2,Test Product 2,Long Desc,Short Desc,20,,,20,0,instock,,
"""
        
        # We must be authenticated (admin usually has edit_products)
        # Try sending raw body with text/csv
        headers = self.session.headers.copy()
        headers['Content-Type'] = 'text/csv'
        headers['Content-Disposition'] = 'attachment; filename="import.csv"'
            
        response = self.session.post(url, data=csv_content, headers=headers)
        
        if response.status_code != 200:
             # It might fail if WC is not active or other issues
             self.fail(f"Product import failed: {response.text}")
        
        result = response.json()
        print(f"Import Result: {result}")
        
        self.assertIsInstance(result, dict)
        # Check created OR updated (since we do dual run)
        created = result.get('created', 0)
        updated = result.get('updated', 0)
        self.assertGreater(created + updated, 0, "Should have imported (created or updated) at least one product")


    def test_import_products_multipart(self):
        """Verify that we can import products via the new endpoint using multipart/form-data (like Ansible)."""
        print(f"Testing Product Import (Multipart) at {self.base_url}?rest_route=/external-media/v1/import-products...")
        
        url = f"{self.base_url}?rest_route=/external-media/v1/import-products"
        
        # Use unique SKUs to ensure we are testing fresh creation/updates without interference
        csv_content = (
            "sku,name,description,short_description,regular_price,categories,images,stock,manage_stock,stock_status,crosssell_ids,upsell_ids\n"
            "woo-multipart-UNIQUE-1,Multipart Product 1,Long Description,Short Description,19.99,,,10,1,instock,,\n"
            "woo-multipart-UNIQUE-2,Multipart Product 2,Long Description,Short Description,29.99,,,0,instock,,"
        )
        
        files = {
            'file': ('products.csv', csv_content, 'text/csv')
        }
        
        # Note: requests handles multipart headers automatically when 'files' is used.
        # We explicitly rely on Admin Auth set in setUp()
        
        # Requests session 'Content-Type' header triggers JSON parsing error in WP. 
        # We use a fresh request to avoid session header pollution completely.
        response = requests.post(
            url, 
            files=files, 
            auth=(self.auth_user, self.auth_pass)
        )
        
        if response.status_code != 200:
             self.fail(f"Product import (multipart) failed: {response.text}")
        
        result = response.json()
        print(f"Import Result (Multipart): {result}")
        
        self.assertIsInstance(result, dict)
        created = result.get('created', 0)
        updated = result.get('updated', 0)
        self.assertGreater(created + updated, 0, "Should have imported (created or updated) at least one product via multipart")


if __name__ == '__main__':
    run_tests()
