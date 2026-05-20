import {
  encodeSfuBinaryFrameEnvelope,
  prepareSfuOutboundFramePayload,
} from '../../../../lib/sfu/framePayload';
import { normalizeScreenShareMediaSource } from '../../screenShareIdentity.js';

export const GOSSIP_MEDIA_FRAME_TYPE = 'gossip.media.frame.v1';
export const GOSSIP_MEDIA_FRAME_CONTRACT_VERSION = 'v1.0.0';
export const GOSSIP_MEDIA_FRAME_PROFILE = 'video_720p30';
const GOSSIP_MEDIA_FRAME_WIDTH = 1280;
const GOSSIP_MEDIA_FRAME_HEIGHT = 720;
const GOSSIP_MEDIA_FRAME_RATE = 30;
const GOSSIP_MEDIA_WLVC_CODEC_ID = 'wlvc_v1';
const GOSSIP_MEDIA_WEB_CODECS_CODEC_IDS = Object.freeze(['webcodecs_vp8', 'webcodecs_vp9', 'webcodecs_av1']);

export function positiveGossipInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

export function normalizedGossipFrameKind(value) {
  return String(value || '').trim().toLowerCase() === 'keyframe' ? 'keyframe' : 'delta';
}

function normalizeGossipRuntimePath(value, fallback = 'gossip_rtc_datachannel') {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized === 'gossip_primary_direct' ? 'gossip_primary_direct' : fallback;
}

function gossipExternalCodecId(frame) {
  const explicitCodecId = String(
    frame?.codecId
      || frame?.codec_id
      || frame?.codecRuntime?.codec_id
      || frame?.codec_runtime?.codec_id
      || '',
  ).trim().toLowerCase();
  if (GOSSIP_MEDIA_WEB_CODECS_CODEC_IDS.includes(explicitCodecId)) return explicitCodecId;
  return GOSSIP_MEDIA_WLVC_CODEC_ID;
}

function gossipRuntimeEncoder(frame) {
  const explicitEncoder = String(
    frame?.codecRuntime?.encoder
      || frame?.codec_runtime?.encoder
      || frame?.codecId
      || frame?.codec_id
      || '',
  ).trim().toLowerCase();
  if (GOSSIP_MEDIA_WEB_CODECS_CODEC_IDS.includes(explicitEncoder) || explicitEncoder === 'webcodecs') return 'webcodecs';
  if (explicitEncoder === 'wlvc_ts') return 'wlvc_ts';
  return 'wlvc_wasm';
}

function gossipRuntimeId(frame, encoder) {
  const explicitRuntimeId = String(frame?.runtimeId || frame?.runtime_id || '').trim();
  if (explicitRuntimeId !== '') return explicitRuntimeId;
  return encoder === 'webcodecs' ? 'webcodecs' : 'wlvc_sfu';
}

function gossipMetricValue(frame, camelKey, snakeKey, fallback = 0) {
  const metrics = frame?.transportMetrics && typeof frame.transportMetrics === 'object' ? frame.transportMetrics : {};
  return positiveGossipInteger(
    frame?.[camelKey] ?? frame?.[snakeKey] ?? metrics?.[snakeKey] ?? metrics?.[camelKey],
    fallback,
  );
}

function gossipMetricString(frame, camelKey, snakeKey, fallback = '') {
  const metrics = frame?.transportMetrics && typeof frame.transportMetrics === 'object' ? frame.transportMetrics : {};
  const normalized = String(frame?.[camelKey] ?? frame?.[snakeKey] ?? metrics?.[snakeKey] ?? metrics?.[camelKey] ?? '').trim();
  return normalized !== '' ? normalized : fallback;
}

function gossipParticipantSessionId(frame, callIdValue, roomIdValue, peerId) {
  const explicitSessionId = String(
    frame?.participantSessionId
      || frame?.participant_session_id
      || frame?.publisherSessionId
      || frame?.publisher_session_id
      || '',
  ).trim();
  const rawSessionId = explicitSessionId || `psess_${callIdValue}_${roomIdValue}_${peerId}`;
  const normalized = rawSessionId.replace(/[^A-Za-z0-9_.:-]/g, '_').slice(0, 160);
  return normalized.length >= 8 ? normalized : `psess_${peerId || 'local'}_00000000`.slice(0, 160);
}

