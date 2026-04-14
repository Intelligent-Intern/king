/**
 * WASM-backed wavelet codec — TypeScript wrapper
 *
 * Exposes the same interface as the TypeScript codec (codec.ts) but delegates
 * to the C++/WASM implementation for ~10–50× faster encoding/decoding.
 *
 * Usage:
 *   import { createWasmEncoder, createWasmDecoder } from './wasm/wasm-codec'
 *
 *   const encoder = await createWasmEncoder({ quality: 60, width: 640, height: 480 })
 *   const frameData = encoder.encodeFrame(imageData, timestamp)
 *
 * Falls back to the TypeScript codec if WASM fails to load.
 */

import type { FrameData, DecodedFrame, WaveletCodecConfig } from '../wavelet/codec.js'

// Dynamic import of the Emscripten-generated module
type WLVCModule = {
  Encoder: new (w: number, h: number, quality: number, keyInterval: number) => WasmEncoder
  Decoder: new (w: number, h: number, quality: number) => WasmDecoder
  AudioProcessor: new (sampleRate: number, gateThresh: number, compThresh: number) => WasmAudioProcessor
}

interface WasmEncoder {
  encode(rgba: Uint8Array | Uint8ClampedArray, timestampUs: number): Uint8Array | null
  reset(): void
  delete(): void
}

interface WasmDecoder {
  decode(encoded: Uint8Array): Uint8Array | null
  reset(): void
  delete(): void
}

interface WasmAudioProcessor {
  process(samples: Float32Array): void
  reset(): void
  delete(): void
}

let wasmModule: WLVCModule | null = null
let loadPromise: Promise<WLVCModule | null> | null = null

/**
 * Load the WASM module (singleton, cached across calls).
 */
async function loadWasmModule(): Promise<WLVCModule | null> {
  if (wasmModule) return wasmModule

  if (loadPromise) return loadPromise

  loadPromise = (async () => {
    try {
      const createModule = (await import('./wlvc.js')).default as (config?: any) => Promise<WLVCModule>
      wasmModule = await createModule({
        locateFile: (path: string) => {
          if (path.endsWith('.wasm')) {
            return new URL('./wlvc.wasm', import.meta.url).href
          }
          return path
        },
      })
      console.log('[WASM Codec] Loaded successfully')
      return wasmModule
    } catch (err) {
      console.error('[WASM Codec] Failed to load:', err)
      return null
    }
  })()

  return loadPromise
}

// ---------------------------------------------------------------------------
// Encoder wrapper
// ---------------------------------------------------------------------------

export class WasmWaveletVideoEncoder {
  private encoder: WasmEncoder | null = null
  private config: Required<Pick<WaveletCodecConfig, 'quality' | 'keyFrameInterval'>> & { width: number; height: number }

  constructor(config: Partial<WaveletCodecConfig> & { width: number; height: number }) {
    this.config = {
      width: config.width,
      height: config.height,
      quality: config.quality ?? 75,
      keyFrameInterval: config.keyFrameInterval ?? 30,
    }
  }

  async init(): Promise<boolean> {
    const mod = await loadWasmModule()
    if (!mod) return false

    this.encoder = new mod.Encoder(
      this.config.width,
      this.config.height,
      this.config.quality,
      this.config.keyFrameInterval
    )
    return true
  }

  encodeFrame(imageData: ImageData, timestamp: number): FrameData {
    if (!this.encoder) {
      throw new Error('[WasmEncoder] Not initialized — call await encoder.init() first')
    }

    const timestampUs = timestamp * 1000  // ms → µs
    const encoded = this.encoder.encode(imageData.data, timestampUs)

    if (!encoded) {
      throw new Error('[WasmEncoder] Encode failed')
    }

    // The WASM encoder returns a Uint8Array view that's invalidated on the
    // next encode call.  Copy it to ensure the caller can retain it.
    const data = encoded.slice().buffer

    return {
      type: 'keyframe',  // WASM encoder handles key/delta internally
      timestamp,
      width: this.config.width,
      height: this.config.height,
      data,
      quality: this.config.quality,
    }
  }

