import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { transformWithOxc } from 'vite'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-media-carrier-integration-smoke-contract] ${message}`)
  }
}

async function importSource(source, filename) {
  const transformed = await transformWithOxc(source, filename)
  return import(`data:text/javascript;base64,${Buffer.from(transformed.code).toString('base64')}`)
}

const mediaCarrierSource = read('demo/video-chat/frontend-vue/src/lib/gossipmesh/mediaCarrierMode.ts')
const mediaCarrier = await importSource(
  mediaCarrierSource.replaceAll('= import.meta.env', '= {}'),
  'mediaCarrierMode.ts',
)

const rolloutGateSource = read('demo/video-chat/frontend-vue/src/lib/gossipmesh/rolloutGate.ts')
const rolloutGate = await importSource(rolloutGateSource, 'rolloutGate.ts')

const roomStateTopologySource = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomStateTopology.ts')
const roomStateTopology = await importSource(roomStateTopologySource, 'roomStateTopology.ts')

async function loadPublisherDispatch(mode) {
  const config = mediaCarrier.resolveVideochatMediaCarrierConfig({
    VITE_VIDEOCHAT_MEDIA_CARRIER: mode,
  })
  const source = read('demo/video-chat/frontend-vue/src/domain/realtime/local/publisherFrameDispatch.ts')
    .replace(
      "import { VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../lib/gossipmesh/featureFlags';\n",
      `const VIDEOCHAT_MEDIA_CARRIER_CONFIG = ${JSON.stringify(config)};\n`,
    )
    .replace(
      "import { reportSfuClientUnavailableAfterEncode } from './publisherPipelineSendFailures';\n",
      "function reportSfuClientUnavailableAfterEncode(payload) { globalThis.__gossipMediaCarrierSmokeReports?.push(payload); }\n",
    )
  return importSource(source, `publisherFrameDispatch.${mode}.ts`)
}

function readyAggregate(overrides = {}) {
  const base = {
    type: 'gossip/telemetry/ack',
    peer_count: 4,
    transports: { rtc_datachannel: 4 },
    rollout_gate: {
      min_neighbor_count: 4,
      max_topology_epoch: 7,
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
      sent: 120,
      received: 120,
      forwarded: 60,
      dropped: 0,
      duplicates: 1,
      ttl_exhausted: 0,
      late_drops: 0,
      stale_generation_drops: 0,
      server_fanout_avoided: 300,
      peer_outbound_fanout: 180,
      rtc_datachannel_sends: 180,
      topology_repairs_requested: 0,
      would_publish_frames: 0,
    },
  }
  return {
    ...base,
    ...overrides,
    sfu_baseline_health: {
      ...base.sfu_baseline_health,
      ...(overrides.sfu_baseline_health || {}),
    },
    totals: {
      ...base.totals,
      ...(overrides.totals || {}),
    },
  }
}

function publisherHarness(overrides = {}) {
  const order = []
  const diagnostics = []
  const failures = []
  const frame = {
    trackId: 'camera-main',
    publisherId: '10',
    publisherUserId: '10',
    type: 'delta',
    data: new Uint8Array([1, 2, 3]).buffer,
  }
  return {
    order,
    diagnostics,
    failures,
    args: {
      frame,
      trackId: 'camera-main',
      mediaRuntimePath: 'wlvc_publisher',
      currentOpenSfuClient: () => null,
      getSfuClientBufferedAmount: () => 0,
      publishLocalEncodedFrameToGossip: () => {
        order.push('gossip')
        return true
      },
      captureClientDiagnostic: (event) => diagnostics.push(event),
      captureClientDiagnosticError: (code, error) => failures.push({ code, error }),
      onRequiredSfuUnavailable: () => {
        order.push('required_sfu_unavailable')
        return false
      },
      onRequiredSfuFailure: () => {
        order.push('required_sfu_failure')
        return false
      },
      ...overrides,
    },
  }
}

for (const mode of ['gossip_primary', 'sfu_first', 'sfu_mirror']) {
  assert(
    mediaCarrier.normalizeVideochatMediaCarrierMode(mode) === mode,
    `${mode} must be an explicitly accepted media carrier mode`,
  )
}

const gossipPrimaryConfig = mediaCarrier.resolveVideochatMediaCarrierConfig({ VITE_VIDEOCHAT_MEDIA_CARRIER: 'gossip_primary' })
const sfuFirstConfig = mediaCarrier.resolveVideochatMediaCarrierConfig({ VITE_VIDEOCHAT_MEDIA_CARRIER: 'sfu_first' })
const sfuMirrorConfig = mediaCarrier.resolveVideochatMediaCarrierConfig({ VITE_VIDEOCHAT_MEDIA_CARRIER: 'sfu_mirror' })
assert(gossipPrimaryConfig.gossipMayPublishWithoutSfu === true && gossipPrimaryConfig.sfuSendIsOptional === true, 'gossip_primary must make Gossip independent from SFU send readiness')
assert(sfuFirstConfig.sfuRequiredBeforeGossip === true && sfuFirstConfig.sfuSendIsOptional === false, 'sfu_first must remain conservative and SFU-required')
assert(sfuMirrorConfig.sfuRequiredBeforeGossip === true && sfuMirrorConfig.sfuSendIsOptional === true, 'sfu_mirror must require SFU before encode while treating the mirror send as optional')

const gossipPrimaryDispatch = await loadPublisherDispatch('gossip_primary')
let harness = publisherHarness()
let result = await gossipPrimaryDispatch.dispatchPublisherFrame(harness.args)
assert(result.ok === true && result.gossipPublished === true && result.sfuSent === false, 'gossip_primary must publish over Gossip when no SFU send client exists')
assert(harness.order.join(',') === 'gossip', 'gossip_primary must publish Gossip before optional SFU handling')
assert(harness.diagnostics.some((event) => event?.eventType === 'sfu_optional_send_unavailable_after_gossip_publish'), 'gossip_primary SFU-unavailable path must be diagnostic, not blocking')
assert(gossipPrimaryDispatch.publisherRequiresSfuBeforeEncode() === false, 'gossip_primary must not require SFU before encode')

const sfuFirstDispatch = await loadPublisherDispatch('sfu_first')
harness = publisherHarness()
result = await sfuFirstDispatch.dispatchPublisherFrame(harness.args)
assert(result.ok === false && result.gossipPublished === false && result.sfuSendOptional === false, 'sfu_first must not publish Gossip when the required SFU send path is unavailable')
assert(harness.order.join(',') === 'required_sfu_unavailable', 'sfu_first must route unavailable SFU through the required failure handler')
assert(sfuFirstDispatch.publisherRequiresSfuBeforeEncode() === true, 'sfu_first must require SFU before encode')

const sfuMirrorDispatch = await loadPublisherDispatch('sfu_mirror')
harness = publisherHarness({
  currentOpenSfuClient: () => ({
    sendEncodedFrame: async () => {
      harness.order.push('sfu')
      return true
    },
  }),
})
result = await sfuMirrorDispatch.dispatchPublisherFrame(harness.args)
assert(result.ok === true && result.sfuSent === true && result.gossipPublished === true, 'sfu_mirror must publish Gossip after successful SFU send')
assert(harness.order.join(',') === 'sfu,gossip', 'sfu_mirror must keep SFU first and mirror to Gossip after send')
assert(sfuMirrorDispatch.publisherRequiresSfuBeforeEncode() === true, 'sfu_mirror must require SFU before encode')

harness = publisherHarness({
  currentOpenSfuClient: () => ({
    sendEncodedFrame: async () => {
      harness.order.push('sfu')
      return false
    },
    getLastSendFailure: () => ({ reason: 'contract_sfu_failure' }),
  }),
})
result = await sfuMirrorDispatch.dispatchPublisherFrame(harness.args)
assert(result.ok === true && result.sfuSent === false && result.gossipPublished === true, 'sfu_mirror must keep Gossip mirror publication when SFU send fails after encode')
assert(harness.order.join(',') === 'sfu,gossip', 'sfu_mirror SFU failure must still mirror to Gossip after the failed SFU attempt')
assert(harness.diagnostics.some((event) => event?.eventType === 'sfu_optional_send_failed_after_gossip_publish'), 'sfu_mirror SFU failure must be diagnostic after Gossip mirror publication')

const unhealthySfu = readyAggregate({
  sfu_baseline_health: {
    keyframe_requests: 80,
    stale_target_prunes: 20,
    encoder_lifecycle_closes: 20,
    send_backpressure_aborts: 20,
  },
})
const gossipPrimaryGate = rolloutGate.deriveGossipRolloutGateState(unhealthySfu, {
  mode: 'active',
  mediaCarrierMode: 'gossip_primary',
})
assert(gossipPrimaryGate.active_allowed === true, 'gossip_primary rollout must key active media on healthy Gossip topology even when SFU fallback is unhealthy')
assert(gossipPrimaryGate.sfu_baseline_required_for_active === false, 'gossip_primary gate must not require SFU baseline')

const sfuFirstGate = rolloutGate.deriveGossipRolloutGateState(unhealthySfu, {
  mode: 'active',
  mediaCarrierMode: 'sfu_first',
})
assert(sfuFirstGate.active_allowed === false && sfuFirstGate.blocking_buckets.includes('keyframe_storm'), 'sfu_first must still block on unhealthy SFU baseline')

const sfuMirrorGate = rolloutGate.deriveGossipRolloutGateState(unhealthySfu, {
  mode: 'active',
  mediaCarrierMode: 'sfu_mirror',
})
assert(sfuMirrorGate.active_allowed === false && sfuMirrorGate.sfu_baseline_required_for_active === true, 'sfu_mirror must keep SFU-baseline gating for active mode')

const appliedTopology = []
assert(
  roomStateTopology.applyGossipTopologyFromRoomStatePayload(
    { gossip_topology: { type: 'topology_hint', peer_id: '10', topology_epoch: 1 } },
    '10',
    (payload) => {
      if (!payload || typeof payload !== 'object') return false
      appliedTopology.push(payload)
      return true
    },
  ) === true,
  'room/snapshot must apply direct viewer-scoped topology hints',
)
assert(appliedTopology[0]?.peer_id === '10', 'direct room-state topology must target the local peer')

appliedTopology.length = 0
assert(
  roomStateTopology.applyGossipTopologyFromRoomStatePayload(
    {
      gossip_topology_by_peer_id: {
        10: { type: 'topology_hint', peer_id: '10', topology_epoch: 2 },
        20: { type: 'topology_hint', peer_id: '20', topology_epoch: 2 },
      },
    },
    '10',
    (payload) => {
      if (!payload || typeof payload !== 'object') return false
      appliedTopology.push(payload)
      return true
    },
  ) === true,
  'room/joined and room/left churn maps must apply the local peer topology assignment',
)
assert(appliedTopology[0]?.topology_epoch === 2, 'churn topology must carry the reassigned topology epoch')

const dataLane = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const neighborLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipNeighborLifecycle.ts')
const socketLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const backendRoomStateContract = read('demo/video-chat/backend-king-php/tests/realtime-gossipmesh-room-state-topology-contract.php')
const backendRuntimeContract = read('demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php')
const mediaFanoutGuard = read('demo/video-chat/backend-king-php/http/module_realtime_media_fanout_guard.php')
const packageJson = read('demo/video-chat/frontend-vue/package.json')
const sprint = read('SPRINT.md')

assert(
  dataLane.includes("type: 'gossip/topology-repair/request'")
    && dataLane.includes("gossipNeighborLifecycle?.closePeer?.(retiredPeerId, 'repair_retired_edge')")
    && dataLane.includes("gossipNeighborLifecycle?.closePeer?.(previousPeerId, 'retired_by_topology')"),
  'neighbor failure, authoritative repair, and retired-edge cleanup must be wired in the workspace data lane',
)
assert(
  neighborLifecycle.includes('function applyAssignedNeighbors(topologyHint, assignedPeerIds)')
    && neighborLifecycle.includes("ensurePeer(peerId, true, 'server_assigned_neighbor')")
    && neighborLifecycle.includes("closePeer(peerId, 'retired_by_topology')")
    && neighborLifecycle.includes("transport: 'rtc_datachannel'"),
  'dedicated bounded neighbor lifecycle must create and retire RTC data-channel links from server assignments',
)
assert(
  socketLifecycle.includes('applyGossipTopologyFromRoomStatePayload(payload, refs.sessionState?.userId, applyGossipTopologyHint)')
    && socketLifecycle.includes('if (handleGossipNeighborSignal(type, senderUserId, payloadBody || {})) return;'),
  'socket lifecycle must treat topology as room state and route dedicated neighbor SDP before native media signaling',
)
assert(
  backendRoomStateContract.includes('room snapshot must carry a directly usable topology_hint')
    && backendRoomStateContract.includes('room/joined churn event must carry per-peer topology hints')
    && backendRoomStateContract.includes('room/left churn event must carry replacement per-peer topology hints'),
  'backend room-state contract must exercise join, snapshot, and churn topology payloads',
)
assert(
  backendRuntimeContract.includes('websocket topology repair should emit peer-scoped room reassignment frames')
    && backendRuntimeContract.includes('websocket recovery must not distribute unsafe token')
    && backendRuntimeContract.includes('must not distribute media frames'),
  'backend runtime contract must exercise repair, recovery ops, and no media fanout',
)
assert(
  mediaFanoutGuard.includes('normal_media_fanout_forbidden')
    && mediaFanoutGuard.includes('protected_frame')
    && mediaFanoutGuard.includes('data_base64'),
  'backend normal websocket path must reject media-frame fanout commands and media-bearing fields',
)
assert(
  packageJson.includes('gossip-media-carrier-integration-smoke-contract.mjs')
    && packageJson.includes('../backend-king-php/tests/realtime-gossipmesh-room-state-topology-contract.sh')
    && packageJson.includes('../backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh'),
  'gossip contract suite must include integration smoke plus backend topology/runtime contracts',
)
assert(
  /- \[x\] GSP-09 Integration contracts and smoke checks/.test(sprint)
    && /gossip-media-carrier-integration-smoke-contract\.mjs/.test(sprint)
    && /gossip_primary`, `sfu_first`, and `sfu_mirror`/.test(sprint),
  'SPRINT must mark GSP-09 complete and record the three-mode smoke proof',
)

console.log('[gossip-media-carrier-integration-smoke-contract] PASS')