function normalizeGossipFrameArrayBuffer(data) {
  if (data instanceof ArrayBuffer) return data;
  if (ArrayBuffer.isView(data)) {
    return data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength);
  }
  return new ArrayBuffer(0);
}

function nextFrameSequenceForTrack(sequenceMap, peerId, trackId) {
  const sequenceKey = `${peerId}:${trackId}`;
  const frameSequence = Math.max(1, Number(sequenceMap.get(sequenceKey) || 0) + 1);
  sequenceMap.set(sequenceKey, frameSequence);
  return frameSequence;
}

function screenShareEnvelopeFieldsFromFrame(frame) {
  const metrics = frame?.transportMetrics && typeof frame.transportMetrics === 'object'
    ? frame.transportMetrics
    : {};
  const publisherMediaSource = normalizeScreenShareMediaSource(
    frame?.publisherMediaSource
      || frame?.publisher_media_source
      || metrics.publisher_media_source
      || metrics.publisherMediaSource
  );
  if (publisherMediaSource === '') return {};

  const ownerUserId = positiveGossipInteger(
    frame?.screenShareOwnerUserId
      || frame?.screen_share_owner_user_id
      || metrics.screen_share_owner_user_id
      || metrics.screenShareOwnerUserId
  );
  const participantUserId = positiveGossipInteger(
    frame?.screenShareParticipantUserId
      || frame?.screen_share_participant_user_id
      || metrics.screen_share_participant_user_id
      || metrics.screenShareParticipantUserId
  );
  return {
    publisher_media_source: publisherMediaSource,
    ...(ownerUserId > 0 ? { screen_share_owner_user_id: ownerUserId } : {}),
    ...(participantUserId > 0 ? { screen_share_participant_user_id: participantUserId } : {}),
  };
}

export function isGossipMediaFrameMessage(msg) {
  const type = String(msg?.type || '').trim();
  return type === GOSSIP_MEDIA_FRAME_TYPE || type === 'sfu/frame';
}

