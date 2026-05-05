import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

function read(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8')
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-neighbor-health-repair-contract] ${message}`)
  }
}

const callWorkspace = read('src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
const controller = read('src/lib/gossipmesh/gossipController.ts')
const packageJson = read('package.json')

assert(
  /setCarrierState\(peerId:\s*string,\s*carrierState:\s*GossipPeer\['carrier_state'\]/.test(controller)
    && /updateCarrierStateFromDataChannel\(peerId:\s*string,\s*state:\s*RTCDataChannelState,\s*eventType:\s*'open' \| 'close' \| 'error'\)/.test(controller)
    && /this\.logEvent\(peerId,\s*'carrier_state_change',\s*'ops'/.test(controller)
    && /this\.logEvent\(peerId,\s*'reconnect_requested',\s*'ops'/.test(controller),
  'GossipController must expose an explicit carrier-state API that logs reconnect intent on lost carriers',
)
assert(
  /const gossipTopologyRepairRequestedAtByPeerId = new Map\(\);/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must track topology repair cooldown state by assigned neighbor',
)
assert(
  /onStateChange:\s*\(peerId,\s*state,\s*eventType\) => \{[\s\S]*assignedGossipNativeNeighborIds\.has\((String\(peerId \|\| ''\)|normalizedPeerId)\)[\s\S]*controller\.updateCarrierStateFromDataChannel\((String\(peerId \|\| ''\)|normalizedPeerId),\s*state,\s*eventType\)/.test(workspaceGossipSurface),
  'RTCDataChannel state changes must update carrier state only for assigned gossip neighbors while the lane is enabled',
)
assert(
  /function gossipCarrierStateFromDataChannelState\(state\)[\s\S]*normalizedState === 'open'[\s\S]*'connected'[\s\S]*normalizedState === 'closed'[\s\S]*'lost'[\s\S]*'degraded'/.test(workspaceGossipSurface)
    || /updateCarrierStateFromDataChannel[\s\S]*eventType === 'open' && state === 'open'[\s\S]*carrier_state = 'connected'[\s\S]*carrier_state = 'lost'/.test(controller),
  'workspace/controller must map RTCDataChannel states into connected/lost carrier states',
)
assert(
  /onStateChange:\s*\(peerId,\s*state,\s*eventType\) => \{[\s\S]*controller\.updateCarrierStateFromDataChannel\((String\(peerId \|\| ''\)|normalizedPeerId),\s*state,\s*eventType\);[\s\S]*gossip_data_channel_state/.test(workspaceGossipSurface),
  'RTCDataChannel state callback must feed carrier health before emitting diagnostics',
)
assert(
  /function requestGossipTopologyRepair\(peerId,\s*reason\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.enabled \|\| !GOSSIP_DATA_LANE_CONFIG\.publish \|\| !GOSSIP_DATA_LANE_CONFIG\.receive\) return false;[\s\S]*!assignedGossipNativeNeighborIds\.has\(String\(peerId \|\| ''\)\)[\s\S]*\(nowMs - lastRequestedAtMs\) < 3000[\s\S]*type:\s*'gossip\/topology-repair\/request'/.test(workspaceGossipSurface),
  'topology repair requests must be enabled-gated, assigned-neighbor-gated, cooldown-bound, and sent on the ops lane',
)
assert(
  /kind:\s*'gossip_topology_repair_request'/.test(workspaceGossipSurface)
    && /lost_peer_id:\s*String\(peerId \|\| ''\)/.test(workspaceGossipSurface)
    && /data_lane_mode:\s*GOSSIP_DATA_LANE_CONFIG\.mode/.test(workspaceGossipSurface)
    && /diagnostics_label:\s*GOSSIP_DATA_LANE_CONFIG\.diagnosticsLabel/.test(workspaceGossipSurface),
  'topology repair payload must identify the lost neighbor and gossip data-lane mode',
)
assert(
  /gossip_topology_repair_requested/.test(workspaceGossipSurface),
  'topology repair attempts must emit diagnostics',
)
assert(
  /\(state === 'closed' \|\| eventType === 'error'\)[\s\S]*requestGossipTopologyRepair\((peerId|normalizedPeerId),\s*eventType\)/.test(workspaceGossipSurface),
  'lost assigned RTCDataChannel carriers must request topology repair',
)
assert(
  /gossipTopologyRepairRequestedAtByPeerId\.clear\(\);[\s\S]*gossipDataChannelTransport\?\.close\(\)/.test(workspaceGossipSurface),
  'gossip teardown must clear repair cooldown state before closing transport state',
)
assert(
  packageJson.includes('gossip-neighbor-health-repair-contract.mjs'),
  'gossip contract suite must include the neighbor health and repair contract',
)

console.log('[gossip-neighbor-health-repair-contract] PASS')
