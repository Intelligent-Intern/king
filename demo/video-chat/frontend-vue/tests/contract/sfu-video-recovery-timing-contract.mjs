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
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const remotePeers = read('src/domain/realtime/sfu/remotePeers.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.js');
  const template = read('src/domain/realtime/CallWorkspaceView.template.html');
  const stageCss = read('src/domain/realtime/CallWorkspaceStage.css');

  requireContains(runtimeConfig, 'export const REMOTE_VIDEO_FREEZE_THRESHOLD_MS = 2000;', 'two second rendered-video freeze threshold');
  requireContains(runtimeConfig, 'export const REMOTE_VIDEO_STALL_THRESHOLD_MS = 3000;', 'short initial no-frame stall threshold');
  requireContains(runtimeConfig, 'export const REMOTE_VIDEO_STALL_CHECK_INTERVAL_MS = 1000;', 'one second remote video health cadence');
  requireContains(runtimeConfig, 'export const SFU_VIDEO_RECOVERY_RECONNECT_COOLDOWN_MS = 5000;', 'bounded SFU reconnect cooldown');

  requireContains(runtimeHealth, "setRemoteVideoStatus(peer, 'recovering', 'Reconnecting video', nowMs);", 'remote recovery status update');
  requireContains(runtimeHealth, "retrySfuSubscription(publisherId, peer, 'remote_video_frozen', nowMs);", 'frozen video resubscribe retry');
  requireContains(runtimeHealth, "retrySfuSubscription(publisherId, peer, 'remote_video_decoder_waiting_keyframe', nowMs);", 'fresh receive/keyframe-wait resubscribe retry');
  requireContains(runtimeHealth, "eventType: 'sfu_remote_video_decoder_waiting_keyframe'", 'fresh receive/keyframe-wait diagnostic');
  requireContains(runtimeHealth, "restartSfuAfterVideoStall('remote_video_frozen'", 'frozen video reconnect trigger');
  assert.equal(
    runtimeHealth.includes('freezeRecoveryCount || 0) >= 2'),
    false,
    'frozen video reconnect must not wait for a second health cycle'
  );
  requireContains(runtimeHealth, 'stalledAgeMs >= remoteVideoStallThresholdMs * 2', 'never-started video reconnect timing');

  requireContains(frameDecode, "peer.mediaConnectionState = 'live';", 'fresh decoded frames clear recovery status');
  requireContains(frameDecode, 'bumpMediaRenderVersion();', 'status changes trigger Vue media rerender');
  requireContains(remotePeers, "mediaConnectionState: 'connecting'", 'new SFU peers start in connecting media state');
  requireContains(mediaStack, 'bumpMediaRenderVersion,', 'runtime health and frame decode receive media render invalidation');

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
