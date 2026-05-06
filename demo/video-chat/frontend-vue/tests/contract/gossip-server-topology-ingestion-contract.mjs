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
    throw new Error(`[gossip-server-topology-ingestion-contract] ${message}`)
  }
}

const callWorkspace = read('src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const packageJson = read('package.json')

assert(
  /const assignedGossipNativeNeighborIds = new Set\(\);/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must track server-assigned native gossip neighbors separately from native peers',
)
assert(
  /function bindGossipDataChannelForNativePeer\(peer\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.enabled\) return false;[\s\S]*if \(!assignedGossipNativeNeighborIds\.has\(peerId\)\) return false;[\s\S]*transport\.bindPeerConnection\(peerId,\s*peer\.pc,\s*Boolean\(peer\.initiator\)\)/.test(workspaceGossipSurface),
  'native gossip data channels must bind only after the server-assigned neighbor gate passes',
)
assert(
  /function applyGossipTopologyHint\(payload\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.enabled\) return false;[\s\S]*ensureLiveGossipController\(\)[\s\S]*controller\.applyTopologyHint\((localPeerId|peerId),\s*topologyHint\)/.test(workspaceGossipSurface),
  'topology hints must be inert when off and applied through the GossipController when enabled',
)
assert(
  /assignedGossipNativeNeighborIds\.clear\(\);[\s\S]*peer\?\.neighbor_set[\s\S]*assignedGossipNativeNeighborIds\.add/.test(workspaceGossipSurface)
    && /bindAssignedGossipNativeNeighbors\(\)/.test(workspaceGossipSurface),
  'applied topology must replace the assigned neighbor set and bind only those native peers',
)
assert(
  /function gossipTopologyNeighborUsesRtcDataChannel\(topologyHint,\s*peerId\)[\s\S]*neighbor\?\.transport[\s\S]*'rtc_datachannel'/.test(workspaceGossipSurface)
    && /gossipTopologyNeighborUsesRtcDataChannel\(topologyHint,\s*normalizedNeighborId\)/.test(workspaceGossipSurface),
  'native WebRTC data-channel binding must only use neighbors assigned to rtc_datachannel transport',
)
assert(
  /previousAssignedNeighborIds[\s\S]*closeGossipDataChannelForNativePeer\(previousPeerId\)/.test(workspaceGossipSurface),
  'topology changes must close gossip data channels for peers no longer assigned',
)
assert(
  /function normalizeGossipTopologyHintPayload\(payload\)[\s\S]*wrapperType === 'topology_hint'[\s\S]*wrapperType !== 'call\/gossip-topology'[\s\S]*return null/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must normalize direct topology_hint and call/gossip-topology server payloads without accepting arbitrary signaling messages',
)
assert(
  /applyGossipTopologyHint = \(\) => false/.test(socketLifecycle)
    && /'call\/gossip-topology'/.test(socketLifecycle)
    && /type === 'topology_hint'/.test(socketLifecycle)
    && /payloadBody\?\.kind \|\| payloadBody\?\.type/.test(socketLifecycle),
  'socket lifecycle must route call/gossip-topology and direct topology_hint payloads to the topology callback',
)
assert(
  /applyGossipTopologyHint,/.test(callWorkspace),
  'call workspace must pass the gossip topology callback into socket lifecycle helpers',
)
assert(
  /assignedGossipNativeNeighborIds\.clear\(\);[\s\S]*gossipDataChannelTransport\?\.close\(\)/.test(workspaceGossipSurface),
  'gossip teardown must clear assigned topology before closing transport state',
)
assert(
  /liveGossipControllerKey !== controllerKey[\s\S]*assignedGossipNativeNeighborIds\.clear\(\);[\s\S]*gossipDataChannelTransport\?\.close\(\);[\s\S]*gossipDataChannelTransport = null;/.test(workspaceGossipSurface),
  'room or call changes must clear stale assigned neighbors and close old gossip data channels',
)
assert(
  packageJson.includes('gossip-server-topology-ingestion-contract.mjs'),
  'gossip contract suite must include server topology ingestion contract',
)

console.log('[gossip-server-topology-ingestion-contract] PASS')
