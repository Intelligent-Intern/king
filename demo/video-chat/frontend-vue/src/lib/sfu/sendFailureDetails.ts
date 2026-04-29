import type { PreparedSfuOutboundFramePayload } from './framePayload'
import type { SfuSendFailureDetails } from './sfuTypes'

interface SfuSendFailureInput {
  reason: string
  stage: string
  source: string
  message: string
  transportPath: string
  bufferedAmount: number
  retryAfterMs?: number
}

interface SfuSendFailureQueueMetrics {
  queueLength: number
  queuePayloadChars: number
  activePayloadChars: number
}

export function buildSfuSendFailureDetails(
  prepared: PreparedSfuOutboundFramePayload,
  details: SfuSendFailureInput,
  queueMetrics: SfuSendFailureQueueMetrics,
): SfuSendFailureDetails {
  return {
    reason: String(details.reason || 'unknown_send_failure'),
    stage: String(details.stage || 'unknown_stage'),
    source: String(details.source || 'unknown_source'),
    message: String(details.message || 'Unknown SFU send failure.'),
    transportPath: String(details.transportPath || 'unknown_transport'),
    bufferedAmount: Math.max(0, Number(details.bufferedAmount || 0)),
    queueLength: Math.max(0, Number(queueMetrics.queueLength || 0)),
    queuePayloadChars: Math.max(0, Number(queueMetrics.queuePayloadChars || 0)),
    activePayloadChars: Math.max(0, Number(queueMetrics.activePayloadChars || 0)),
    trackId: String(prepared.trackId || ''),
    chunkCount: Math.max(1, Number(prepared.chunkCount || 1)),
    payloadChars: Math.max(0, Number(prepared.payloadChars || 0)),
    payloadBytes: Math.max(0, Number(prepared.metrics?.payload_bytes || 0)),
    wirePayloadBytes: Math.max(
      0,
      Number(prepared.metrics?.projected_binary_envelope_bytes || prepared.projectedBinaryEnvelopeBytes || 0),
    ),
    retryAfterMs: Math.max(0, Number(details.retryAfterMs || 0)),
    binaryContinuationState: String(prepared.metrics?.binary_continuation_state || 'unknown_binary_continuation_state'),
    timestamp: Math.max(0, Number(prepared.timestamp || 0)),
  }
}
