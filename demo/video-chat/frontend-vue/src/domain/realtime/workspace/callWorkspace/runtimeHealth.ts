import type { Ref } from 'vue'
import type { MediaRuntimeCapabilities } from '../media/runtimeCapabilities'

// King native infra: IIBIN for binary protocol, WebSocket in sfuClient
import type { IIBINMessage, MessageType } from '../../../../packages/iibin/src/iibin'

export interface MediaSecuritySessionClass {
  supportsNativeTransforms(): boolean
}

export interface RuntimeHealthCallbacks {
  captureClientDiagnostic: (params: {
    category: string
    level: string
    eventType: string
    code: string
    message: string
    payload?: Record<string, unknown>
    immediate?: boolean
  }) => void
  mediaDebugLog: (...args: unknown[]) => void
}

export interface RuntimeHealthConstants {
  mediaSecuritySessionClass: MediaSecuritySessionClass
  defaultNativeAudioBridgeFailureMessage: () => string
  sfuRuntimeEnabled: boolean
}

export interface RuntimeHealthRefs {
  mediaRuntimeCapabilities: Ref<MediaRuntimeCapabilities>
  mediaRuntimePath: Ref<string>
  videoEncoderRef: Ref<{ reset?: () => void } | null>
}

export interface RuntimeHealthHelpers {
  isNativeWebRtcRuntimePath: () => boolean
  isWlvcRuntimePath: () => boolean
  nativeAudioBridgeFailureMessage: () => string
  resetWlvcEncoderAfterDroppedEncodedFrame: (reason?: string) => void
  shouldBlockNativeRuntimeSignaling: () => boolean
  shouldMaintainNativePeerConnections: () => boolean
  shouldSendNativeTrackKind: (kind: string) => boolean
  shouldUseNativeAudioBridge: () => boolean
}

export function createCallWorkspaceRuntimeHealthHelpers({
  callbacks,
  constants,
  refs,
}: {
  callbacks: RuntimeHealthCallbacks
  constants: RuntimeHealthConstants
  refs: RuntimeHealthRefs
}): RuntimeHealthHelpers {
  const {
    mediaDebugLog,
  } = callbacks
  const {
    mediaSecuritySessionClass,
    defaultNativeAudioBridgeFailureMessage,
    sfuRuntimeEnabled,
  } = constants
  const {
    mediaRuntimeCapabilities,
    mediaRuntimePath,
    videoEncoderRef,
  } = refs

  function resetWlvcEncoderAfterDroppedEncodedFrame(reason = 'dropped_encoded_frame'): void {
    const encoder = videoEncoderRef.value
    if (!encoder || typeof encoder.reset !== 'function') return
    try {
      encoder.reset()
    } catch (error) {
      mediaDebugLog('[SFU] WLVC encoder reset after dropped encoded frame failed', reason, error)
    }
  }

  function isWlvcRuntimePath(): boolean {
    return mediaRuntimePath.value === 'wlvc_wasm'
  }

  function isNativeWebRtcRuntimePath(): boolean {
    return mediaRuntimePath.value === 'webrtc_native'
  }

  function shouldUseNativeAudioBridge(): boolean {
    if (!mediaSecuritySessionClass.supportsNativeTransforms()) {
      return false
    }
    return sfuRuntimeEnabled
      && isWlvcRuntimePath()
      && Boolean(mediaRuntimeCapabilities.value.stageB)
  }

  function nativeAudioBridgeFailureMessage(): string {
    return defaultNativeAudioBridgeFailureMessage()
  }

  function shouldMaintainNativePeerConnections(): boolean {
    return isNativeWebRtcRuntimePath() || shouldUseNativeAudioBridge()
  }

  function shouldSendNativeTrackKind(kind: string): boolean {
    const normalizedKind = String(kind || '').trim().toLowerCase()
    if (normalizedKind === 'audio') return shouldMaintainNativePeerConnections()
    if (normalizedKind === 'video') return isNativeWebRtcRuntimePath()
    return false
  }

  function shouldBlockNativeRuntimeSignaling(): boolean {
    return sfuRuntimeEnabled && mediaRuntimePath.value === 'pending'
  }

  return {
    isNativeWebRtcRuntimePath,
    isWlvcRuntimePath,
    nativeAudioBridgeFailureMessage,
    resetWlvcEncoderAfterDroppedEncodedFrame,
    shouldBlockNativeRuntimeSignaling,
    shouldMaintainNativePeerConnections,
    shouldSendNativeTrackKind,
    shouldUseNativeAudioBridge,
  }
}