  setQuality(quality: number): void {
    this.config.quality = Math.max(1, Math.min(100, quality))
    // Note: changing quality at runtime requires recreating the WASM encoder
    // (the C++ encoder doesn't support dynamic quality changes).
    console.warn('[WasmEncoder] setQuality requires re-initialization (not yet supported)')
  }

  reset(): void {
    this.encoder?.reset()
  }

  destroy(): void {
    this.encoder?.delete()
    this.encoder = null
  }
}

// ---------------------------------------------------------------------------
// Decoder wrapper
// ---------------------------------------------------------------------------

export class WasmWaveletVideoDecoder {
  private decoder: WasmDecoder | null = null
  private config: Required<Pick<WaveletCodecConfig, 'quality'>> & { width: number; height: number }

  constructor(config: Partial<WaveletCodecConfig> & { width: number; height: number }) {
    this.config = {
      width: config.width,
      height: config.height,
      quality: config.quality ?? 75,
    }
  }

  async init(): Promise<boolean> {
    const mod = await loadWasmModule()
    if (!mod) return false

    this.decoder = new mod.Decoder(this.config.width, this.config.height, this.config.quality)
    return true
  }

  decodeFrame(frameData: FrameData): DecodedFrame {
    if (!this.decoder) {
      throw new Error('[WasmDecoder] Not initialized — call await decoder.init() first')
    }

    const encoded = new Uint8Array(frameData.data)
    const rgba = this.decoder.decode(encoded)

    if (!rgba) {
      throw new Error('[WasmDecoder] Decode failed')
    }

    // Copy the WASM heap view to a stable Uint8ClampedArray
    const data = new Uint8ClampedArray(rgba)

    return {
      data,
      width: frameData.width,
      height: frameData.height,
      timestamp: frameData.timestamp,
      quality: frameData.quality,
    }
  }

  setQuality(quality: number): void {
    this.config.quality = Math.max(1, Math.min(100, quality))
    console.warn('[WasmDecoder] setQuality requires re-initialization (not yet supported)')
  }

  reset(): void {
    this.decoder?.reset()
  }

  destroy(): void {
    this.decoder?.delete()
    this.decoder = null
  }
}

// ---------------------------------------------------------------------------
// Factory functions (async because WASM loading is async)
// ---------------------------------------------------------------------------

export async function createWasmEncoder(
  config: Partial<WaveletCodecConfig> & { width: number; height: number }
): Promise<WasmWaveletVideoEncoder | null> {
  const encoder = new WasmWaveletVideoEncoder(config)
  const ok = await encoder.init()
  return ok ? encoder : null
}

export async function createWasmDecoder(
  config: Partial<WaveletCodecConfig> & { width: number; height: number }
): Promise<WasmWaveletVideoDecoder | null> {
  const decoder = new WasmWaveletVideoDecoder(config)
  const ok = await decoder.init()
  return ok ? decoder : null
}

// ---------------------------------------------------------------------------
// Hybrid codec factory — tries WASM, falls back to TypeScript
// ---------------------------------------------------------------------------

import { createEncoder as createTsEncoder, createDecoder as createTsDecoder } from '../wavelet/codec.js'

export async function createHybridEncoder(config: Partial<WaveletCodecConfig> & { width: number; height: number }) {
  const wasm = await createWasmEncoder(config)
  if (wasm) {
    console.log('[Codec] Using WASM encoder')
    return wasm
  }
  console.warn('[Codec] WASM encoder unavailable, falling back to TypeScript')
  return createTsEncoder(config)
}

export async function createHybridDecoder(config: Partial<WaveletCodecConfig> & { width: number; height: number }) {
  const wasm = await createWasmDecoder(config)
  if (wasm) {
    console.log('[Codec] Using WASM decoder')
    return wasm
  }
  console.warn('[Codec] WASM decoder unavailable, falling back to TypeScript')
  return createTsDecoder(config)
}
