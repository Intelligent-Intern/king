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
    throw new Error(`[gossip-neighbor-health-topology-repair-contract] ${message}`)
  }
}

const callWorkspace = read('src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
const controller = read('src/lib/gossipmesh/gossipController.ts')
const transport = read('src/lib/gossipmesh/rtcDataChannelTransport.ts')

assert(
  /onStateChange\?:\s*\(peerId:\s*string,\s*state:\s*RTCDataChannelState,\s*(eventType|reason):\s*'open' \| 'close' \| 'error'\)\s*=>\s*void/.test(transport),
  'RTCDataChannel transport must tell callers whether open, close, or error caused the state callback',
)
assert(
  /channel\.addEventListener\('open'[\s\S]*this\.onStateChange\?\.\(peerId,\s*channel\.readyState,\s*'open'\)/.test(transport)
    && /channel\.addEventListener\('close'[\s\S]*this\.onStateChange\?\.\(peerId,\s*channel\.readyState,\s*'close'\)/.test(transport)
    && /channel\.addEventListener\('error'[\s\S]*this\.onStateChange\?\.\(peerId,\s*channel\.readyState,\s*'error'\)/.test(transport),
  'RTCDataChannel open, close, and error events must all emit explicit state-change reasons',
)
assert(
  /updateCarrierStateFromDataChannel\(peerId:\s*string,\s*state:\s*RTCDataChannelState,\s*(eventType|reason):\s*'open' \| 'close' \| 'error'\):\s*boolean/.test(controller),
  'GossipController must expose a public RTCDataChannel carrier-health update hook',
)
assert(
  /updateCarrierStateFromDataChannel[\s\S]*carrier_state = 'connected'[\s\S]*reason:\s*'rtc_datachannel_open'/.test(controller),
  'RTCDataChannel open must mark the gossip carrier connected and log an ops-lane carrier_state_change',
)
assert(
  /updateCarrierStateFromDataChannel[\s\S]*carrier_state = 'lost'[\s\S]*reason:\s*'rtc_datachannel_lost'/.test(controller)
    && /updateCarrierStateFromDataChannel[\s\S]*reconnect_requested[\s\S]*reconnect_allowed:\s*true/.test(controller),
  'RTCDataChannel close/error must mark the gossip carrier lost and request reconnect/topology recovery in controller events',
)
assert(
  /onStateChange:\s*\(peerId,\s*state,\s*(eventType|reason)\)\s*=>\s*\{[\s\S]*controller\.updateCarrierStateFromDataChannel\((String\(peerId \|\| ''\)|normalizedPeerId),\s*state,\s*(eventType|reason)\)/.test(workspaceGossipSurface),
  'workspace RTCDataChannel state callback must update GossipController carrier state',
)
assert(
  /function requestGossipTopologyRepair\(peerId,\s*reason/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must define a focused topology repair request helper for assigned gossip neighbor loss',
)
assert(
  /function requestGossipTopologyRepair\(peerId,\s*reason[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.enabled \|\| !GOSSIP_DATA_LANE_CONFIG\.publish \|\| !GOSSIP_DATA_LANE_CONFIG\.receive\) return false;/.test(workspaceGossipSurface),
  'topology repair requests must be gated to the active gossip data lane, not off or shadow mode',
)
assert(
  /function requestGossipTopologyRepair\(peerId,\s*reason[\s\S]*if \(!assignedGossipNativeNeighborIds\.has\(String\(peerId \|\| ''\)\)\) return false;/.test(workspaceGossipSurface),
  'topology repair requests must only fire for currently assigned native gossip neighbors',
)
assert(
  /sendSocketFrame\(\{[\s\S]*type:\s*'gossip\/topology-repair\/request'[\s\S]*lane:\s*'ops'[\s\S]*lost_peer_id:\s*String\(peerId \|\| ''\)[\s\S]*reason:\s*String\(reason \|\| ''\)/.test(workspaceGossipSurface),
  'assigned-neighbor loss must request topology repair over the existing ops WebSocket lane',
)
assert(
  /onStateChange:\s*\(peerId,\s*state,\s*(eventType|reason)\)\s*=>\s*\{[\s\S]*if \(\(state === 'closed' \|\| (eventType|reason) === 'error'\)[\s\S]*requestGossipTopologyRepair\((peerId|normalizedPeerId),\s*(eventType|reason)\)/.test(workspaceGossipSurface),
  'RTCDataChannel close/error for an assigned neighbor must trigger a topology repair request',
)
assert(
  /eventType:\s*'gossip_topology_repair_requested'/.test(workspaceGossipSurface)
    && /code:\s*'gossip_topology_repair_requested'/.test(workspaceGossipSurface),
  'topology repair requests must emit a client diagnostic for observability',
)

console.log('[gossip-neighbor-health-topology-repair-contract] PASS')
