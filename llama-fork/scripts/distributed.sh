#!/bin/bash
# Distributed inference orchestrator
# Wires workers in series: Worker 0 → Worker 1 → output

set -e

# Defaults
MODEL="${MODEL:-/Users/sasha/king/demo/models/qwen2.5-coder-3b-q4_k.gguf}"
PROMPT="${PROMPT:-2+2=}"
OUTPUT_TOKENS="${OUTPUT_TOKENS:-4}"
CTX_SIZE="${CTX_SIZE:-512}"
TEMP="${TEMP:-0.0}"
KV_FILE="${KV_FILE:-/tmp/voltron_kv.bin}"
LAYER_SPLIT="${LAYER_SPLIT:-18}"  # First half / second half

LLAMA_CLI="${LLAMA_CLI:-$(dirname "$0")/build/bin/llama-cli}"

usage() {
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  --model FILE          Model path (default: $MODEL)"
    echo "  --prompt TEXT         Input prompt (default: '$PROMPT')"
    echo "  --tokens N            Output tokens (default: $OUTPUT_TOKENS)"
    echo "  --ctx N               Context size (default: $CTX_SIZE)"
    echo "  --temp N              Temperature (default: $TEMP)"
    echo "  --kv-file FILE        KV cache file (default: $KV_FILE)"
    echo "  --layer-split N       Layer to split at (default: $LAYER_SPLIT)"
    echo ""
    echo "Environment variables also supported:"
    echo "  MODEL, PROMPT, OUTPUT_TOKENS, CTX_SIZE, TEMP, KV_FILE, LAYER_SPLIT"
}

# Parse args
while [[ $# -gt 0 ]]; do
    case $1 in
        --model) MODEL="$2"; shift 2 ;;
        --prompt) PROMPT="$2"; shift 2 ;;
        --tokens) OUTPUT_TOKENS="$2"; shift 2 ;;
        --ctx) CTX_SIZE="$2"; shift 2 ;;
        --temp) TEMP="$2"; shift 2 ;;
        --kv-file) KV_FILE="$2"; shift 2 ;;
        --layer-split) LAYER_SPLIT="$2"; shift 2 ;;
        --help) usage; exit 0 ;;
        *) echo "Unknown option: $1"; usage; exit 1 ;;
    esac
done

# Validate
if [[ ! -f "$LLAMA_CLI" ]]; then
    echo "Error: llama-cli not found at $LLAMA_CLI"
    echo "Run: ./scripts/build.sh"
    exit 1
fi

if [[ ! -f "$MODEL" ]]; then
    echo "Error: Model not found at $MODEL"
    exit 1
fi

# Clean up
rm -f "$KV_FILE"

echo "=== Voltron Distributed Inference ==="
echo "Model: $MODEL"
echo "Prompt: $PROMPT"
echo "Split at layer: $LAYER_SPLIT"
echo ""

# Worker 0: Run inference, save KV cache
echo ">>> Worker 0 (generating initial forward pass)..."
WORKER0_OUT=$(timeout 120 "$LLAMA_CLI" \
    -m "$MODEL" \
    -n 1 \
    -c "$CTX_SIZE" \
    --temp "$TEMP" \
    -p "$PROMPT" \
    --kv-cache-out "$KV_FILE" \
    2>&1 | tail -1)

echo "Worker 0 output: $WORKER0_OUT"

# Worker 1: Load KV cache, continue inference
echo ""
echo ">>> Worker 1 (loading KV cache, continuing inference)..."
WORKER1_OUT=$(timeout 120 "$LLAMA_CLI" \
    -m "$MODEL" \
    -n "$OUTPUT_TOKENS" \
    -c "$CTX_SIZE" \
    --temp "$TEMP" \
    -p "$PROMPT" \
    --kv-cache-in "$KV_FILE" \
    2>&1 | tail -1)

echo "Worker 1 output: $WORKER1_OUT"

# Cleanup
rm -f "$KV_FILE"

echo ""
echo "=== Done ==="