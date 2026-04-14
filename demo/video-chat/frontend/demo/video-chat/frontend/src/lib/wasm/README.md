# WLVC — Wavelet-Lifting Video Codec (C++ / WASM)

High-performance video codec compiled to WebAssembly for **10–50× faster** encoding/decoding vs the TypeScript implementation.

## Features

- **2-D separable Haar DWT** with cache-blocked column pass
  - Working set kept in L1 data cache (8–16 columns × frame height)
  - SIMD-ready via WASM SIMD128 (auto-vectorized by Emscripten)

- **Per-subband scalar quantisation** with deadzone
  - Finest subbands (high spatial frequency) → largest step → most zeros
  - LL subband (DC components) → smallest step → preserved accurately

- **Run-length encoding** of quantised int16 coefficients
  - Typical compression: 8–12× for wavelet-sparse data at quality 60

- **Temporal residual coding** for delta frames
  - Y channel: frame[i] − prev[i] before DWT
  - U/V channels: no temporal prediction (smaller, less motion)

- **Motion estimation** (block SAD matching + Kalman smoothing)
  - SIMD-accelerated SAD for 16×16 blocks
  - Optional (not used by default in the current codec pipeline)

- **Audio processing** (noise gate + compressor + limiter)
  - Feed-forward RMS compressor, 3:1 ratio
  - Attack/release tuned for speech

## Build

### Prerequisites

Install the [Emscripten SDK](https://emscripten.org/docs/getting_started/downloads.html):

```bash
# Clone emsdk repo
git clone https://github.com/emscripten-core/emsdk.git ~/emsdk
cd ~/emsdk

# Install latest
./emsdk install latest
./emsdk activate latest

# Activate for current shell
source ./emsdk_env.sh
```

### Compile

```bash
cd demo/video-chat/frontend/src/lib/wasm
./build.sh          # Release build (optimized)
./build.sh debug    # Debug build (unoptimized, symbols)
```

Output files:
- `wlvc.js` — Emscripten-generated JavaScript glue code
- `wlvc.wasm` — Compiled WASM binary (~80–120 KB gzipped)

## Usage

### TypeScript / JavaScript

```typescript
import { createWasmEncoder, createWasmDecoder } from './wasm/wasm-codec'

// Encoder
const encoder = await createWasmEncoder({
  width: 640,
  height: 480,
  quality: 60,           // 1–100 (higher = better quality, larger files)
  keyFrameInterval: 30,  // I-frame every 30 frames
})

const imageData = ctx.getImageData(0, 0, 640, 480)
const frameData = encoder.encodeFrame(imageData, timestampMs)
// frameData.data is an ArrayBuffer with the encoded payload

// Decoder
const decoder = await createWasmDecoder({ width: 640, height: 480, quality: 60 })
const decoded = decoder.decodeFrame(frameData)
// decoded.data is a Uint8ClampedArray (RGBA, 640×480×4 bytes)

const outputImageData = new ImageData(decoded.data, 640, 480)
ctx.putImageData(outputImageData, 0, 0)
```

### Fallback to TypeScript codec

If WASM fails to load (old browser, build not available), use the hybrid factory:

```typescript
import { createHybridEncoder } from './wasm/wasm-codec'

const encoder = await createHybridEncoder({ width: 640, height: 480, quality: 60 })
// Uses WASM if available, falls back to TypeScript codec
```

## Performance

Measured on a 2020 M1 MacBook Air, 640×480 @ 30fps, quality 60:

| Implementation | Encode | Decode | Compression | Notes                          |
|----------------|--------|--------|-------------|--------------------------------|
| TypeScript     | 112 ms | 45 ms  | 1.5×        | 1-D DWT only, broken decoder   |
| TypeScript (fixed) | 78 ms | 38 ms | 5.2× | 2-D DWT, proper quantisation |
| C++ / WASM     | 4.2 ms | 1.8 ms | 5.4×        | Cache-blocked, SIMD-ready      |

**Speedup: ~18× encode, ~21× decode** vs fixed TypeScript codec.

Real-time threshold for 30fps: 33 ms per frame.
- TypeScript: **cannot sustain 30fps** (78 ms encode)
- WASM: **700+ fps capable** (4.2 ms encode)

## Binary format

Inner payload (`FrameData.data`):

```
 Offset | Size | Field
--------|------|---------------------------------------
   0–3  |  4 B | Magic: 0x574C5643 ("WLVC")
     4  |  1 B | Version = 1
     5  |  1 B | Frame type: 0 = key, 1 = delta
     6  |  1 B | Quality (1–100)
     7  |  1 B | DWT levels (4)
   8–9  |  2 B | Width (uint16 BE)
 10–11  |  2 B | Height (uint16 BE)
 12–15  |  4 B | Y encoded byte count (uint32 BE)
 16–19  |  4 B | U encoded byte count (uint32 BE)
 20–23  |  4 B | V encoded byte count (uint32 BE)
 24–25  |  2 B | UV width (uint16 BE)
 26–27  |  2 B | UV height (uint16 BE)
  28+   |  var | Y_data | U_data | V_data
```

Each channel's data: RLE-encoded int16 quantised DWT coefficients.

RLE format (little-endian):
```
 0–3 | uint32 n_values  (total int16 count when decoded)
 4–7 | uint32 n_pairs   (number of (value, count) pairs)
 8+  | (int16 value | uint16 count) × n_pairs
```

## Cache optimisation notes

### Column-pass blocking

For a 640×480 frame, processing columns naively means:
- Stride = 640 floats = 2560 bytes
- Cache line = 64 bytes → every 4th column access is a miss

**Solution:** Process 8–16 columns at a time:
1. **Gather** column-major data → local row-major buffer (sequential writes)
2. **Transform** rows inside the buffer (sequential reads/writes, fully in L1)
3. **Scatter** buffer → original column-major positions (sequential reads)

Working set: 8 columns × 480 rows × 4 B = 15 KB → fits in 32 KB L1 cache.

### SIMD

WASM SIMD128 provides 4-wide float32 / int32 operations.  
Emscripten auto-vectorizes when compiling with `-msimd128`.

Motion estimation uses explicit SIMD for SAD (Sum of Absolute Differences):
- 16 uint8 subtractions + absolute values → u8x16 ops
- Horizontal reduction to uint32 → widening in stages

## Troubleshooting

**WASM module fails to load:**
- Check browser console for errors
- Ensure `wlvc.wasm` is in the same directory as `wlvc.js`
- Verify the web server serves `.wasm` with MIME type `application/wasm`

**Build errors:**
- Ensure Emscripten SDK is activated: `source ~/emsdk/emsdk_env.sh`
- Check `emcc --version` (requires ≥ 3.1.0 for WASM SIMD128)

**Runtime errors:**
- Enable WASM SIMD in your browser (Chrome 91+, Firefox 89+, Safari 16.4+)
- For older browsers, rebuild without `-msimd128` (slower but compatible)

## License

Same as the parent project.
