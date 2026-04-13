#!/usr/bin/env bash
# build.sh — Build WLVC WASM codec
#
# Prerequisites:
#   • Emscripten SDK installed and activated
#     (https://emscripten.org/docs/getting_started/downloads.html)
#     source ~/emsdk/emsdk_env.sh  # or wherever emsdk is installed
#
# Usage:
#   ./build.sh          # Release build (optimized)
#   ./build.sh debug    # Debug build (symbols, no optimization)

set -euo pipefail

BUILD_TYPE="${1:-Release}"
BUILD_DIR="build"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " Building WLVC WASM codec (${BUILD_TYPE})"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Check for Emscripten
if ! command -v emcc &> /dev/null; then
    echo "❌ Emscripten not found. Install from:"
    echo "   https://emscripten.org/docs/getting_started/downloads.html"
    echo ""
    echo "Then activate:"
    echo "   source ~/emsdk/emsdk_env.sh"
    exit 1
fi

echo "✓ Emscripten $(emcc --version | head -n1)"
echo ""

# Clean previous build
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"
cd "${BUILD_DIR}"

# Configure with Emscripten toolchain
echo "→ Configuring with CMake..."
emcmake cmake .. \
    -DCMAKE_BUILD_TYPE="${BUILD_TYPE}" \
    -DCMAKE_VERBOSE_MAKEFILE=ON

# Build
echo ""
echo "→ Building..."
emmake make -j$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 4)

# Install (copies wlvc.js + wlvc.wasm to parent directory)
echo ""
echo "→ Installing..."
make install

cd ..

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Build complete!"
echo ""
echo "Output files:"
ls -lh wlvc.js wlvc.wasm 2>/dev/null || echo "  (build failed — check errors above)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
