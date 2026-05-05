import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { deriveGossipRolloutGateState } from '../../src/lib/gossipmesh/rolloutGate.ts'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

const rolloutGateSource = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/rolloutGate.ts'), 'utf8')
const workspaceGossip = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'), 'utf8')
const socketLifecycle = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts'), 'utf8')
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-rollout-gate-contract] ${message}`)
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
    },
    ...overrides,
  }
}

const offDecision = deriveGossipRolloutGateState(readyAggregate(), { mode: 'off' })
assert(offDecision.active_allowed === false, 'off mode must be inert')
assert(offDecision.decision === 'sfu_first_explicit', 'off mode must keep SFU-first decision')
assert(offDecision.observational_only === true, 'off mode must not become an active gate')

const shadowDecision = deriveGossipRolloutGateState(readyAggregate(), { mode: 'shadow' })
assert(shadowDecision.active_allowed === false, 'shadow mode must remain observational even when aggregates are ready')
assert(shadowDecision.decision === 'shadow_observe', 'shadow mode must surface observe-only rollout state')
assert(shadowDecision.sfu_first === true, 'shadow mode must keep SFU first')

const unreadyActiveDecision = deriveGossipRolloutGateState(readyAggregate({
  peer_count: 4,
  transports: { rtc_datachannel: 2 },
}), { mode: 'active' })
assert(unreadyActiveDecision.active_allowed === false, 'active mode must still require RTC/topology readiness')
assert(unreadyActiveDecision.decision === 'sfu_first_explicit', 'unready active mode must remain SFU-first')

const noisyActiveDecision = deriveGossipRolloutGateState(readyAggregate({
  totals: {
    ...readyAggregate().totals,
    duplicates: 12,
    late_drops: 4,
    topology_repairs_requested: 2,
  },
}), { mode: 'active' })
assert(noisyActiveDecision.active_allowed === false, 'active mode must require clean telemetry rates')
assert(noisyActiveDecision.duplicate_rate > 0.02, 'duplicate rate must be exposed for dashboard gating')
assert(noisyActiveDecision.late_drop_rate > 0.01, 'late drop rate must be exposed for dashboard gating')
assert(noisyActiveDecision.repair_rate > 0.05, 'repair rate must be exposed for dashboard gating')

const readyActiveDecision = deriveGossipRolloutGateState(readyAggregate(), { mode: 'active' })
assert(readyActiveDecision.active_allowed === true, 'active mode may be allowed only after topology, channel, and telemetry readiness')
assert(readyActiveDecision.decision === 'active_allowed_diagnostic', 'ready active mode must remain a diagnostic gate decision')
assert(readyActiveDecision.rtc_ready === true, 'ready active mode must report RTC readiness')
assert(readyActiveDecision.telemetry_ready === true, 'ready active mode must report telemetry readiness')

for (const forbiddenField of ['data_base64', 'protected_frame', 'sdp', 'ice_candidate', 'token', 'raw_media_key']) {
  const decision = deriveGossipRolloutGateState(readyAggregate({ [forbiddenField]: 'unsafe' }), { mode: 'active' })
  assert(decision.active_allowed === false, `gate must fail closed when ${forbiddenField} enters telemetry gate input`)
  assert(decision.reason === 'forbidden_media_or_signaling_field', `gate must report forbidden field for ${forbiddenField}`)
}

for (const forbiddenToken of ['data_base64', 'protected_frame', 'sdp', 'ice_candidate', 'raw_media_key']) {
  assert(!JSON.stringify(readyActiveDecision).includes(forbiddenToken), `sanitized rollout gate state must not include ${forbiddenToken}`)
}

assert(workspaceGossip.includes("type !== 'gossip/telemetry/ack'"), 'workspace gossip lane must only consume telemetry ack payloads for rollout gates')
assert(workspaceGossip.includes('deriveGossipRolloutGateState(payload'), 'workspace gossip lane must derive gate state from sanitized backend ack payloads')
assert(workspaceGossip.includes("eventType: 'gossip_rollout_gate_state'"), 'workspace gossip lane must surface rollout gate diagnostics')
assert(socketLifecycle.includes("type === 'gossip/telemetry/ack'"), 'socket lifecycle must route telemetry acks without bloating CallWorkspaceView')
assert(packageJson.includes('gossip-rollout-gate-contract.mjs'), 'gossip contract script must include rollout gate contract')
assert(!/gossip_rollout_gate_state[\s\S]{0,900}(data_base64|protected_frame|sdp|ice_candidate|raw_media_key)/.test(workspaceGossip), 'rollout diagnostic surface must not attach media/signaling/secret fields')
assert(rolloutGateSource.includes('FORBIDDEN_GATE_FIELDS'), 'rollout gate helper must pin forbidden telemetry fields')
assert(rolloutGateSource.includes("activeAllowed = requestedMode === 'active'"), 'active allowed must be explicitly mode-gated')

console.log('[gossip-rollout-gate-contract] PASS')
