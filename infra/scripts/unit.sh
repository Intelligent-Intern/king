#!/bin/bash
# unit.sh - Unit test script for the King PHP extension

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Running King Extension Unit Tests${NC}"

# Get the directory of this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$PROJECT_ROOT"

# Ensure extension is built
if [ ! -f "extension/modules/king.so" ]; then
    echo -e "${YELLOW}Extension not found, building first...${NC}"
    bash infra/scripts/build.sh
fi

# Run PHP extension tests
echo -e "${YELLOW}Running PHP extension tests...${NC}"
cd extension
make test

# Run PHP-level tests
echo -e "${YELLOW}Running PHP-level tests...${NC}"
cd "$PROJECT_ROOT"
if [ -d "tests" ]; then
    php -d extension="$PROJECT_ROOT/extension/modules/king.so" -f tests/run_tests.php
fi

echo -e "${GREEN}Unit tests completed!${NC}"