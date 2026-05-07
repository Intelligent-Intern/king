export interface SFUTrack {
  id: string
  kind: 'audio' | 'video'
  label: string
}

export interface SFUTracksEvent {
  roomId: string
  publisherId: string
  publisherUserId: string
  publisherName: string
  tracks: SFUTrack[]
}

export interface SFUEncodedFrame {
  publisherId: string
  publisherUserId?: string
  trackId: string
  timestamp: number
  data?: ArrayBuffer
  dataBase64?: string | null
  type: 'keyframe' | 'delta'
  protected?: Record<string, unknown> | null
  protectedFrame?: string | null
  protectionMode?: 'transport_only' | 'protected' | 'required'
  protocolVersion?: number
  frameSequence?: number
  mediaGeneration?: number
  payloadChars?: number
  chunkCount?: number
  frameId?: string
  senderSentAtMs?: number
  publisherJoinStartedAtMs?: number
  codecId?: string
  runtimeId?: string
  publisherMediaSource?: string
  videoLayer?: 'primary' | 'thumbnail' | string | null
  outgoingVideoQualityProfile?: string
  kingReceiveLatencyMs?: number
  kingFanoutLatencyMs?: number
  subscriberSendLatencyMs?: number
  frameWidth?: number
  frameHeight?: number
  layoutMode?: 'full_frame' | 'tile_foreground' | 'background_snapshot'
  layerId?: 'full' | 'foreground' | 'background'
  cacheEpoch?: number
  tileColumns?: number
  tileRows?: number
  tileWidth?: number
  tileHeight?: number
  tileIndices?: number[] | null
  roiNormX?: number
  roiNormY?: number
  roiNormWidth?: number
  roiNormHeight?: number
  transportMetrics?: Record<string, unknown>
}

export interface SfuSendFailureDetails {
  reason: string
  stage: string
  source: string
  message: string
  transportPath: string
  bufferedAmount: number
  queueLength: number
  queuePayloadChars: number
  activePayloadChars: number
  trackId: string
  chunkCount: number
  payloadChars: number
  payloadBytes: number
  wirePayloadBytes: number
  publisherFrameTraceId: string
  publisherPathTraceStages: string
  retryAfterMs: number
  binaryContinuationState: string
  timestamp: number
}

export interface SfuFrameTransportSample {
  transportPath: string
  payloadBytes: number
  wirePayloadBytes: number
  wireOverheadBytes: number
  wireVsPayloadRatio: number
  websocketBufferedAmount: number
  queueLength: number
  queuePayloadChars: number
  activePayloadChars: number
  trackId: string
  frameType: string
  frameSequence: number
  chunkCount: number
  videoLayer: string
  outgoingVideoQualityProfile: string
  selectedVideoQualityProfile: string
  activeCaptureBackend: string
  sourceFrameWidth: number
  sourceFrameHeight: number
  sourceFrameRate: number
  sourceReadbackMs: number
  droppedSourceFrameCount: number
  automaticQualityTransitionCount: number
  encodeMs: number
  queuedAgeMs: number
  sendDrainMs: number
  sendDrainTargetBytes: number
  sendDrainMaxWaitMs: number
  binaryEnvelopeEncodeMs: number
  websocketSendMs: number
  publisherFrameTraceId: string
  publisherPathTraceStage: string
  publisherPathTraceStages: string
  budgetMaxEncodedBytesPerFrame: number
  budgetMaxWireBytesPerSecond: number
  budgetMaxQueueAgeMs: number
  budgetMaxBufferedBytes: number
  binaryContinuationState: string
  binaryContinuationRequired: boolean
  timestampUnixMs: number
}

export interface SFUClientCallbacks {
  onTracks:        (e: SFUTracksEvent) => void
  onUnpublished:   (publisherId: string, trackId: string) => void
  onPublisherLeft: (publisherId: string) => void
  onConnected?:    () => void
  onDisconnect:    () => void
  onEncodedFrame?: (frame: SFUEncodedFrame) => void
  onPublisherPressure?: (details: Record<string, unknown>) => void
  onSessionAccepted?: (details: Record<string, unknown>) => void
  onTrackAccepted?: (details: Record<string, unknown>) => void
  onJoinLatencySample?: (details: Record<string, unknown>) => void
}