export function gossipFrameMessageFromEncodedFrame(frame, sequenceMap, {
  peerId = '',
  callId = 'call',
  roomId = 'lobby',
  plainRelay = false,
} = {}) {
  if (!frame || typeof frame !== 'object') return null;
  const normalizedPeerId = String(peerId || '').trim();
  if (normalizedPeerId === '' || normalizedPeerId === '0') return null;
  const trackId = String(frame.trackId || '').trim();
  if (trackId === '') return null;

  const frameSequence = nextFrameSequenceForTrack(sequenceMap, normalizedPeerId, trackId);
  const relayData = plainRelay
    ? (frame.relayData || frame.plainData || frame.data)
    : frame.data;
  const dataBuffer = normalizeGossipFrameArrayBuffer(relayData);
  if (dataBuffer.byteLength <= 0) return null;
  const protectedFrame = '';
  const protectionMode = 'transport_only';
  const frameKind = normalizedGossipFrameKind(frame.type || frame.frameType || frame.frame_kind);
  const timestampUnixMs = positiveGossipInteger(frame.timestamp || frame.timestamp_unix_ms, Date.now());
  const runtimePath = plainRelay ? 'gossip_primary_direct' : 'gossip_rtc_datachannel';
  const codecId = gossipExternalCodecId(frame);
  const codecRuntimeEncoder = gossipRuntimeEncoder(frame);
  const runtimeId = gossipRuntimeId(frame, codecRuntimeEncoder);
  const outgoingProfile = gossipMetricString(frame, 'outgoingVideoQualityProfile', 'outgoing_video_quality_profile', 'strict_720p30');
  const selectedProfile = gossipMetricString(frame, 'selectedVideoQualityProfile', 'selected_video_quality_profile', outgoingProfile);
  const profileFrameWidth = gossipMetricValue(frame, 'profileFrameWidth', 'profile_frame_width', GOSSIP_MEDIA_FRAME_WIDTH);
  const profileFrameHeight = gossipMetricValue(frame, 'profileFrameHeight', 'profile_frame_height', GOSSIP_MEDIA_FRAME_HEIGHT);
  const profileFrameRate = gossipMetricValue(frame, 'profileFrameRate', 'profile_frame_rate', GOSSIP_MEDIA_FRAME_RATE);
  const participantSessionId = gossipParticipantSessionId(frame, callId, roomId, normalizedPeerId);
  const frameId = String(frame.frameId || frame.frame_id || '').trim()
    || `gsr_${callId}_${normalizedPeerId}_${trackId.replace(/[^A-Za-z0-9_.:-]/g, '_').slice(0, 80)}_${frameSequence}`;
  return {
    type: GOSSIP_MEDIA_FRAME_TYPE,
    envelope_contract: GOSSIP_MEDIA_FRAME_TYPE,
    contract_version: GOSSIP_MEDIA_FRAME_CONTRACT_VERSION,
    call_id: callId,
    room_id: roomId,
    participant_session_id: participantSessionId,
    protocol_version: 2,
    frame_id: frameId,
    route_id: frameId,
    publisher_id: String(frame.publisherId || normalizedPeerId),
    publisher_user_id: String(frame.publisherUserId || normalizedPeerId),
    ...screenShareEnvelopeFieldsFromFrame(frame),
    track_id: trackId,
    track_kind: 'video',
    timestamp: timestampUnixMs,
    timestamp_unix_ms: timestampUnixMs,
    frame_kind: frameKind,
    frame_type: frameKind,
    frame_sequence: frameSequence,
    sequence: frameSequence,
    sender_sent_at_ms: Date.now(),
    runtime_path: runtimePath,
    codec_id: codecId,
    codec_runtime: {
      codec_id: codecId,
      runtime_path: runtimePath,
      encoder: codecRuntimeEncoder,
    },
    runtime_id: runtimeId,
    profile: GOSSIP_MEDIA_FRAME_PROFILE,
    profile_frame_width: profileFrameWidth,
    profile_frame_height: profileFrameHeight,
    profile_frame_rate: profileFrameRate,
    frame_width: gossipMetricValue(frame, 'frameWidth', 'frame_width', profileFrameWidth),
    frame_height: gossipMetricValue(frame, 'frameHeight', 'frame_height', profileFrameHeight),
    payload_encoding: 'binary',
    protection_mode: protectionMode,
    protected_frame: protectedFrame,
    payload_chars: dataBuffer.byteLength,
    payload_bytes: dataBuffer.byteLength,
    chunk_count: 1,
    outgoing_video_quality_profile: outgoingProfile,
    selected_video_quality_profile: selectedProfile,
    ...(frameKind === 'delta' ? {
      dependency: {
        requires_keyframe_before_delta: true,
        previous_sequence: frameSequence > 1 ? frameSequence - 1 : null,
      },
    } : {}),
    layout_mode: String(frame.layoutMode || 'full_frame'),
    layer_id: String(frame.layerId || 'full'),
    cache_epoch: Math.max(0, Number(frame.cacheEpoch || 0)),
    tile_columns: Math.max(0, Number(frame.tileColumns || 0)),
    tile_rows: Math.max(0, Number(frame.tileRows || 0)),
    tile_width: Math.max(0, Number(frame.tileWidth || 0)),
    tile_height: Math.max(0, Number(frame.tileHeight || 0)),
    tile_indices: Array.isArray(frame.tileIndices) ? frame.tileIndices : [],
    roi_norm_x: Math.max(0, Number(frame.roiNormX || 0)),
    roi_norm_y: Math.max(0, Number(frame.roiNormY || 0)),
    roi_norm_width: Math.max(0, Number(frame.roiNormWidth || 0)),
    roi_norm_height: Math.max(0, Number(frame.roiNormHeight || 0)),
  };
}

