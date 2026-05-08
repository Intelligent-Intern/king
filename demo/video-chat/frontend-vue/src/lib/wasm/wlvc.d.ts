export {}

declare module './wlvc.js' {
  interface WLVCModule {
    Encoder: new (w: number, h: number, quality: number, keyInterval: number) => any
    Decoder: new (w: number, h: number, quality: number) => any
    AudioProcessor: new (sampleRate: number, gateThresh: number, compThresh: number) => any
    BackgroundMatteRefiner?: new (width: number, height: number, preset: number) => {
      refine(rgbaMask: Uint8Array | Uint8ClampedArray): Uint8Array | null
      segment(rgba: Uint8Array | Uint8ClampedArray): Uint8Array | null
      reset(): void
      delete(): void
    }
  }
  export default function createWLVCModule(config?: { ENVIRONMENT?: string }): Promise<WLVCModule>
}
