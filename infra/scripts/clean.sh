#!/bin/bash
# clean.sh - Clean script for the King PHP extension

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Cleaning King PHP Extension Build${NC}"

# Get the directory of this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$PROJECT_ROOT"

# Clean extension build
echo -e "${YELLOW}Cleaning extension build...${NC}"
cd extension
if [ -f "Makefile" ]; then
    make clean || true
fi

# Remove generated files
rm -f config.cache config.log config.status
rm -f configure configure.ac
rm -f Makefile Makefile.fragments Makefile.objects
rm -f libtool
rm -rf autom4te.cache
rm -rf .libs
rm -f *.lo *.la
rm -rf modules

# Clean quiche build
echo -e "${YELLOW}Cleaning quiche build...${NC}"
cd "$PROJECT_ROOT/quiche"
if [ -d "target" ]; then
    cargo clean || true
fi

# Clean libcurl build
echo -e "${YELLOW}Cleaning libcurl build...${NC}"
cd "$PROJECT_ROOT/libcurl"
if [ -f "Makefile" ]; then
    make clean || true
    make distclean || true
fi

echo -e "${GREEN}Clean completed!${NC}"