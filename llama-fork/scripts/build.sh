#!/bin/bash
# Build script for llama-fork with Voltron KV cache transfer support

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LLAMA_FORK_DIR="$SCRIPT_DIR"

echo "Building llama-fork..."
cd "$LLAMA_FORK_DIR"

# Clean and configure
rm -rf build
mkdir -p build
cd build

cmake .. \
    -DCMAKE_BUILD_TYPE=Release \
    -DGGML_METAL=ON \
    -DGGML_ACCELERATE=ON

# Build
cmake --build . --config Release -j$(sysctl -n hw.ncpu 2>/dev/null || nproc)

echo ""
echo "Build complete!"
echo "Binary: $LLAMA_FORK_DIR/build/bin/llama-cli"
echo ""
echo "Usage examples:"
echo "  # Single-node inference:"
echo "  $LLAMA_FORK_DIR/build/bin/llama-cli -m model.gguf -n 10 -p '2+2='"
echo ""
echo "  # Worker 0 - save KV cache:"
echo "  $LLAMA_FORK_DIR/build/bin/llama-cli -m model.gguf -n 1 -p '2+2=' --kv-cache-out /tmp/kv.bin"
echo ""
echo "  # Worker 1 - load KV cache:"
echo "  $LLAMA_FORK_DIR/build/bin/llama-cli -m model.gguf -n 10 -p '2+2=' --kv-cache-in /tmp/kv.bin"