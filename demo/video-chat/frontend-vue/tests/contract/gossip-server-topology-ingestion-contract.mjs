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
  /const assignedGossipNeighborIds = new Set\(\);/.test(workspaceGossipSurface)
    && /const dedicatedGossipPeerConnections = new Map\(\);/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must track assigned gossip neighbors and dedicated gossip peer connections',
)
assert(
  /function ensureDedicatedGossipNeighborConnection\(peerId,\s*reason = 'topology_hint'\)[\s\S]*if \(!assignedGossipNeighborIds\.has\(normalizedPeerId\)\) return false;[\s\S]*new RTCPeerConnection\(gossipRtcConfig\(\)\)[\s\S]*transport\.bindPeerConnection\(normalizedPeerId,\s*pc,\s*entry\.initiator\)/.test(workspaceGossipSurface),
  'dedicated gossip data channels must be created only for server-assigned neighbors',
)
assert(
  /function applyGossipTopologyHint\(payload\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.enabled\) return false;[\s\S]*ensureLiveGossipController\(\)[\s\S]*controller\.applyTopologyHint\((localPeerId|peerId),\s*topologyHint\)/.test(workspaceGossipSurface),
  'topology hints must be inert when off and applied through the GossipController when enabled',
)
assert(
  /assignedGossipNeighborIds\.clear\(\);[\s\S]*peer\?\.neighbor_set[\s\S]*assignedGossipNeighborIds\.add/.test(workspaceGossipSurface)
    && /ensureAssignedGossipNeighborConnections\('topology_hint_applied'\)/.test(workspaceGossipSurface),
  'applied topology must replace the assigned neighbor set and ensure only those dedicated gossip connections',
)
assert(
  /function gossipTopologyNeighborUsesRtcDataChannel\(topologyHint,\s*peerId\)[\s\S]*neighbor\?\.transport[\s\S]*'rtc_datachannel'/.test(workspaceGossipSurface)
    && /gossipTopologyNeighborUsesRtcDataChannel\(topologyHint,\s*normalizedNeighborId\)/.test(workspaceGossipSurface),
  'dedicated gossip WebRTC binding must only use neighbors assigned to rtc_datachannel transport',
)
assert(
  /previousAssignedNeighborIds[\s\S]*closeDedicatedGossipPeerConnection\(previousPeerId,\s*'topology_neighbor_removed'\)/.test(workspaceGossipSurface),
  'topology changes must close dedicated gossip peer connections for peers no longer assigned',
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
  /assignedGossipNeighborIds\.clear\(\);[\s\S]*gossipDataChannelTransport\?\.close\(\)/.test(workspaceGossipSurface),
  'gossip teardown must clear assigned topology before closing transport state',
)
assert(
  /liveGossipControllerKey !== controllerKey[\s\S]*closeAllDedicatedGossipPeerConnections\('controller_key_changed'\);[\s\S]*assignedGossipNeighborIds\.clear\(\);[\s\S]*gossipDataChannelTransport\?\.close\(\);[\s\S]*gossipDataChannelTransport = null;/.test(workspaceGossipSurface),
  'room or call changes must clear stale assigned neighbors and close old dedicated gossip peer connections',
)
assert(
  packageJson.includes('gossip-server-topology-ingestion-contract.mjs'),
  'gossip contract suite must include server topology ingestion contract',
)

console.log('[gossip-server-topology-ingestion-contract] PASS')
