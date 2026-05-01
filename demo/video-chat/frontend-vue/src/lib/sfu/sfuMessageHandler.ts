import { reportClientDiagnostic } from '../../support/clientDiagnostics'
import { normalizeSfuIdentifier } from './identifiers'
import { stringField, type SfuInboundFrameAssembler } from './inboundFrameAssembler'
import { base64UrlToArrayBuffer } from './framePayload'
import type { SFUClientCallbacks } from './sfuTypes'
import { hasExplicitSfuTileMetadataFields, normalizeTilePatchMetadata } from './tilePatchMetadata'

interface SfuClientMessageHandlerContext {
  callbacks: SFUClientCallbacks
  inboundFrameAssembler: SfuInboundFrameAssembler
  roomId: string
  subscribe: (publisherId: string) => void
}

function integerField(fallback: number, ...values: any[]): number {
  for (const value of values) {
    const normalized = Number(value)
    if (Number.isFinite(normalized)) return Math.floor(normalized)
  }
  return fallback
}

function videoLayerField(...values: any[]): 'primary' | 'thumbnail' | '' {
  for (const value of values) {
    const normalized = stringField(value).trim().toLowerCase()
    if (normalized === 'thumbnail' || normalized === 'thumb' || normalized === 'mini') return 'thumbnail'
    if (normalized === 'primary' || normalized === 'main' || normalized === 'fullscreen') return 'primary'
  }
  return ''
}

