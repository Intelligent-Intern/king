import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-video-recovery-timing-contract] FAIL: ${message}`);
}

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `missing ${label}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');

try {
  const runtimeConfig = read('src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
  const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.js');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const remoteCanvas = read('src/domain/realtime/sfu/remoteCanvas.js');
  const remotePeers = read('src/domain/realtime/sfu/remotePeers.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.js');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const template = read('src/domain/realtime/CallWorkspaceView.template.html');
  const stageCss = read('src/domain/realtime/CallWorkspaceStage.css');

  requireContains(runtimeConfig, 'export const REMOTE_VIDEO_FREEZE_THRESHOLD_MS = 2000;', 'two second rendered-video freeze threshold');
  requireContains(runtimeConfig, 'export const REMOTE_VIDEO_STALL_THRESHOLD_MS = 3000;', 'short initial no-frame stall threshold');
  requireContains(runtimeConfig, 'export const REMOTE_VIDEO_STALL_CHECK_INTERVAL_MS = 1000;', 'one second remote video health cadence');
  requireContains(runtimeConfig, 'export const SFU_VIDEO_RECOVERY_RECONNECT_COOLDOWN_MS = 5000;', 'bounded SFU reconnect cooldown');
  requireContains(runtimeConfig, "'call/media-quality-pressure'", 'targeted remote quality-pressure signaling type');

  requireContains(runtimeHealth, "setRemoteVideoStatus(peer, 'recovering', 'Reconnecting video', nowMs);", 'remote recovery status update');
  requireContains(runtimeHealth, "retrySfuSubscription(publisherId, peer, 'remote_video_frozen', nowMs);", 'frozen video resubscribe retry');
  requireContains(runtimeHealth, "retrySfuSubscription(publisherId, peer, 'remote_video_decoder_waiting_keyframe', nowMs);", 'fresh receive/keyframe-wait resubscribe retry');
  requireContains(runtimeHealth, "eventType: 'sfu_remote_video_decoder_waiting_keyframe'", 'fresh receive/keyframe-wait diagnostic');
  requireContains(runtimeHealth, 'function sendRemoteSfuVideoQualityPressure', 'remote freezes can request sender-side quality downgrade');
  requireContains(runtimeHealth, "type: 'call/media-quality-pressure'", 'remote freeze quality pressure uses targeted call signal');
  requireContains(runtimeHealth, 'peer.freezeRecoveryCount >= 2', 'remote freeze quality pressure waits for two recovery hits');
  requireContains(runtimeHealth, "requested_action: requestFullKeyframe ? 'force_full_keyframe' : 'downgrade_outgoing_video'", 'fresh receive/keyframe-wait asks publisher for full-frame keyframe');
  requireContains(runtimeHealth, 'const shouldSendRemoteQualityPressure = receivingFreshFrames || peer.freezeRecoveryCount >= 2;', 'fresh receive/keyframe-wait bypasses the second-freeze delay');
  requireContains(runtimeHealth, 'const shouldRestartFrozenVideo = receiveGapMs >= remoteVideoReconnectThresholdMs();', 'frozen video restart waits for sustained receive loss');
  requireContains(runtimeHealth, 'if (shouldRestartFrozenVideo) {', 'frozen video reconnect is gated after staged recovery');
  requireContains(runtimeHealth, 'remote_quality_pressure_sent', 'remote freeze diagnostics include remote quality-pressure result');
  requireContains(runtimeHealth, 'socket_restart_deferred', 'remote freeze diagnostics expose deferred socket restart');
  requireContains(runtimeHealth, 'stalledAgeMs >= remoteVideoStallThresholdMs * 2', 'never-started video reconnect timing');
  requireContains(socketLifecycle, "type === 'call/media-quality-pressure'", 'socket lifecycle consumes remote quality-pressure signal');
  requireContains(socketLifecycle, "downgradeSfuVideoQualityAfterEncodePressure('sfu_remote_quality_pressure')", 'remote quality pressure lowers sender outgoing quality');
  requireContains(socketLifecycle, 'requestWlvcFullFrameKeyframe', 'remote keyframe wait is routed into publisher full-frame keyframe recovery');
  requireContains(socketLifecycle, 'full_keyframe_requested', 'remote pressure diagnostics record full-keyframe recovery');

  requireContains(frameDecode, "peer.mediaConnectionState = 'live';", 'fresh decoded frames clear recovery status');
  requireContains(frameDecode, 'bumpMediaRenderVersion();', 'status changes trigger Vue media rerender');
  requireContains(remoteCanvas, 'export function resizeCanvasPreservingFrame', 'remote decoder size switches preserve the visible frame');
  requireContains(remoteCanvas, 'snapshotCtx.drawImage(canvas, 0, 0);', 'remote canvas resize snapshots the visible frame before dimensions change');
  requireContains(remoteCanvas, 'ctx.drawImage(snapshot, 0, 0, previousWidth, previousHeight, 0, 0, nextWidth, nextHeight);', 'remote canvas resize restores the previous visible frame at the new size');
  requireContains(frameDecode, 'resizeCanvasPreservingFrame(peer.decodedCanvas, nextWidth, nextHeight);', 'decoder reconfigure does not clear the remote canvas before the next keyframe');
  requireContains(remotePeers, "mediaConnectionState: 'connecting'", 'new SFU peers start in connecting media state');
  requireContains(remotePeers, 'function findSfuRemotePeerEntryByPeer', 'remote peer owner lookup for publisher rollover');
  requireContains(remotePeers, 'publisherId: normalizedPublisherId', 'publisher alias lookup adopts the current frame publisher id');
  requireContains(remotePeers, 'function resetSfuRemotePeerMediaContinuity', 'remote peer continuity reset helper');
  requireContains(remotePeers, "'publisher_id_rollover'", 'publisher id rollover resets remote continuity');
  requireContains(remotePeers, "'track_set_rollover'", 'track rollover resets remote continuity');
  requireContains(remotePeers, 'lastSfuFrameSequenceByTrack: {}', 'rollover clears stale SFU frame sequence continuity');
  requireContains(remotePeers, 'acceptedSfuCacheEpochByTrack: {}', 'rollover clears stale tile cache epoch continuity');
  requireContains(remotePeers, 'setSfuRemotePeer(normalizedPublisherId, updatedPeer, resolvedPreviousPublisherId)', 'frame alias adoption moves peer to new publisher id');
  requireContains(mediaStack, 'bumpMediaRenderVersion,', 'runtime health and frame decode receive media render invalidation');
  requireContains(sfuClient, 'private markPublisherFrameReceived(msg: any', 'SFU client tracks publisher frame freshness');
  requireContains(sfuClient, "if (stringField(msg?.type) !== 'sfu/frame') return", 'publisher frame tracker keys off normalized SFU frame messages');
  requireContains(sfuClient, 'decodeSfuBinaryFrameEnvelope(ev.data)', 'binary frame envelopes flow through the same SFU message handler');
  requireContains(sfuClient, 'this.markPublisherFrameReceived(msg)', 'publisher frame tracker runs for binary-decoded and JSON SFU frames');
  requireContains(sfuClient, 'eventType: \'sfu_publisher_frame_stall\'', 'transport-local publisher stall diagnostic');
  requireContains(sfuClient, "reason: 'publisher_frame_stall_recovery'", 'transport-local publisher stall recovery resubscribes before UI restart');

  requireContains(participantUi, 'function participantMediaStatus(userId)', 'participant media status helper');
  requireContains(participantUi, 'remotePeerForParticipant', 'status helper reads SFU remote peers');
  requireContains(template, 'showParticipantMediaOverlay(participant.userId)', 'grid and mini tiles show media loading/recovery overlays');
  requireContains(template, 'showParticipantMediaOverlay(primaryVideoUserId)', 'main remote video shows media loading/recovery overlay');
  requireContains(template, 'participantMediaStatusLabel(participant.userId)', 'tile overlay renders dynamic media status label');
  requireContains(stageCss, '.workspace-video-status-spinner', 'media status overlay has a loading spinner');

  process.stdout.write('[sfu-video-recovery-timing-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
