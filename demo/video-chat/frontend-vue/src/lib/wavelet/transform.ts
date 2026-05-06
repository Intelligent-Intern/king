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

import { createWaveletCodec, WaveletCodec } from './webrtc-shim.ts'
import { debugLog, debugWarn } from '../../support/debugLogs.ts'

export interface WaveletTransformConfig {
  quality: number
  enableKalman: boolean
  keyFrameInterval: number
  onStats?: (stats: ReturnType<WaveletCodec['getStats']>) => void
}

const DEFAULT_TRANSFORM_CONFIG: WaveletTransformConfig = {
  quality: 40,
  enableKalman: false, // Disabled: stub for future use
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
  return getCodec().getStats()
}

export function resetCodec() {
  getCodec().reset()
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
    debugWarn('[WaveletTransform] createEncodedStreams not supported')
    return
  }

  try {
    const { readable, writable } = sender.createEncodedStreams()
    senderTransform(readable, writable, null as any).catch((error) => {
      debugWarn('[WaveletTransform] Sender transform error:', error)
    })
    debugLog('[WaveletTransform] Sender transform setup complete')
  } catch (error) {
    debugWarn('[WaveletTransform] Failed to setup sender transform:', error)
  }
}

export function setupReceiverTransform(receiver: RTCRtpReceiver): void {
  if (!receiver.createEncodedStreams) {
    debugWarn('[WaveletTransform] createEncodedStreams not supported')
    return
  }

  try {
    const { readable, writable } = receiver.createEncodedStreams()
    receiverTransform(readable, writable, null as any)
    debugLog('[WaveletTransform] Receiver transform setup complete')
  } catch (error) {
    debugWarn('[WaveletTransform] Failed to setup receiver transform:', error)
  }
}

export function isEncodedTransformSupported(): boolean {
  return typeof RTCRtpSender !== 'undefined' && 
         'createEncodedStreams' in RTCRtpSender.prototype
}
