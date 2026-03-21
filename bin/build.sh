#!/bin/bash
# King Library Build Script
set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PHP_VERSION=${PHP_VERSION:-$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")}
BUILD_TYPE=${BUILD_TYPE:-release}
JOBS=${JOBS:-$(nproc)}
CCACHE=${CCACHE:-1}

echo -e "${BLUE}🚀 Building King Library${NC}"
echo -e "${BLUE}PHP Version: ${PHP_VERSION}${NC}"
echo -e "${BLUE}Build Type: ${BUILD_TYPE}${NC}"
echo -e "${BLUE}Jobs: ${JOBS}${NC}"

# Check dependencies
check_dependencies() {
    echo -e "${YELLOW}📋 Checking dependencies...${NC}"
    
    local missing_deps=()
    
    # Check PHP development headers
    if ! php-config --version >/dev/null 2>&1; then
        missing_deps+=("php-dev")
    fi
    
    # Check build tools
    for tool in gcc make cmake pkg-config phpize; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            missing_deps+=("$tool")
        fi
    done
    
    # Check libraries
    for lib in openssl curl nghttp2; do
        if ! pkg-config --exists "$lib" 2>/dev/null; then
            missing_deps+=("lib${lib}-dev")
        fi
    done
    
    if [ ${#missing_deps[@]} -ne 0 ]; then
        echo -e "${RED}❌ Missing dependencies: ${missing_deps[*]}${NC}"
        echo -e "${YELLOW}Install with: sudo apt-get install ${missing_deps[*]}${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✅ All dependencies satisfied${NC}"
}

# Setup ccache if available
setup_ccache() {
    if [ "$CCACHE" = "1" ] && command -v ccache >/dev/null 2>&1; then
        echo -e "${YELLOW}⚡ Setting up ccache...${NC}"
        export CC="ccache gcc"
        export CXX="ccache g++"
        ccache -s
    fi
}

# Build extension
build_extension() {
    echo -e "${YELLOW}🔨 Building King extension...${NC}"
    
    cd extension
    
    # Clean previous build
    if [ -f Makefile ]; then
        make clean || true
    fi
    
    # Generate configure script
    phpize
    
    # Configure build
    local configure_flags="--enable-king"
    
    if [ "$BUILD_TYPE" = "debug" ]; then
        configure_flags="$configure_flags --enable-debug"
        export CFLAGS="-g -O0 -Wall -Wextra"
    else
        export CFLAGS="-O3 -march=native -DNDEBUG"
    fi
    
    # Add library flags
    configure_flags="$configure_flags --with-openssl --with-curl"
    
    echo -e "${BLUE}Configure flags: ${configure_flags}${NC}"
    ./configure $configure_flags
    
    # Build
    make -j"$JOBS"
    
    echo -e "${GREEN}✅ Extension built successfully${NC}"
    cd ..
}

# Build demo application
build_demo() {
    echo -e "${YELLOW}🎨 Building demo application...${NC}"
    
    if [ -d "demo/video-chat" ]; then
        cd demo/video-chat
        
        if [ -f package.json ]; then
            # Install dependencies
            if command -v npm >/dev/null 2>&1; then
                npm ci
                npm run build
                echo -e "${GREEN}✅ Demo application built${NC}"
            else
                echo -e "${YELLOW}⚠️  npm not found, skipping demo build${NC}"
            fi
        else
            echo -e "${YELLOW}⚠️  No package.json found in demo${NC}"
        fi
        
        cd ../..
    else
        echo -e "${YELLOW}⚠️  Demo directory not found${NC}"
    fi
}

# Run tests
run_tests() {
    echo -e "${YELLOW}🧪 Running tests...${NC}"
    
    # Install extension temporarily for testing
    cd extension
    make install
    
    # Add extension to PHP configuration
    local php_ini=$(php --ini | grep "Loaded Configuration" | sed -e "s|.*:\s*||")
    if ! grep -q "extension=king.so" "$php_ini" 2>/dev/null; then
        echo "extension=king.so" | sudo tee -a "$php_ini"
    fi
    
    # Verify extension loads
    if php -m | grep -q king; then
        echo -e "${GREEN}✅ King extension loaded successfully${NC}"
        php -r "echo 'King version: ' . phpversion('king') . PHP_EOL;"
    else
        echo -e "${RED}❌ Failed to load King extension${NC}"
        exit 1
    fi
    
    cd ..
    
    # Run PHPUnit tests if available
    if [ -f vendor/bin/phpunit ]; then
        vendor/bin/phpunit
    else
        echo -e "${YELLOW}⚠️  PHPUnit not found, skipping unit tests${NC}"
    fi
    
    # Run integration tests
    if [ -f tests/integration/system_integration_test.php ]; then
        php tests/integration/system_integration_test.php
    else
        echo -e "${YELLOW}⚠️  Integration tests not found${NC}"
    fi
}

# Main build process
main() {
    local start_time=$(date +%s)
    
    check_dependencies
    setup_ccache
    build_extension
    
    if [ "${BUILD_DEMO:-1}" = "1" ]; then
        build_demo
    fi
    
    if [ "${RUN_TESTS:-1}" = "1" ]; then
        run_tests
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    echo -e "${GREEN}🎉 Build completed successfully in ${duration}s${NC}"
    echo -e "${GREEN}Extension: extension/modules/king.so${NC}"
    
    if [ -d "demo/video-chat/dist" ]; then
        echo -e "${GREEN}Demo: demo/video-chat/dist/${NC}"
    fi
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "King Library Build Script"
        echo ""
        echo "Usage: $0 [options]"
        echo ""
        echo "Options:"
        echo "  --help, -h          Show this help"
        echo "  --debug             Build in debug mode"
        echo "  --release           Build in release mode (default)"
        echo "  --no-demo           Skip demo build"
        echo "  --no-tests          Skip tests"
        echo "  --clean             Clean before build"
        echo ""
        echo "Environment variables:"
        echo "  PHP_VERSION         PHP version to build for"
        echo "  BUILD_TYPE          Build type (debug|release)"
        echo "  JOBS                Number of parallel jobs"
        echo "  CCACHE              Use ccache (1|0)"
        exit 0
        ;;
    --debug)
        BUILD_TYPE=debug
        ;;
    --release)
        BUILD_TYPE=release
        ;;
    --no-demo)
        BUILD_DEMO=0
        ;;
    --no-tests)
        RUN_TESTS=0
        ;;
    --clean)
        echo -e "${YELLOW}🧹 Cleaning previous build...${NC}"
        ./bin/clean.sh
        ;;
esac

main "$@"