export function handleSfuClientMessage(ctx: SfuClientMessageHandlerContext, msg: any): void {
  const { callbacks, inboundFrameAssembler, roomId, subscribe } = ctx

  switch (msg.type) {
    case 'sfu/joined':
      for (const publisherId of (msg.publishers ?? [])) {
        const normalizedPublisherId = stringField(publisherId)
        if (normalizedPublisherId !== '') {
          subscribe(normalizedPublisherId)
        }
      }
      break

    case 'sfu/tracks':
      callbacks.onTracks({
        roomId:          stringField(msg.roomId, msg.room_id),
        publisherId:     stringField(msg.publisherId, msg.publisher_id),
        publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id),
        publisherName:   stringField(msg.publisherName, msg.publisher_name),
        tracks:          msg.tracks ?? [],
      })
      break

    case 'sfu/unpublished':
      callbacks.onUnpublished(
        stringField(msg.publisherId, msg.publisher_id),
        stringField(msg.trackId, msg.track_id),
      )
      break

    case 'sfu/publisher_left':
      callbacks.onPublisherLeft(stringField(msg.publisherId, msg.publisher_id))
      break

    case 'sfu/frame':
      if (callbacks.onEncodedFrame) {
        const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)
        const dataBase64 = stringField(msg.dataBase64, msg.data_base64)
        const payloadChars = Math.max(0, integerField(0, msg.payloadChars, msg.payload_chars))
        const tileMetadataInput = {
          layoutMode: msg.layoutMode ?? msg.layout_mode,
          layerId: msg.layerId ?? msg.layer_id,
          cacheEpoch: msg.cacheEpoch ?? msg.cache_epoch,
          tileColumns: msg.tileColumns ?? msg.tile_columns,
          tileRows: msg.tileRows ?? msg.tile_rows,
          tileWidth: msg.tileWidth ?? msg.tile_width,
          tileHeight: msg.tileHeight ?? msg.tile_height,
          tileIndices: msg.tileIndices ?? msg.tile_indices,
          roiNormX: msg.roiNormX ?? msg.roi_norm_x,
          roiNormY: msg.roiNormY ?? msg.roi_norm_y,
          roiNormWidth: msg.roiNormWidth ?? msg.roi_norm_width,
          roiNormHeight: msg.roiNormHeight ?? msg.roi_norm_height,
        }
        const tileMetadata = normalizeTilePatchMetadata(tileMetadataInput)
        if (inboundFrameAssembler.rejectFramePayloadLengthMismatch(msg)) return
        if (!tileMetadata && hasExplicitSfuTileMetadataFields(tileMetadataInput)) {
          reportClientDiagnostic({
            category: 'media',
            level: 'warning',
            eventType: 'sfu_frame_rejected',
            code: 'sfu_frame_rejected',
            message: 'SFU frame used invalid tile/layer/cache metadata and was rejected.',
            roomId,
            payload: {
              room_id: roomId,
              publisher_id: stringField(msg.publisherId, msg.publisher_id),
              publisher_user_id: stringField(msg.publisherUserId, msg.publisher_user_id),
              track_id: stringField(msg.trackId, msg.track_id),
              frame_id: stringField(msg.frameId, msg.frame_id),
              frame_sequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence)),
              reject_reason: 'invalid_tile_metadata',
            },
            immediate: true,
          })
          return
        }
        callbacks.onEncodedFrame({
          publisherId: stringField(msg.publisherId, msg.publisher_id),
          publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id),
          trackId: stringField(msg.trackId, msg.track_id),
          timestamp: msg.timestamp,
          data: dataBase64 !== ''
            ? base64UrlToArrayBuffer(dataBase64)
            : (msg.data instanceof ArrayBuffer
              ? msg.data
              : (Array.isArray(msg.data) ? new Uint8Array(msg.data).buffer : new ArrayBuffer(0))),
          dataBase64: dataBase64 || null,
          type: stringField(msg.frameType, msg.frame_type) === 'keyframe' ? 'keyframe' : 'delta',
          protected: msg.protected && typeof msg.protected === 'object' ? msg.protected : null,
          protectedFrame: protectedFrame || null,
          protectionMode: stringField(msg.protectionMode, msg.protection_mode) === 'required'
            ? 'required'
            : (protectedFrame !== '' ? 'protected' : 'transport_only'),
          protocolVersion: Math.max(1, integerField(1, msg.protocolVersion, msg.protocol_version)),
          frameSequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence)),
          payloadChars,
          chunkCount: Math.max(1, integerField(1, msg.chunkCount, msg.chunk_count)),
          frameId: stringField(msg.frameId, msg.frame_id),
          senderSentAtMs: Math.max(0, integerField(0, msg.senderSentAtMs, msg.sender_sent_at_ms)),
          codecId: stringField(msg.codecId, msg.codec_id),
          runtimeId: stringField(msg.runtimeId, msg.runtime_id),
          videoLayer: videoLayerField(msg.videoLayer, msg.video_layer),
          outgoingVideoQualityProfile: stringField(
            msg.outgoingVideoQualityProfile,
            msg.outgoing_video_quality_profile,
          ),
          kingReceiveLatencyMs: Math.max(0, Number(msg.kingReceiveLatencyMs ?? msg.king_receive_latency_ms ?? 0)),
          kingFanoutLatencyMs: Math.max(0, Number(msg.kingFanoutLatencyMs ?? msg.king_fanout_latency_ms ?? 0)),
          subscriberSendLatencyMs: Math.max(0, Number(msg.subscriberSendLatencyMs ?? msg.subscriber_send_latency_ms ?? 0)),
          frameWidth: Math.max(0, integerField(0, msg.frameWidth, msg.frame_width)),
          frameHeight: Math.max(0, integerField(0, msg.frameHeight, msg.frame_height)),
          layoutMode: tileMetadata?.layoutMode || 'full_frame',
          layerId: tileMetadata?.layerId || 'full',
          cacheEpoch: Math.max(0, Number(tileMetadata?.cacheEpoch || 0)),
          tileColumns: Math.max(0, Number(tileMetadata?.tileColumns || 0)),
          tileRows: Math.max(0, Number(tileMetadata?.tileRows || 0)),
          tileWidth: Math.max(0, Number(tileMetadata?.tileWidth || 0)),
          tileHeight: Math.max(0, Number(tileMetadata?.tileHeight || 0)),
          tileIndices: Array.isArray(tileMetadata?.tileIndices) ? tileMetadata.tileIndices : null,
          roiNormX: Number(tileMetadata?.roiNormX ?? 0),
          roiNormY: Number(tileMetadata?.roiNormY ?? 0),
          roiNormWidth: Number(tileMetadata?.roiNormWidth ?? 1),
          roiNormHeight: Number(tileMetadata?.roiNormHeight ?? 1),
        })
      }
      break

    case 'sfu/frame-chunk': {
      reportClientDiagnostic({
        category: 'media',
        level: 'error',
        eventType: 'sfu_legacy_frame_chunk_rejected',
        code: 'binary_media_required',
        message: 'SFU media chunks must use binary media envelopes.',
        roomId,
        payload: {
          room_id: roomId,
          command_type: 'sfu/frame-chunk',
          publisher_id: stringField(msg.publisherId, msg.publisher_id),
          publisher_user_id: stringField(msg.publisherUserId, msg.publisher_user_id),
          track_id: stringField(msg.trackId, msg.track_id),
          frame_id: stringField(msg.frameId, msg.frame_id),
          frame_sequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence)),
          reject_reason: 'binary_media_required',
          transport_path: 'legacy_json_media_chunk',
        },
        immediate: true,
      })
      break
    }

    case 'sfu/publisher-pressure':
      callbacks.onPublisherPressure?.({
        reason: stringField(msg.reason, msg.code, 'sfu_publisher_pressure'),
        trackId: stringField(msg.trackId, msg.track_id),
        track_id: stringField(msg.trackId, msg.track_id),
        queueAgeMs: Math.max(0, Number(msg.queueAgeMs ?? msg.queue_age_ms ?? 0)),
        queue_age_ms: Math.max(0, Number(msg.queueAgeMs ?? msg.queue_age_ms ?? 0)),
        budgetMaxQueueAgeMs: Math.max(0, Number(msg.budgetMaxQueueAgeMs ?? msg.budget_max_queue_age_ms ?? 0)),
        budget_max_queue_age_ms: Math.max(0, Number(msg.budgetMaxQueueAgeMs ?? msg.budget_max_queue_age_ms ?? 0)),
        kingReceiveLatencyMs: Math.max(0, Number(msg.kingReceiveLatencyMs ?? msg.king_receive_latency_ms ?? 0)),
        payloadBytes: Math.max(0, Number(msg.payloadBytes ?? msg.payload_bytes ?? 0)),
        retryAfterMs: Math.max(0, Number(msg.retryAfterMs ?? msg.retry_after_ms ?? 0)),
        stage: 'sfu_ingress_latency_guard',
        source: 'king_sfu_gateway',
        transportPath: 'binary_envelope',
        message: 'King SFU dropped a stale ingress frame and requested publisher backpressure recovery.',
      })
      break

    case 'sfu/publisher-recovery-request':
      callbacks.onPublisherPressure?.({
        reason: stringField(msg.reason, msg.code, 'sfu_publisher_recovery_request'),
        trackId: stringField(msg.trackId, msg.track_id),
        track_id: stringField(msg.trackId, msg.track_id),
        requesterId: stringField(msg.requesterId, msg.requester_id),
        requester_id: stringField(msg.requesterId, msg.requester_id),
        requesterUserId: stringField(msg.requesterUserId, msg.requester_user_id),
        requester_user_id: stringField(msg.requesterUserId, msg.requester_user_id),
        requestedAction: stringField(msg.requestedAction, msg.requested_action),
        requested_action: stringField(msg.requestedAction, msg.requested_action),
        requestFullKeyframe: Boolean(msg.requestFullKeyframe ?? msg.request_full_keyframe),
        request_full_keyframe: Boolean(msg.requestFullKeyframe ?? msg.request_full_keyframe),
        requestedVideoLayer: stringField(msg.requestedVideoLayer, msg.requested_video_layer),
        requested_video_layer: stringField(msg.requestedVideoLayer, msg.requested_video_layer),
        requestedVideoQualityProfile: stringField(
          msg.requestedVideoQualityProfile,
          msg.requested_video_quality_profile,
        ),
        requested_video_quality_profile: stringField(
          msg.requestedVideoQualityProfile,
          msg.requested_video_quality_profile,
        ),
        frameSequence: Math.max(0, Number(msg.frameSequence ?? msg.frame_sequence ?? 0)),
        frame_sequence: Math.max(0, Number(msg.frameSequence ?? msg.frame_sequence ?? 0)),
        stage: 'sfu_media_recovery_request',
        source: 'king_sfu_gateway',
        transportPath: stringField(msg.mediaTransport, msg.media_transport, 'websocket_binary_media_fallback'),
        controlTransport: stringField(msg.controlTransport, msg.control_transport, 'websocket_sfu_control'),
        message: 'A remote SFU receiver requested publisher-side media recovery without restarting the socket.',
      })
      break

    case 'sfu/error':
      reportClientDiagnostic({
        category: 'media',
        level: 'error',
        eventType: 'sfu_command_error',
        code: normalizeSfuIdentifier(stringField(msg.error), 'sfu_command_error'),
        message: 'SFU command failed.',
        roomId: stringField(msg.roomId, msg.room_id),
        payload: {
          room_id: stringField(msg.roomId, msg.room_id),
          command_type: stringField(msg.commandType, msg.command_type),
          error: stringField(msg.error),
        },
        immediate: true,
      })
      break
  }
}
