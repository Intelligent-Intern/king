export {}

declare module './wlvc.js' {
  interface WLVCModule {
    Encoder: new (w: number, h: number, quality: number, keyInterval: number) => any
    Decoder: new (w: number, h: number, quality: number) => any
    AudioProcessor: new (sampleRate: number, gateThresh: number, compThresh: number) => any
  }
  export default function createWLVCModule(config?: { ENVIRONMENT?: string }): Promise<WLVCModule>
}