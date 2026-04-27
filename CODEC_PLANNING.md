# Codec Planning

## ✅ Completed

The following is now implemented in both TypeScript and C++:

| Feature | TypeScript | WASM (C++) | Status |
|---------|------------|------------|--------|
| Wavelet types | haar, db4, cdf97 | kHaar, kDB4, kCDF97 | ✅ |
| Entropy coding | rle, arithmetic, none | kRLE, kArithmetic, kNone | ✅ |
| DWT levels | 1-8 configurable | configurable | ✅ |
| Color space | yuv, rgb | kYUV, kRGB | ✅ |
| keyFrameInterval | configurable | configurable | ✅ |
| Motion estimation | ❌ NOT in TS | ✅ in C++ | ✅ |
| Quantizer class | separate | in C++ | ✅ |
| Header format | 32 bytes | 32 bytes | ✅ |

## What Has Been Built

### 1. WASM Rebuild (✅ Done)

C++ source compiled with emscripten:

```bash
cd demo/video-chat/frontend-vue/src/lib/wasm
emcc -O3 -msimd128 --bind -s WASM=1 -s ALLOW_MEMORY_GROWTH=1 \
     -s ENVIRONMENT=web -o wlvc.js \
     cpp/exports.cpp cpp/codec.cpp cpp/dwt.cpp \
     cpp/quantize.cpp cpp/entropy.cpp cpp/motion.cpp cpp/audio.cpp

# Output:
#   wlvc.js  (48KB)
#   wlvc.wasm (37KB)
```

### 2. WASM TypeScript Wrapper (✅ Done)

`wasm-codec.ts` updated with full config interface:

```typescript
export interface WasmCodecConfig {
  width: number
  height: number
  waveletType: 'haar' | 'db4' | 'cdf97'  // map to 0,1,2
  entropyCoding: 'rle' | 'arithmetic' | 'none'  // map to 0,1,2
  dwtLevels: number
  colorSpace: 'yuv' | 'rgb'  // map to 0,1
  quality: number
  keyFrameInterval: number
  motionEstimation: boolean  // map to 0,1
}
```

### 3. WebRTC Shim Update (✅ Done)

`webrtc-shim.ts` updated with full config:

```typescript
export interface WaveletCodecConfig {
  waveletType: 'haar' | 'db4' | 'cdf97'
  entropyCoding: 'rle' | 'arithmetic' | 'none'
  dwtLevels: number
  colorSpace: 'yuv' | 'rgb'
  quality: number
  keyFrameInterval: number
  motionEstimation: boolean
  width: number
  height: number
}
```

### 4. Docker Build Option (Alternative)

If emscripten is not available locally:

```bash
docker run --rm -v $(pwd):/src -u $(id -u) emscripten/emsdk \
  sh -c "cd /src/demo/video-chat/frontend-vue/src/lib/wasm && emcc ..."
```

---

## Usage

After rebuild, WASM and TS will be compatible:

```typescript
// Works with both WASM and TypeScript fallback
const config = {
  waveletType: 'cdf97',    // haar, db4, cdf97
  entropyCoding: 'rle',   // rle, arithmetic, none  
  dwtLevels: 4,           // 1-8
  colorSpace: 'yuv',      // yuv, rgb
  quality: 75,
  keyFrameInterval: 30,
  motionEstimation: true  // only works in WASM
}

// WASM (fast)
const encoder = await createWasmEncoder({ ...config, width: 640, height: 480 })

// TypeScript fallback (slower but works without WASM)
const encoder = createEncoder(config)
```

---

## KingRT Web Multi-Participant Rendering Bugs (✅ Fixed)

### Issue: Wrong Participant Video Display with Multiple Participants

When multiple participants joined a KingRT video call, some participants' video canvases would not render correctly or display the wrong participant's video.

**File**: `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue`

**Bugs Fixed**:

| # | Bug | Location | Fix |
|---|-----|----------|-----|
| 1 | `updateSfuRemotePeerUserId` return value discarded | `handleSFUEncodedFrame` | Capture return, compare, update peer |
| 2 | Missing `renderCallVideoLayout()` for existing peers | `handleSFUEncodedFrame` | Call via `nextTick()` when canvas has no parent |
| 3 | Unsafe null `createdPeer` in Promise chain | `handleSFUEncodedFrame` | Explicit `if (!createdPeer) return` |
| 4 | Unnecessary re-renders on every `sfu/tracks` | `createOrUpdateSfuRemotePeer` | Short-circuit when no actual update |
| 5 | Decode before DOM mount (race) | `handleSFUEncodedFrame` | Wait for `nextTick()` before decode |

---

## Testing Plan

1. **Encode with WASM, decode with TS** - verify compatibility
2. **Encode with TS, decode with WASM** - verify compatibility
3. **Test all wavelet types** - haar, db4, cdf97
4. **Test all entropy modes** - rle, arithmetic, none
5. **Test color spaces** - yuv, rgb
6. **Test DWT levels** - 1, 2, 3, 4
7. **Motion estimation** - verify it works in WASM, NOT in TS
8. **Multi-participant rendering** - verify all participants display correctly
   - Join with 2+ remote publishers
   - Check each canvas renders the correct participant
   - Verify `renderCallVideoLayout` mounts canvases correctly