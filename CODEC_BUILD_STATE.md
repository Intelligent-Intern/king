# Codec Build State

## Current Implementation Status (experiments/1.0.7-video-codec)

### King's Native Infrastructure Integration

The wavelet codec integrates with King's native infrastructure:

| Component | King's Function | Usage |
|-----------|-----------------|-------|
| HTTP API | `fetchBackend()` | Configuration, session management |
| Real-time | `king_client_websocket_*` | Encoded frame transport via SFU gateway |
| Storage | `king_object_store_put/get` | Archive/backup encoded frames |

### Binary Protocol (IIBIN-style)

All SFU traffic uses binary protocol via `king_websocket_send(..., true)`:

| Message | Type ID | Payload |
|---------|--------|--------|
| JOIN | 0x01 | varint(roomId), varint(role) |
| JOINED | 0x02 | varint(roomId), varint(publishers)... |
| PUBLISH | 0x03 | varint(trackId), varint(kind), varint(label) |
| PUBLISHED | 0x04 | varint(trackId), varint(serverTime) |
| UNPUBLISH | 0x05 | varint(trackId) |
| UNPUBLISHED | 0x06 | varint(publisherId), varint(trackId) |
| SUBSCRIBE | 0x07 | varint(publisherId) |
| TRACKS | 0x09 | varint(roomId), varint(publisherId), varint(userId), varint(name), tracks... |
| FRAME | 0x0A | [binary: magic(4) + frameType(1) + timestamp(4) + length(4) + trackId(8) + data] |
| PUBLISHER_LEFT | 0x0B | varint(publisherId) |
| LEAVE | 0x0C | (empty) |
| WELCOME | 0x0D | varint(userId), varint(name), varint(roomId) |
| ERROR | 0xFF | varint(message) |

**Efficiency**: ~3x smaller than JSON (varint strings + binary frames)

### TypeScript Fallback Implementation

#### ✅ Complete
- **config.ts** - Codec configuration interface
  - WaveletType: 'haar' | 'db4' | 'cdf97'
  - EntropyMode: 'rle' | 'arithmetic' | 'none'
  - ColorSpace: 'yuv' | 'rgb'
  - dwtLevels: 1-8
  - qualityFactor: 1-100
  - keyframeInterval

- **wavelets.ts** - Wavelet implementations
  - HaarWavelet class
  - DB4Wavelet class
  - CDF97Wavelet class
  - getWavelet() factory

- **quantize.ts** - Quantization and entropy
  - Quantizer class (separate)
  - ArithmeticEncoder class
  - runLengthEncode/Decode functions

- **codec.ts** - Main encoder/decoder (updated to use config)
  - ✅ WaveletVideoEncoder with full config support
  - ✅ WaveletVideoDecoder with full config support  
  - ✅ YUV 4:2:0 or RGB modes
  - ✅ Entropy coding switch (rle/arithmetic/none)
  - ✅ Configurable DWT levels
  - ✅ 32-byte header matching WASM format
  - ⚠️ Motion estimation NOT in TS (WASM only, as specified)

- **dwt.ts** - Legacy DWT implementation (still present)

- **index.ts** - Exports all components

### WASM Implementation (C++ Source)

#### ✅ Complete Update (Built)
- **wasm/cpp/codec.h** - Updated with:
  - WaveletType enum (kHaar=0, kDB4=1, kCDF97=2)
  - ColorSpace enum (kYUV=0, kRGB=1)
  - EntropyMode enum (kRLE=0, kArithmetic=1, kNone=2)
  - Expanded EncoderConfig with all options
  - Expanded DecoderConfig
  - Header expanded to 32 bytes

- **wasm/cpp/codec.cpp** - Updated with:
  - Configurable DWT levels
  - Configurable color space (YUV/RGB)
  - Configurable entropy mode
  - Motion estimation flag (stub)
  - Extended header

- **wasm/cpp/exports.cpp** - Full constructor params exposed:
  - `Encoder(w, h, quality, keyInterval, levels, wavelet, colorSpace, entropy, motion)`
  - `Decoder(w, h, quality, levels, wavelet, colorSpace, entropy)`

- **Other cpp files** (unchanged):
  - dwt.cpp/h - 2D separable Haar DWT with cache blocking
  - quantize.cpp/h - Per-subband quantization
  - entropy.cpp/h - RLE encoding/decoding
  - motion.cpp/h - Motion estimation (WASM only)
  - audio.cpp/h - Audio processing

- **Built output**:
  - wlvc.js (48KB)
  - wlvc.wasm (37KB)

### WebRTC/SFU Integration

- **webrtc-shim.ts** - WaveletCodec class
  - Auto-fallback: WASM → TypeScript
  - Config exposed but limited to quality/keyFrameInterval - **needs update**
  - **Kalman filter disabled** (as specified)

- **processor-pipeline.ts** - Video processing pipeline
- **transform.ts** - WebRTC encoded transform (pass-through, for future use)
- **sfuClient.ts** - SFU signalling for frame transport via King's websocket
  - ✅ **Binary protocol** (IIBIN-style encoding)
  - ✅ Varint + string encoding  
  - ✅ Binary WebSocket frames (no JSON)
  - ✅ Frame relay: binary → binary

### WebRTC/SFU Integration

| Feature | TypeScript Fallback | WASM (C++) | Match |
|---------|---------------------|------------|-------|
| Wavelet types | ✅ haar, db4, cdf97 config | ✅ kHaar, kDB4, kCDF97 | ✅ |
| Entropy coding | ✅ rle, arithmetic, none | ✅ kRLE, kArithmetic, kNone | ✅ |
| DWT levels | ✅ configurable 1-8 | ✅ configurable | ✅ |
| Color space | ✅ yuv/rgb | ✅ kYUV/kRGB | ✅ |
| keyFrameInterval | ✅ configurable | ✅ configurable | ✅ |
| Motion estimation | ❌ NOT in TS | ✅ in motion.cpp | ✅ |
| Quantizer class | ✅ separate | ✅ in source | ✅ |
| Header size | ✅ 32 bytes | ✅ 32 bytes | ✅ |

**Status**: Integration complete. WASM rebuilt with full config. TypeScript matches header format.
**Transport**: Frames are sent via existing King websocket infrastructure (sfuClient → realtime_websocket)

### SFU Multi-Participant Rendering Fixes (CallWorkspaceView.vue)

#### Bugs Fixed

1. **Peer reference return value ignored** (line ~6788)
   - `updateSfuRemotePeerUserId` return value discarded when different from input
   - `remotePeersRef` never updated with correct userId/displayName
   - Fixed: capture return, compare, update peer reference

2. **Missing layout trigger** (line ~6809)
   - Existing peers drawn but `renderCallVideoLayout()` never called
   - Fixed: call `renderCallVideoLayout()` via `nextTick()` when canvas has no parent

3. **Unsafe null in Promise chain** (line ~6797)
   - `null` createdPeer silently passed to `decodeSfuFrameForPeer`
   - Fixed: explicit `if (!createdPeer) return`

4. **Unnecessary re-renders** (line ~4852)
   - `await nextTick()` + `renderCallVideoLayout()` on every `sfu/tracks` event
   - Fixed: short-circuit when no actual update needed

5. **Decode before DOM mount** (line ~6803)
   - Canvas drawn before `nextTick()` completes
   - Fixed: wait for `nextTick()` before `decodeSfuFrameForPeer`

#### Files Modified
- `src/domain/realtime/CallWorkspaceView.vue` - `handleSFUEncodedFrame`, `createOrUpdateSfuRemotePeer`