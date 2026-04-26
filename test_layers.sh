#!/bin/bash
echo "=== Full model ===" 
timeout 60 /Users/sasha/king/llama-fork/build/bin/llama-cli -m /Users/sasha/king/demo/models/qwen2.5-coder-3b-q4_k.gguf -n 4 -c 512 --temp 0.0 -p "2+2=" 2>/dev/null | tail -1

echo "=== Layer 0-0 ==="
timeout 60 /Users/sasha/king/llama-fork/build/bin/llama-cli -m /Users/sasha/king/demo/models/qwen2.5-coder-3b-q4_k.gguf -n 4 -c 512 --temp 0.0 -p "2+2=" -ls 0 -le 0 2>/dev/null | tail -1

echo "=== Layer 0-35 ==="
timeout 60 /Users/sasha/king/llama-fork/build/bin/llama-cli -m /Users/sasha/king/demo/models/qwen2.5-coder-3b-q4_k.gguf -n 4 -c 512 --temp 0.0 -p "2+2=" -ls 0 -le 35 2>/dev/null | tail -1

echo "=== Layer 10-20 ==="
timeout 60 /Users/sasha/king/llama-fork/build/bin/llama-cli -m /Users/sasha/king/demo/models/qwen2.5-coder-3b-q4_k.gguf -n 4 -c 512 --temp 0.0 -p "2+2=" -ls 10 -le 20 2>/dev/null | tail -1