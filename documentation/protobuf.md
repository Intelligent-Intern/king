# Protobuf & Performance Optimizations

This document covers the performance optimizations applied to King's IIBIN (protobuf-like) encoding/decoding system, including batch operations, varint optimizations, and WebSocket speedups.

## Overview

King uses **IIBIN** (the project's own protobuf alternative) for binary encoding. Recent commits have significantly improved its performance through:

1. **Batch encoding/decoding** - amortize PHP↔C boundary overhead
2. **Varint optimizations** - branchless encode/decode
3. **SIMD-accelerated decode** - ARM64 NEON unrolling
4. **WebSocket improvements** - faster frame handling

## Batch Encoding/Decoding

### The Problem

Each call from PHP to the C extension has overhead:

```php
// BEFORE: N calls across PHP↔C boundary
for ($i = 0; $i < 1000; $i++) {
    $binary = king_proto_encode('ChatMessage', $messages[$i]);
}
```

Each `king_proto_encode()` call:
- Prepares zval arguments
- Crosses PHP→C boundary
- Performs encoding
- Returns result across C→PHP boundary
- Cleans up

This overhead dominates when encoding many small messages.

### The Solution: Batch Encoding

Batch encode amortizes the boundary cost:

```php
// AFTER: 1 call across PHP↔C boundary
$binaries = king_proto_encode_batch('ChatMessage', $messages);
```

**Commit:** `c9f6cf6` - "perf: add batch encode to amortize PHP↔C boundary"

### Implementation

```c
// extension/src/iibin/iibin_encoding.c
zend_result king_iibin_encode_batch(
    const char *schema_name,
    zval *records,        // Array of records to encode
    zend_string **out   // Concatenated binary output
)
{
    // Single boundary: encode N records at once
    // Return concatenated binary
}
```

### Functions Added

| Function | Description |
|----------|-------------|
| `king_proto_encode_batch(schema, records[])` | Encode multiple records |
| `king_proto_decode_batch(schema, binary)` | Decode batch from binary |

### Benchmark Results

From commit `b6507fc`:

```
Single encode:  ~500ns per call
Batch encode:  ~50ns per record (10x faster)
               └─ amortizes boundary overhead
```

### Use Cases

- **Real-time chat** - encode multiple messages at once
- **Presence updates** - batch room state changes
- **WebSocket frames** - encode outgoing messages

## Varint Optimizations

### What are Varints?

Variable-length integers. Smaller numbers take fewer bytes:

| Value | Bytes |
|-------|-------|
| 0-127 | 1 byte |
| 128-16383 | 2 bytes |
| 16384-1073741823 | 3 bytes |
| >1073741824 | 4+ bytes |

### Optimization 1: Branchless Encode

**Commit:** `3267785` - "perf: optimize varint encode with branchless algorithm"

Traditional approach uses branches:

```c
// BEFORE: branch per byte case
if (value < 0x80) {
    buf[0] = value;
} else if (value < 0x4000) {
    buf[0] = (value & 0x7f) | 0x80;
    buf[1] = (value >> 7);
}
```

Optimized uses pre-computed masks:

```c
// AFTER: branchless with bit manipulation
uint8_t mask = (value <= 0x7f) ? 0 : 0x80;
buf[0] = (value | mask) & 0xff;
mask = (value <= 0x3fff) ? 0 : 0x80;
buf[1] = ((value >> 7) | mask) & 0xff;
```

**Benefits:**
- No branch misprediction
- Faster on modern CPUs
- Works on both macOS (ARM64) and Linux (x86_64)

### Optimization 2: SIMD Decode

**Commit:** `a669b09` - "perf: optimize varint decode with ARM64 unrolling"

Uses NEON SIMD instructions to decode multiple varints in parallel:

```c
// ARM64 NEON: decode 4 varints at once
uint8x4_t data = vld1_u8(input);
uint8x4_t has_continue = vtst_u8(data, 0x80);
// ... parallel decode of 4 values
```

**Benefits:**
- ~4x throughput on ARM64 (Apple Silicon, AWSGraviton)
- Falls back to scalar on x86_64

## WebSocket Speedups

### Recent Commits

| Commit | Change |
|--------|--------|
| `67c86bf` | Stabilize websocket origin selection |
| `16ed929` | Harden origin failover |
| `65e92dd` | Remove fallback to rest origin |
| `b962904` | Fix SFU websocket failures |

### Optimization: Origin Pinning

After WebSocket failover, subsequent requests **pin to successful endpoint**:

```php
// First connection: try multiple origins
$ws = king_client_websocket_connect('wss://example.com/socket', [
    'fallback_origins' => ['wss://backup.example.com']
]);

// After success: pin to working origin
// Next request goes directly to pinned origin
```

### Optimization: Frame Buffering

Incoming WebSocket frames are buffered and parsed together:

```php
// Receive with single call (gets all pending frames)
$frames = king_client_websocket_receive($ws, 5000);
foreach (explode("\n", $frames) as $frame) {
    // Process each frame
}
```

Reduces `recv()` syscalls when many frames arrive in quick succession.

## Performance Summary

| Optimization | Speedup | Use Case |
|--------------|--------|----------|
| Batch encode | 10x | Chat, presence |
| Branchless varint | ~1.3x | All encoding |
| SIMD varint | 4x (ARM64) | High-volume decode |
| Origin pinning | Faster reconnects | Client failover |
| Frame buffering | Fewer syscalls | High-throughput WS |

## Files Changed

### Batch Encoding

```
extension/src/iibin/iibin_encoding.c    +42 lines
extension/src/iibin/iibin_decoding.c  +40 lines
extension/src/iibin/iibin_api.c       +8  lines
```

### Varint Optimization

```
extension/include/iibin/iibin_internal.h    +44/-11 lines
extension/src/iibin/varint_simd.c          (new file)
```

### WebSocket

```
extension/src/client/websocket/api.inc         (frame handling)
demo/video-chat/backend-king-php/http/module_realtime.php
```

## Usage

### PHP Batch Encoding

```php
$messages = [
    ['user_id' => 1, 'text' => 'Hello'],
    ['user_id' => 2, 'text' => 'Hi there'],
    ['user_id' => 1, 'text' => 'How are you?'],
];

// Before: N calls
$binaries = [];
foreach ($messages as $msg) {
    $binaries[] = king_proto_encode('ChatMessage', $msg);
}

// After: 1 call
$binary = king_proto_encode_batch('ChatMessage', $messages);
```

### PHP Batch Decoding

```php
// Encode batch
$binary = king_proto_encode_batch('ChatMessage', $messages);

// Decode batch
$decoded = king_proto_decode_batch('ChatMessage', $binary);
// Returns array of decoded records
```

### JavaScript Usage

```javascript
const client = new GossipMeshClient({
    onFrameReceived: (publisherId, sequence, data) => {
        // data is ArrayBuffer from WebRTC DataChannel
        const messages = decodeBatch('ChatMessage', data);
        // Process each message
    }
});
```

## Benchmarks

Run from extension directory:

```bash
cd extension
php -d extension=modules/king.so benchmarks/iibin-batch-bench.php
```

Typical results on Apple Silicon M1:

```
Single encode:   500ns/call
Batch encode:  50ns/record (10x)
Single decode: 400ns/call  
Batch decode: 100ns/record (4x)
```

## Future Optimizations

Potential areas for further improvement:

1. **Zero-copy decode** - decode directly into PHP objects without intermediate array
2. **SIMD encode** - ARM64 NEON for encoding
3. **Streaming batch** - encode/decode without pre-allocating full buffer
4. **WASM compilation** - run IIBIN in browser via WebAssembly
