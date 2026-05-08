import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { transformWithOxc } from 'vite'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

const publisherPipeline = fs.readFileSync(
  path.join(frontendRoot, 'src/domain/realtime/local/publisherPipeline.ts'),
  'utf8',
)
const publisherFrameDispatch = fs.readFileSync(
  path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts'),
  'utf8',
)
const gossipDataLane = fs.readFileSync(
  path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'),
  'utf8',
)
const featureFlags = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/featureFlags.ts'), 'utf8')
const mediaCarrierMode = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/mediaCarrierMode.ts'), 'utf8')
const gossipController = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts'), 'utf8')
const rolloutGateSource = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/rolloutGate.ts'), 'utf8')
const roomSnapshot = fs.readFileSync(
  path.join(repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_room_snapshot.php'),
  'utf8',
)
const backendGossip = fs.readFileSync(
  path.join(repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php'),
  'utf8',
)
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8')
const transformedRolloutGate = await transformWithOxc(rolloutGateSource, 'rolloutGate.ts')
const { deriveGossipRolloutGateState } = await import(
  `data:text/javascript;base64,${Buffer.from(transformedRolloutGate.code).toString('base64')}`
)

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-media-carrier-contract] ${message}`)
  }
}

function aggregate(overrides = {}) {
  return {
    type: 'gossip/telemetry/ack',
    peer_count: 4,
    transports: { rtc_datachannel: 4 },
    rollout_gate: {
      min_neighbor_count: 4,
      max_topology_epoch: 11,
    },
    sfu_baseline_health: {
      baseline_sample_count: 200,
      participant_set_recoveries: 0,
      participant_set_recovery_in_flight: 0,
      protected_decrypt_failures: 0,
      keyframe_requests: 40,
      stale_target_prunes: 8,
      encoder_lifecycle_closes: 8,
      send_backpressure_aborts: 8,
    },
    totals: {
      sent: 100,
      received: 100,
      forwarded: 60,
      duplicates: 1,
      ttl_exhausted: 0,
      late_drops: 0,
      topology_repairs_requested: 0,
    },
    ...overrides,
  }
}

const sfuMirrorDecision = deriveGossipRolloutGateState(aggregate(), {
  mode: 'active',
  mediaCarrierMode: 'sfu_mirror',
})
assert(sfuMirrorDecision.active_allowed === false, 'sfu_mirror must still block active media on SFU fallback pressure')
assert(sfuMirrorDecision.blocking_buckets.includes('keyframe_storm'), 'sfu_mirror must keep SFU keyframe storms as blocking buckets')

const gossipPrimaryDecision = deriveGossipRolloutGateState(aggregate(), {
  mode: 'active',
  mediaCarrierMode: 'gossip_primary',
})
assert(gossipPrimaryDecision.active_allowed === true, 'gossip_primary must allow active media on healthy gossip topology despite SFU fallback pressure')
assert(gossipPrimaryDecision.media_carrier_mode === 'gossip_primary', 'gossip_primary decision must expose the carrier mode')
assert(gossipPrimaryDecision.gossip_topology_healthy === true, 'gossip_primary must gate on gossip topology health')
assert(gossipPrimaryDecision.sfu_fallback_healthy === false, 'gossip_primary must still report unhealthy SFU fallback pressure')
assert(gossipPrimaryDecision.sfu_fallback_buckets.includes('keyframe_storm'), 'gossip_primary must retain SFU fallback pressure diagnostics')
assert(!gossipPrimaryDecision.blocking_buckets.includes('keyframe_storm'), 'gossip_primary must not let SFU keyframe storms block gossip media')

assert(mediaCarrierMode.includes('VITE_VIDEOCHAT_MEDIA_CARRIER'), 'media carrier mode must include explicit media carrier env')
assert(featureFlags.includes('VIDEOCHAT_MEDIA_CARRIER_CONFIG'), 'feature flags must re-export authoritative media carrier config')
assert(gossipDataLane.includes('VIDEOCHAT_MEDIA_CARRIER_CONFIG'), 'gossip data lane must consume the media carrier config')
assert(gossipDataLane.includes('mediaCarrierMode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode'), 'telemetry snapshots must include media carrier mode')
assert(gossipDataLane.includes('rolloutStrategy: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode'), 'telemetry rollout strategy must track media carrier mode')
assert(gossipDataLane.includes('lastGossipRolloutGateState?.gossip_topology_healthy'), 'gossip_primary receive/publish gate must use gossip topology health')

assert(publisherPipeline.includes('VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary'), 'publisher pipeline must branch on gossip_primary')
assert(
  /const gossipFirst = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary[\s\S]*if \(gossipFirst\)[\s\S]*publishGossipFrame/.test(publisherFrameDispatch),
  'gossip_primary must publish before optional SFU send through the dispatch helper',
)
assert(
  /publisherRequiresSfuBeforeEncode\(\) && !currentOpenSfuClient\(\)/.test(publisherPipeline),
  'gossip_primary must not require open SFU transport before encode',
)
assert(
  /const protectedBrowserPublisher = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary[\s\S]*\? null[\s\S]*maybeStartProtectedBrowserVideoEncoderPublisher/.test(publisherPipeline),
  'gossip_primary must stay on the WLVC publisher path until browser encoder gossip publication is wired',
)
assert(
  /sfu_optional_send_unavailable_after_gossip_publish/.test(publisherFrameDispatch)
    && /sfu_optional_send_failed_after_gossip_publish/.test(publisherFrameDispatch),
  'optional SFU paths must keep gossip publication independent when SFU is unavailable or failed',
)

assert(gossipController.includes('media_carrier_mode'), 'gossip telemetry snapshots must serialize media_carrier_mode')
assert(backendGossip.includes("'media_carrier_mode' => $mediaCarrierMode"), 'backend telemetry decoder must accept media_carrier_mode')
assert(backendGossip.includes("'gossip_primary', 'sfu_first', 'sfu_mirror'"), 'backend telemetry decoder must preserve explicit media carrier labels')

assert(roomSnapshot.includes('videochat_realtime_send_gossipmesh_topology_hint'), 'backend room snapshots must emit gossip topology hints')
assert(roomSnapshot.includes('videochat_gossipmesh_plan_topology'), 'backend topology hint emission must use server-authoritative planner')
assert(roomSnapshot.includes('videochat_gossipmesh_call_topology_payload'), 'backend topology hint emission must send call/gossip-topology payloads')
assert(roomSnapshot.includes('videochat_realtime_gossipmesh_room_allows_topology'), 'backend topology hints must avoid lobby/waiting-room fanout')
assert(packageJson.includes('gossip-media-carrier-mode-contract.mjs'), 'gossip contract suite must include media carrier mode contract')
assert(packageJson.includes('gossip-media-carrier-integration-smoke-contract.mjs'), 'gossip contract suite must include media carrier integration smoke contract')

console.log('[gossip-media-carrier-contract] PASS')
