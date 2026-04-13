# Wavelet Video Codec

Pure TypeScript Haar DWT wavelet codec for real-time video compression in WebRTC pipelines. Used as the fallback when the WASM codec (`src/lib/wasm/`) is unavailable.

## Architecture

```
processor-pipeline.ts       — drives encode→decode per animation frame, exposes stats
    └── webrtc-shim.ts      — outer container: wraps inner payload, manages WASM/TS swap
            ├── codec.ts    — inner codec: Haar DWT, YUV 4:2:0, RLE, temporal prediction
            └── wasm/       — C++ WASM codec (preferred when loaded)
```

## Files

| File | Purpose |
|------|---------|
| `codec.ts` | Core encoder/decoder. 2-D separable Haar DWT, YUV 4:2:0 chroma subsampling, RLE entropy coding, closed-loop temporal residual on Y channel. |
| `webrtc-shim.ts` | Wraps the inner codec in an outer 24-byte WLVC header. Initialises the WASM codec in the background and swaps it in transparently once ready. |
| `processor-pipeline.ts` | `WaveletVideoProcessor` — takes a `MediaStream`, runs encode→decode every frame, outputs a processed `MediaStream`. |
| `transform.ts` | WebRTC Encoded Transform hooks (sender/receiver). Currently pass-through; wavelet is applied at the raw-frame level in `processor-pipeline.ts`. |
| `dwt.ts` | 2-D separable Haar DWT (forward + inverse), `rgbToYuv`/`yuvToRgb` helpers. |
| `quantize.ts` | Per-subband quantization, dead-zone, arithmetic/RLE entropy coding utilities. |
| `fast-codec.ts` | Lightweight fast-path codec (no Kalman, reduced levels). |
| `processor.ts` | `VideoFrameProcessor` — frame-level encode/decode with Kalman filtering on the Y channel. |

## Wire Format

### Outer header (webrtc-shim — 24 bytes, all little-endian Uint32)

| Offset | Field | Value |
|--------|-------|-------|
| 0 | magic | `0x574C5643` ("WLVC") |
| 4 | width | frame width in px |
| 8 | height | frame height in px |
| 12 | isKeyFrame | 1 = key, 0 = delta |
| 16 | byteLength | inner payload size |
| 20 | chunkId | monotonic counter |

### Inner payload (codec.ts — 28-byte header, all big-endian)

| Offset | Field |
|--------|-------|
| 0–3 | magic `0x574C5643` |
| 4 | version = 1 |
| 5 | frame_type: 0 = key, 1 = delta |
| 6 | quality (1–100) |
| 7 | DWT levels |
| 8–9 | width (uint16) |
| 10–11 | height (uint16) |
| 12–15 | Y channel byte count |
| 16–19 | U channel byte count |
| 20–23 | V channel byte count |
| 24–25 | UV width (uint16) |
| 26–27 | UV height (uint16) |
| 28+ | Y data \| U data \| V data (RLE-encoded Int16) |

## Key Design Decisions

- **Closed-loop temporal prediction** — the encoder reconstructs exactly what the decoder will produce (dequantize + IDWT) before saving `previousY`. This keeps encoder and decoder in sync across delta frames and eliminates quality drift.
- **YUV coefficients** — non-standard but self-consistent matched pair. Do not replace with BT.601; the forward and inverse matrices are verified inverses of each other.
- **WASM-first** — `webrtc-shim.ts` starts with the TS codec and asynchronously initialises the WASM codec (`src/lib/wasm/`). Once both encoder and decoder report ready, it swaps them in without interrupting the stream. The WASM codec provides ~7× better compression and ~2× faster encode/decode via motion estimation and SIMD.
- **Outer header endianness** — the outer header is written by `Uint32Array` (native little-endian) and read with `DataView.getUint32(n, true)` (explicit LE). The inner payload uses `DataView` with `false` (big-endian) throughout.

## Stats

Accessible via `waveletProcessor.getStats()`:

| Field | Description |
|-------|-------------|
| `framesProcessed` | Total frames encoded |
| `keyFrames` | Key frames encoded |
| `deltaFrames` | Delta frames encoded |
| `compressionRatio` | Raw bytes / encoded bytes |
| `avgEncodeTimeMs` | Rolling average encode time |
| `avgDecodeTimeMs` | Rolling average decode time |

Typical numbers with WASM codec at quality=65: **~19× compression**, **~12ms encode**, **~9ms decode** at 640×480.
