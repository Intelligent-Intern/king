import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { transformWithOxc } from 'vite'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const rolloutGateSource = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/rolloutGate.ts'), 'utf8')
const workspaceGossip = fs.readFileSync(
  path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'),
  'utf8',
)
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8')
const transformedRolloutGate = await transformWithOxc(rolloutGateSource, 'rolloutGate.ts')
const { deriveGossipRolloutGateState } = await import(
  `data:text/javascript;base64,${Buffer.from(transformedRolloutGate.code).toString('base64')}`
)

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-primary-health-gate-contract] ${message}`)
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
      keyframe_requests: 0,
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

const sfuUnhealthy = readyAggregate({
  sfu_baseline_health: {
    ...readyAggregate().sfu_baseline_health,
    keyframe_requests: 80,
    stale_target_prunes: 12,
    encoder_lifecycle_closes: 12,
    send_backpressure_aborts: 12,
  },
})

const gossipPrimaryDecision = deriveGossipRolloutGateState(sfuUnhealthy, {
  mode: 'active',
  mediaCarrierMode: 'gossip_primary',
})
assert(gossipPrimaryDecision.active_allowed === true, 'gossip_primary must allow media when Gossip topology is healthy even if SFU fallback is unhealthy')
assert(gossipPrimaryDecision.decision === 'gossip_primary_active_allowed', 'gossip_primary must expose an explicit Gossip-primary allow decision')
assert(gossipPrimaryDecision.gossip_topology_healthy === true, 'gossip_primary gate must report healthy Gossip topology')
assert(gossipPrimaryDecision.sfu_baseline_required_for_active === false, 'gossip_primary must not require SFU baseline for active media')
assert(gossipPrimaryDecision.sfu_fallback_healthy === false, 'SFU fallback health must remain visible separately')
assert(gossipPrimaryDecision.sfu_fallback_buckets.includes('keyframe_storm'), 'unhealthy SFU fallback buckets must still be reported')
assert(!gossipPrimaryDecision.blocking_buckets.includes('keyframe_storm'), 'SFU fallback buckets must not block gossip_primary media')
assert(gossipPrimaryDecision.sfu_first === false, 'gossip_primary must not downgrade the decision to SFU-first when only SFU fallback is unhealthy')

const sfuFirstDecision = deriveGossipRolloutGateState(sfuUnhealthy, {
  mode: 'active',
  mediaCarrierMode: 'sfu_first',
})
assert(sfuFirstDecision.active_allowed === false, 'sfu_first must keep requiring SFU baseline health')
assert(sfuFirstDecision.blocking_buckets.includes('keyframe_storm'), 'sfu_first must still block on SFU baseline buckets')
assert(sfuFirstDecision.sfu_baseline_required_for_active === true, 'sfu_first must mark SFU baseline as required')

const noisyGossip = deriveGossipRolloutGateState(readyAggregate({
  totals: {
    ...readyAggregate().totals,
    duplicates: 20,
    topology_repairs_requested: 4,
  },
}), {
  mode: 'active',
  mediaCarrierMode: 'gossip_primary',
})
assert(noisyGossip.active_allowed === false, 'gossip_primary must still block noisy Gossip topology/telemetry')
assert(noisyGossip.decision === 'gossip_topology_blocked', 'gossip_primary must name Gossip topology blocking instead of SFU-first')
assert(noisyGossip.blocking_buckets.includes('gossip_telemetry_noisy'), 'Gossip telemetry noise must remain a blocking bucket')

assert(
  workspaceGossip.includes('mediaCarrierMode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode')
    && workspaceGossip.includes('VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary')
    && workspaceGossip.includes('lastGossipRolloutGateState?.gossip_topology_healthy')
    && workspaceGossip.includes('lastGossipRolloutGateState?.sfu_baseline_required_for_active'),
  'workspace data lane must evaluate active gating with explicit media-carrier semantics',
)
assert(
  rolloutGateSource.includes('sfuBaselineRequiredForActive = !gossipPrimary')
    && rolloutGateSource.includes('gossip_topology_healthy')
    && rolloutGateSource.includes('sfu_fallback_buckets'),
  'rollout gate helper must separate Gossip topology health from SFU fallback health',
)
assert(packageJson.includes('gossip-primary-health-gate-contract.mjs'), 'gossip suite must include the gossip-primary health gate contract')

console.log('[gossip-primary-health-gate-contract] PASS')
