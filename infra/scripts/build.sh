#!/bin/bash
# build.sh - Build script for the King PHP extension

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Building King PHP Extension${NC}"

# Get the directory of this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$PROJECT_ROOT"

# Initialize and update submodules
echo -e "${YELLOW}Initializing submodules...${NC}"
git submodule update --init --recursive

# Build quiche
echo -e "${YELLOW}Building quiche...${NC}"
cd quiche
if [ ! -f "target/release/libquiche.a" ]; then
    cargo build --release --features ffi,pkg-config-meta,qlog
fi
cd ..

# Build libcurl
echo -e "${YELLOW}Building libcurl...${NC}"
cd libcurl
if [ ! -f "lib/.libs/libcurl.a" ]; then
    ./buildconf
    ./configure --enable-static --disable-shared --with-openssl --enable-http3 --with-quiche="$PROJECT_ROOT/quiche/target/release"
    make -j$(nproc)
fi
cd ..

# Build the PHP extension
echo -e "${YELLOW}Building King extension...${NC}"
cd extension

# Clean previous build
if [ -f "Makefile" ]; then
    make clean || true
fi

# Run phpize
phpize

# Configure
./configure --with-king

# Build
make -j$(nproc)

echo -e "${GREEN}Build completed successfully!${NC}"
echo -e "${YELLOW}Extension built: $(pwd)/modules/king.so${NC}"

# Optional: Install the extension
if [ "$1" = "--install" ]; then
    echo -e "${YELLOW}Installing extension...${NC}"
    make install
    echo -e "${GREEN}Extension installed successfully!${NC}"
fi