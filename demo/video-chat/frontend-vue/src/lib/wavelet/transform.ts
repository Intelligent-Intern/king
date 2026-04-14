/**
 * WebRTC Encoded Transform for Wavelet Codec
 * Pipes video through wavelet encoder/decoder
 */

declare global {
  interface RTCRtpSender {
    createEncodedStreams(): {
      readable: ReadableStream<EncodedVideoChunk>
      writable: WritableStream<EncodedVideoChunk>
    }
  }
  interface RTCRtpReceiver {
    createEncodedStreams(): {
      readable: ReadableStream<EncodedVideoChunk>
      writable: WritableStream<EncodedVideoChunk>
    }
  }
}

import { createWaveletCodec, WaveletCodec } from './webrtc-shim.js'

export interface WaveletTransformConfig {
  quality: number
  enableKalman: boolean
  keyFrameInterval: number
  onStats?: (stats: ReturnType<WaveletCodec['getStats']>) => void
}

const DEFAULT_TRANSFORM_CONFIG: WaveletTransformConfig = {
  quality: 60,
  enableKalman: true,
  keyFrameInterval: 30,
}

let globalCodec: WaveletCodec | null = null
let globalConfig: WaveletTransformConfig = DEFAULT_TRANSFORM_CONFIG

export function setWaveletTransformConfig(config: Partial<WaveletTransformConfig>): void {
  globalConfig = { ...globalConfig, ...config }
  if (globalCodec) {
    globalCodec.reset()
  }
}

export function getWaveletTransformConfig(): WaveletTransformConfig {
  return { ...globalConfig }
}

function getCodec(): WaveletCodec {
  if (!globalCodec) {
    globalCodec = createWaveletCodec({
      quality: globalConfig.quality,
      enableKalman: globalConfig.enableKalman,
      keyFrameInterval: globalConfig.keyFrameInterval,
    })
  }
  return globalCodec
}

export function getCodecStats() {
  if (!globalCodec) return null
  return globalCodec.getStats()
}

export function resetCodec() {
  if (globalCodec) {
    globalCodec.reset()
  }
}

// Wavelet coding happens at the raw-frame level in processor-pipeline.ts.
// At the encoded-chunk level (post-WebRTC-codec) there is nothing to do —
// both transforms are pure pass-throughs kept for future use.

export async function senderTransform(
  readable: ReadableStream<EncodedVideoChunk>,
  writable: WritableStream<EncodedVideoChunk>,
  _controller: TransformStreamDefaultController<EncodedVideoChunk>
): Promise<void> {
  await readable.pipeTo(writable)
}

export async function receiverTransform(
  readable: ReadableStream<EncodedVideoChunk>,
  writable: WritableStream<EncodedVideoChunk>,
  _controller: TransformStreamDefaultController<EncodedVideoChunk>
): Promise<void> {
  await readable.pipeTo(writable)
}

export function setupSenderTransform(sender: RTCRtpSender): void {
  if (!sender.createEncodedStreams) {
    console.warn('[WaveletTransform] createEncodedStreams not supported')
    return
  }

  try {
    const { readable, writable } = sender.createEncodedStreams()
    senderTransform(readable, writable, null as any).catch((error) => {
      console.error('[WaveletTransform] Sender transform error:', error)
    })
    console.log('[WaveletTransform] Sender transform setup complete')
  } catch (error) {
    console.error('[WaveletTransform] Failed to setup sender transform:', error)
  }
}

export function setupReceiverTransform(receiver: RTCRtpReceiver): void {
  if (!receiver.createEncodedStreams) {
    console.warn('[WaveletTransform] createEncodedStreams not supported')
    return
  }

  try {
    const { readable, writable } = receiver.createEncodedStreams()
    receiverTransform(readable, writable, null as any)
    console.log('[WaveletTransform] Receiver transform setup complete')
  } catch (error) {
    console.error('[WaveletTransform] Failed to setup receiver transform:', error)
  }
}

export function isEncodedTransformSupported(): boolean {
  return typeof RTCRtpSender !== 'undefined' && 
         'createEncodedStreams' in RTCRtpSender.prototype
}
