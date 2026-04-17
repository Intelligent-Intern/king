# Wavelet Codec Test

Standalone test for comparing the wavelet video codec implementations with live camera input.

## Usage

```bash
cd /Users/sasha/king/demo/video-chat/frontend-vue
npx serve . -p 3000
```

Then open http://localhost:3000/codec-test.html

## Panels

1. **Original Camera** - Live webcam feed
2. **Wavelet** - JavaScript implementation (baseline)
3. **Wavelet + Kalman** - JS with Kalman motion tracking (for future use)
4. **WASM** - C++ compiled implementation (primary codec)

## Configuration

From live testing with 320x240 webcam:

| Codec | Quality | Key Interval | Compression | PSNR |
|-------|---------|-------------|-------------|-----|
| WASM  | 40      | 2           | ~20x        | ~35dB |
| JS    | 40      | 30         | ~5x         | ~35dB |

### Recommended Settings
- **quality**: 40 - best balance of quality vs compression
- **keyFrameInterval**: 2 - prevents temporal drift (smearing)

## WASM vs JavaScript

The WASM implementation provides ~4x better compression than the pure JavaScript version due to:
1. More efficient RLE encoding
2. Better memory layout
3. Key frame every 2nd frame prevents accumulated prediction errors

If WASM fails to load, the system falls back to JavaScript wavelet encoding automatically.

## Color

All implementations process full color (RGBA). Grayscale is only used for internal Kalman motion estimation.