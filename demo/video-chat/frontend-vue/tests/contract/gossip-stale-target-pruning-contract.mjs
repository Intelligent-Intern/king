import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-stale-target-pruning-contract] ${message}`);
  }
}

const callWorkspace = read('src/domain/realtime/CallWorkspaceView.vue');
const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts');
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const packageJson = read('package.json');

assert(
  /function pruneGossipNeighborForUserId\(userId,\s*reason = 'target_not_in_room'\)[\s\S]*assignedGossipNativeNeighborIds\.delete\(peerId\);[\s\S]*gossipTopologyRepairRequestedAtByPeerId\.delete\(peerId\);[\s\S]*closeGossipDataChannelForNativePeer\(peerId\);/m.test(gossipDataLane),
  'gossip data lane must expose stale-neighbor pruning that removes assignment state and closes the native data channel',
);
assert(
  /eventType: 'gossip_assigned_neighbor_pruned'[\s\S]*code: 'gossip_assigned_neighbor_pruned'[\s\S]*reason: String\(reason \|\| 'target_not_in_room'\)/m.test(gossipDataLane),
  'stale gossip pruning must leave a durable deploy-log diagnostic with the pruning reason',
);
assert(
  /pruneGossipNeighborForUserId,/.test(gossipDataLane)
    && /pruneGossipNeighborForUserId,/.test(callWorkspace)
    && /pruneGossipNeighborForUserId = \(\) => false/.test(socketLifecycle),
  'call workspace must pass stale gossip pruning into socket lifecycle with a safe default',
);
assert(
  /removeParticipantLocallyAfterHangup\(userId\)[\s\S]*removeSfuRemotePeersForUserId\(normalizedUserId\);[\s\S]*pruneGossipNeighborForUserId\(normalizedUserId,\s*'target_not_in_room'\);[\s\S]*return participantsChanged \|\| gossipNeighborPruned;/m.test(socketLifecycle),
  'target-not-in-room recovery must prune SFU remotes and assigned gossip neighbors in the same local removal path',
);
assert(
  /requestWlvcFullFrameKeyframe\('media_security_target_not_in_room_pruned'/.test(socketLifecycle),
  'media-security stale-target pruning must force a fresh full-frame keyframe for remaining receivers',
);
assert(
  /const STALE_TARGET_PRUNING_SIGNAL_TYPES = Object\.freeze\(\[[\s\S]*'call\/ice'[\s\S]*'call\/media-quality-pressure'[\s\S]*'call\/offer'[\s\S]*\]\);/m.test(socketLifecycle),
  'socket lifecycle must explicitly cover native ICE, media-quality pressure, and native offer stale-target pruning',
);
assert(
  /const failedStaleTargetPruningSignal = failedMediaSecuritySignal[\s\S]*\|\| STALE_TARGET_PRUNING_SIGNAL_TYPES\.includes\(failedCommandType\);[\s\S]*const shouldPruneTargetNotInRoom = targetIsKnown[\s\S]*&& normalizedError === 'target_not_in_room'[\s\S]*&& failedStaleTargetPruningSignal;[\s\S]*\? removeParticipantLocallyAfterHangup\(normalizedTargetUserId\)/m.test(socketLifecycle),
  'target_not_in_room recovery must prune the same local participant/native/SFU/gossip state for media-security, call/media-quality-pressure, call/ice, and call/offer',
);
assert(
  /function isExpectedStaleTargetPublishFailure\(code, failedCommandType, signalingError, failedTargetUserId\)[\s\S]*signalingError[\s\S]*!== 'target_not_in_room'[\s\S]*return mediaSecuritySignalTypes\.includes\(failedCommandType\)[\s\S]*\|\| STALE_TARGET_PRUNING_SIGNAL_TYPES\.includes\(failedCommandType\);/m.test(socketLifecycle),
  'stale-target publish failures must have a dedicated classifier instead of sharing broker failure diagnostics',
);
assert(
  /if \(code === 'signaling_publish_failed' && !expectedStaleTargetPublishFailure\)[\s\S]*eventType: 'realtime_signaling_publish_failed'[\s\S]*level: 'error'/m.test(socketLifecycle),
  'real signaling broker failures must remain error diagnostics',
);
assert(
  /else if \(expectedStaleTargetPublishFailure\)[\s\S]*level: 'warning'[\s\S]*eventType: 'realtime_signaling_stale_target_pruned'[\s\S]*code: 'target_not_in_room'[\s\S]*expected_stale_target_prune: true/m.test(socketLifecycle),
  'expected stale-target pruning must emit a warning diagnostic that is distinct from broker failure',
);
assert(
  packageJson.includes('gossip-stale-target-pruning-contract.mjs'),
  'gossip contract suite must include stale target pruning contract',
);

console.log('[gossip-stale-target-pruning-contract] PASS');
