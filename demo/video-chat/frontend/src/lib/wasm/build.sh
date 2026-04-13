#!/usr/bin/env bash
# build.sh — Build WLVC WASM codec
#
# Prerequisites:
#   • Emscripten SDK installed and activated
#     source ~/emsdk/emsdk_env.sh
#
# Usage:
#   ./build.sh          # Release build (optimized)
#   ./build.sh debug    # Debug build

set -euo pipefail

BUILD_TYPE="${1:-Release}"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " Building WLVC WASM codec (${BUILD_TYPE})"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Check for Emscripten
if ! command -v emcc &> /dev/null; then
    echo "❌ Emscripten not found. Run:"
    echo "   source ~/emsdk/emsdk_env.sh"
    exit 1
fi

echo "✓ Emscripten $(emcc --version | head -n1)"
echo ""

# Build flags
if [ "$BUILD_TYPE" = "debug" ]; then
    CXX_FLAGS="-g4 -O0 -msimd128"
    EXTRA_FLAGS=""
else
    CXX_FLAGS="-O3 -msimd128"
    EXTRA_FLAGS="--bind"
fi

# Build directly with emcc
echo "→ Building..."
emcc $CXX_FLAGS \
    $EXTRA_FLAGS \
    -s WASM=1 \
    -s ALLOW_MEMORY_GROWTH=1 \
    -s INITIAL_MEMORY=16777216 \
    -s MAXIMUM_MEMORY=134217728 \
    -s STACK_SIZE=1048576 \
    -s MODULARIZE=1 \
    -s EXPORT_ES6=1 \
    -s ENVIRONMENT=web \
    -o wlvc.js \
    cpp/dwt.cpp cpp/quantize.cpp cpp/entropy.cpp cpp/motion.cpp cpp/audio.cpp cpp/codec.cpp cpp/exports.cpp

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Build complete!"
echo ""
echo "Output files:"
ls -lh wlvc.js wlvc.wasm 2>/dev/null || echo "  (build failed)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"