export function gossipFrameMetadataFromEncodedFrame(frame, sequenceMap, options = {}) {
  return gossipFrameMessageFromEncodedFrame(frame, sequenceMap, options);
}

export function gossipBinaryEnvelopeFromEncodedFrame(frame, msg) {
  if (!frame || typeof frame !== 'object' || !msg || typeof msg !== 'object') return null;
  const plainRelay = String(msg.runtime_path || '').trim().toLowerCase() === 'gossip_primary_direct';
  const relayData = plainRelay
    ? (frame.relayData || frame.plainData || frame.data)
    : frame.data;
  const dataBuffer = normalizeGossipFrameArrayBuffer(relayData);
  if (dataBuffer.byteLength <= 0) return null;
  const prepared = prepareSfuOutboundFramePayload({
    publisherId: String(msg.publisher_id || msg.publisherId || ''),
    publisherUserId: String(msg.publisher_user_id || msg.publisherUserId || ''),
    trackId: String(msg.track_id || msg.trackId || ''),
    timestamp: positiveGossipInteger(msg.timestamp_unix_ms || msg.timestamp, Date.now()),
    data: dataBuffer,
    type: normalizedGossipFrameKind(msg.frame_kind || msg.frame_type),
    protectionMode: 'transport_only',
    frameSequence: positiveGossipInteger(msg.frame_sequence || msg.sequence, 0),
    senderSentAtMs: positiveGossipInteger(msg.sender_sent_at_ms, Date.now()),
    codecId: String(msg.codec_id || 'wlvc_wasm'),
    runtimeId: String(msg.runtime_id || 'wlvc_sfu'),
    videoLayer: String(msg.video_layer || ''),
    transportMetrics: {
      ...msg,
      media_transport: 'gossip_server_fanout',
      control_transport: 'king_realtime_ws',
      gossip_media_contract: GOSSIP_MEDIA_FRAME_TYPE,
      binary_media_required: true,
    },
  });
  prepared.payload.frame_id = String(msg.frame_id || msg.frameId || '');
  return encodeSfuBinaryFrameEnvelope(prepared);
}

export function gossipFrameBinaryMessageFromMetadata(frame, msg) {
  if (!frame || typeof frame !== 'object' || !msg || typeof msg !== 'object') return null;
  const plainRelay = String(msg.runtime_path || '').trim().toLowerCase() === 'gossip_primary_direct';
  const relayData = plainRelay
    ? (frame.relayData || frame.plainData || frame.data)
    : frame.data;
  const dataBuffer = normalizeGossipFrameArrayBuffer(relayData);
  if (dataBuffer.byteLength <= 0) return null;
  return {
    ...msg,
    payload_encoding: 'binary',
    payload_bytes: dataBuffer.byteLength,
    payload_chars: dataBuffer.byteLength,
    data_binary: new Uint8Array(dataBuffer),
  };
}

