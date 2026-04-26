#!/bin/bash
echo "=== Testing if layer args are parsed ===" 
echo "Full model:"
timeout 30 /tmp/llama.cpp/build/bin/llama-cli -m /Users/sasha/king/demo/models/qwen2.5-coder-3b-q4_k.gguf -n 4 -c 512 --temp 0.0 -p "2+2=" 2>&1 | head -5

echo "Layer 0 only:"
timeout 30 /tmp/llama.cpp/build/bin/llama-cli -m /Users/sasha/king/demo/models/qwen2.5-coder-3b-q4_k.gguf -n 4 -c 512 --temp 0.0 -p "2+2=" --layer-start 0 --layer-end 0 2>&1 | head -5