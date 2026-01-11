#!/bin/bash
set -e

# Configuration
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export WP_BASE_URL="http://localhost:8080"
export WP_USER="admin"

echo "=== Starting E2E Test Suite ==="

# 1. Start Environment
echo "[1/4] Starting Docker Environment..."
cd "$TEST_DIR"
docker compose up -d --wait wordpress db

# 2. Setup & Get Credentials
echo "[2/4] Running Setup..."
# We run setup.sh and grep the Application Password
setup_output=$(bash setup.sh)
export WP_PASS=$(echo "$setup_output" | grep "Application Password: " | cut -d ' ' -f 3)

if [ -z "$WP_PASS" ]; then
    echo "Error: Failed to retrieve Application Password from setup.sh"
    echo "Setup Output:"
    echo "$setup_output"
    exit 1
fi

echo "Credentials retrieved. User: $WP_USER"

# 3. Run Python Tests
echo "[3/4] Running Python Tests..."
# Install dependencies if needed (create venv)
if [ ! -d "venv" ]; then
    python3 -m venv venv
fi

source venv/bin/activate
pip install -r requirements.txt

python test_e2e.py

# 4. Cleanup
echo "[4/4] Cleanup..."
docker compose down -v

echo "=== E2E Tests Completed Successfully ==="
