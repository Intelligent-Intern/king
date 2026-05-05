import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { deriveGossipRolloutGateState } from '../../src/lib/gossipmesh/rolloutGate.ts'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const workspaceGossip = fs.readFileSync(
  path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'),
  'utf8',
)
const rolloutGateSource = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/rolloutGate.ts'), 'utf8')
const gossipController = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts'), 'utf8')
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-sfu-baseline-rollout-gate-contract] ${message}`)
  }
}

function readyAggregate(overrides = {}) {
  return {
    type: 'gossip/telemetry/ack',
    peer_count: 4,
    transports: { rtc_datachannel: 4 },
    rollout_gate: {
      min_neighbor_count: 4,
      max_topology_epoch: 9,
    },
    sfu_baseline_health: {
      baseline_sample_count: 200,
      participant_set_recoveries: 0,
      participant_set_recovery_in_flight: 0,
      protected_decrypt_failures: 0,
      keyframe_requests: 1,
      stale_target_prunes: 0,
      encoder_lifecycle_closes: 0,
      send_backpressure_aborts: 0,
    },
    totals: {
      sent: 100,
      received: 100,
      forwarded: 60,
      dropped: 0,
      duplicates: 1,
      ttl_exhausted: 0,
      late_drops: 0,
      stale_generation_drops: 0,
      server_fanout_avoided: 300,
      peer_outbound_fanout: 160,
      rtc_datachannel_sends: 160,
      topology_repairs_requested: 0,
      would_publish_frames: 0,
    },
    ...overrides,
  }
}

const ready = deriveGossipRolloutGateState(readyAggregate(), { mode: 'active' })
assert(ready.active_allowed === true, 'clean SFU baseline and media-security readiness must allow active mode')
assert(ready.sfu_baseline_healthy === true, 'ready gate must expose SFU baseline health')
assert(ready.media_security_recovery_ready === true, 'ready gate must expose media-security recovery readiness')
assert(Array.isArray(ready.blocking_buckets) && ready.blocking_buckets.length === 0, 'ready gate must not report blocking buckets')

const blockedCases = [
  ['participant_set_recoveries', 12, 'participant_set_recovery_storm'],
  ['participant_set_recovery_in_flight', 1, 'participant_set_recovery_in_flight'],
  ['protected_decrypt_failures', 8, 'protected_decrypt_burst'],
  ['keyframe_requests', 40, 'keyframe_storm'],
  ['stale_target_prunes', 8, 'stale_target_prune_storm'],
  ['encoder_lifecycle_closes', 8, 'encoder_lifecycle_close_storm'],
  ['send_backpressure_aborts', 8, 'send_backpressure_abort_storm'],
]

for (const [field, value, bucket] of blockedCases) {
  const decision = deriveGossipRolloutGateState(readyAggregate({
    sfu_baseline_health: {
      ...readyAggregate().sfu_baseline_health,
      [field]: value,
    },
  }), { mode: 'active' })
  assert(decision.active_allowed === false, `${field} must block active gossip media`)
  assert(decision.blocking_buckets.includes(bucket), `${field} must report ${bucket}`)
}

const aliasDecision = deriveGossipRolloutGateState(readyAggregate({
  media_security_readiness: {
    media_security_recovery_in_flight: 1,
    sfu_protected_frame_decrypt_failed: 5,
  },
}), { mode: 'active' })
assert(aliasDecision.media_security_recovery_ready === false, 'media-security alias counters must feed the recovery readiness gate')
assert(aliasDecision.blocking_buckets.includes('participant_set_recovery_in_flight'), 'media-security in-flight alias must block active mode')
assert(aliasDecision.blocking_buckets.includes('protected_decrypt_burst'), 'decrypt-failure alias must block active mode')

for (const forbiddenToken of ['data_base64', 'protected_frame', 'sdp', 'ice_candidate', 'raw_media_key']) {
  assert(!JSON.stringify(ready).includes(forbiddenToken), `baseline gate state must not expose ${forbiddenToken}`)
}

assert(rolloutGateSource.includes('sfu_baseline_healthy'), 'rollout gate helper must expose SFU baseline health')
assert(rolloutGateSource.includes('media_security_recovery_ready'), 'rollout gate helper must expose media-security readiness')
assert(rolloutGateSource.includes('participant_set_recovery_storm'), 'rollout gate helper must name participant-set storm bucket')
assert(rolloutGateSource.includes('protected_decrypt_burst'), 'rollout gate helper must name protected decrypt burst bucket')
assert(rolloutGateSource.includes('keyframe_storm'), 'rollout gate helper must name keyframe storm bucket')
assert(rolloutGateSource.includes('stale_target_prune_storm'), 'rollout gate helper must name stale-target prune bucket')
assert(rolloutGateSource.includes('encoder_lifecycle_close_storm'), 'rollout gate helper must name encoder lifecycle close bucket')
assert(rolloutGateSource.includes('send_backpressure_abort_storm'), 'rollout gate helper must name send-backpressure abort bucket')

assert(workspaceGossip.includes('gossipActiveDataLaneAllowed'), 'workspace gossip data lane must require active rollout gates before media')
assert(workspaceGossip.includes("eventType: 'gossip_data_lane_shadow_would_publish'"), 'shadow mode must record would-publish diagnostics')
assert(workspaceGossip.includes("controller?.recordTransportTelemetry?.(peerId, 'would_publish_frames', 1)"), 'shadow mode must increment would-publish telemetry')
assert(!/gossip_data_lane_shadow_would_publish[\s\S]{0,1600}(data_base64|protected_frame|sdp|ice_candidate|raw_media_key)/.test(workspaceGossip), 'would-publish diagnostics must stay sanitized')
assert(gossipController.includes('would_publish_frames'), 'gossip telemetry counters must include shadow would-publish records')
assert(packageJson.includes('gossip-sfu-baseline-rollout-gate-contract.mjs'), 'gossip contract suite must include SFU baseline rollout gate contract')

console.log('[gossip-sfu-baseline-rollout-gate-contract] PASS')