export function sfuFrameFromGossipMessage(msg, delivery) {
  const publisherId = String(msg.publisherId || msg.publisher_id || msg.publisher_user_id || '').trim();
  const trackId = String(msg.trackId || msg.track_id || '').trim();
  if (publisherId === '' || trackId === '') return null;
  const dataBinary = normalizeGossipFrameArrayBuffer(msg.dataBinary || msg.data_binary || msg.data);
  if (dataBinary.byteLength <= 0) return null;
  const frameSequence = Math.max(0, Number(msg.frameSequence ?? msg.frame_sequence ?? msg.sequence ?? 0));
  const mediaGeneration = Math.max(0, Number(msg.mediaGeneration ?? msg.media_generation ?? 0));
  const frameKind = normalizedGossipFrameKind(msg.frameKind || msg.frame_kind || msg.frameType || msg.frame_type);
  const timestampUnixMs = positiveGossipInteger(msg.timestampUnixMs ?? msg.timestamp_unix_ms ?? msg.timestamp, 0);
  const runtimePath = normalizeGossipRuntimePath(msg.runtimePath || msg.runtime_path);
  const codecRuntimeEncoder = gossipRuntimeEncoder(msg);
  const externalCodecId = gossipExternalCodecId(msg);
  return {
    publisherId,
    publisherUserId: String(msg.publisherUserId || msg.publisher_user_id || msg.publisher_id || ''),
    publisherMediaSource: normalizeScreenShareMediaSource(msg.publisherMediaSource || msg.publisher_media_source),
    screenShareOwnerUserId: positiveGossipInteger(msg.screenShareOwnerUserId || msg.screen_share_owner_user_id),
    screenShareParticipantUserId: positiveGossipInteger(msg.screenShareParticipantUserId || msg.screen_share_participant_user_id),
    trackId,
    timestamp: timestampUnixMs || msg.timestamp,
    data: dataBinary,
    type: frameKind,
    protected: null,
    protectedFrame: '',
    protectionMode: 'transport_only',
    protocolVersion: Math.max(1, Number(msg.protocolVersion ?? msg.protocol_version ?? 1)),
    frameSequence,
    mediaGeneration,
    payloadChars: Math.max(0, Number(msg.payloadChars ?? msg.payload_chars ?? dataBinary.byteLength)),
    chunkCount: Math.max(1, Number(msg.chunkCount ?? msg.chunk_count ?? 1)),
    frameId: String(msg.frameId || msg.frame_id || delivery?.frame_id || ''),
    senderSentAtMs: Math.max(0, Number(msg.senderSentAtMs ?? msg.sender_sent_at_ms ?? 0)),
    codecId: externalCodecId,
    runtimeId: gossipRuntimeId(msg, codecRuntimeEncoder),
    gossipContractVersion: String(msg.contractVersion || msg.contract_version || ''),
    gossipProfile: String(msg.profile || ''),
    gossipRuntimePath: runtimePath,
    gossipCodecId: externalCodecId,
    codecRuntimeEncoder,
    videoLayer: String(msg.videoLayer || msg.video_layer || ''),
    outgoingVideoQualityProfile: String(msg.outgoingVideoQualityProfile || msg.outgoing_video_quality_profile || msg.profile || ''),
    selectedVideoQualityProfile: String(msg.selectedVideoQualityProfile || msg.selected_video_quality_profile || msg.outgoing_video_quality_profile || msg.profile || ''),
    frameWidth: positiveGossipInteger(msg.frameWidth ?? msg.frame_width ?? msg.profile_frame_width, GOSSIP_MEDIA_FRAME_WIDTH),
    frameHeight: positiveGossipInteger(msg.frameHeight ?? msg.frame_height ?? msg.profile_frame_height, GOSSIP_MEDIA_FRAME_HEIGHT),
    layoutMode: String(msg.layoutMode || msg.layout_mode || 'full_frame'),
    layerId: String(msg.layerId || msg.layer_id || 'full'),
    cacheEpoch: Math.max(0, Number(msg.cacheEpoch ?? msg.cache_epoch ?? 0)),
    tileColumns: Math.max(0, Number(msg.tileColumns ?? msg.tile_columns ?? 0)),
    tileRows: Math.max(0, Number(msg.tileRows ?? msg.tile_rows ?? 0)),
    tileWidth: Math.max(0, Number(msg.tileWidth ?? msg.tile_width ?? 0)),
    tileHeight: Math.max(0, Number(msg.tileHeight ?? msg.tile_height ?? 0)),
    tileIndices: Array.isArray(msg.tileIndices) ? msg.tileIndices : (Array.isArray(msg.tile_indices) ? msg.tile_indices : []),
    roiNormX: Math.max(0, Number(msg.roiNormX ?? msg.roi_norm_x ?? 0)),
    roiNormY: Math.max(0, Number(msg.roiNormY ?? msg.roi_norm_y ?? 0)),
    roiNormWidth: Math.max(0, Number(msg.roiNormWidth ?? msg.roi_norm_width ?? 0)),
    roiNormHeight: Math.max(0, Number(msg.roiNormHeight ?? msg.roi_norm_height ?? 0)),
    transportPath: runtimePath,
  };
}
