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
    throw new Error(`[gossip-native-webrtc-binding-contract] ${message}`)
  }
}

const callWorkspace = read('src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLane = read('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const gossipNeighborLifecycle = read('src/domain/realtime/workspace/callWorkspace/gossipNeighborLifecycle.ts')
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}\n${gossipNeighborLifecycle}\n${socketLifecycle}`
const nativeStack = read('src/domain/realtime/workspace/callWorkspace/nativeStack.ts')
const peerFactory = read('src/domain/realtime/native/peerFactory.ts')
const peerLifecycle = read('src/domain/realtime/native/peerLifecycle.ts')
const packageJson = read('package.json')

assert(
  /import \{ createCallWorkspaceGossipDataLane \} from '\.\/workspace\/callWorkspace\/gossipDataLane';/.test(callWorkspace),
  'call workspace must import the gossip data-lane helper',
)
assert(
  /import \{ GossipRtcDataChannelTransport \} from '(..\/..\/)?(\.\.\/\.\.\/)?lib\/gossipmesh\/rtcDataChannelTransport';/.test(gossipDataLane),
  'workspace gossip data-lane implementation must import the RTCDataChannel gossip transport',
)
assert(
  /const assignedGossipNeighborIds = new Set\(\);/.test(gossipDataLane)
    && /createGossipNeighborLifecycle\(\{/.test(gossipDataLane),
  'gossip data lane must track server-assigned gossip neighbors and delegate dedicated peer lifecycle to the neighbor helper',
)
assert(
  /const peers = new Map\(\);/.test(gossipNeighborLifecycle)
    && /new RTCPeerConnection\(peerConnectionConfig\(\)\)/.test(gossipNeighborLifecycle),
  'server-assigned gossip neighbors must create dedicated gossip-only RTCPeerConnections inside the neighbor lifecycle',
)
assert(
  /getIceServers = \(\) => DEFAULT_NATIVE_ICE_SERVERS/.test(gossipNeighborLifecycle)
    && /getIceServers: currentGossipIceServers/.test(gossipDataLane)
    && /dynamicIceServers/.test(callWorkspace),
  'dedicated gossip neighbor lifecycle must preserve dynamic ICE/TURN server selection',
)
assert(
  /transport\?\.bindPeerConnection\?\.\(normalizedPeerId,\s*pc,\s*peer\.initiator\)/.test(gossipNeighborLifecycle),
  'dedicated gossip neighbor peers must bind their RTCPeerConnection to the gossip data transport',
)
assert(
  /gossip_neighbor_offer/.test(gossipNeighborLifecycle)
    && /gossip_neighbor_answer/.test(gossipNeighborLifecycle)
    && /gossip_neighbor_ice/.test(gossipNeighborLifecycle)
    && /GOSSIP_NEIGHBOR_RUNTIME_PATH = 'gossip_primary_neighbor'/.test(gossipNeighborLifecycle),
  'dedicated gossip peer connections must use gossip-specific neighbor signaling kinds and runtime path',
)
assert(
  /LEGACY_GOSSIP_WEBRTC_SIGNAL_KINDS/.test(gossipNeighborLifecycle)
    && /gossip_webrtc_offer/.test(gossipNeighborLifecycle)
    && /gossip_webrtc_answer/.test(gossipNeighborLifecycle)
    && /gossip_webrtc_ice/.test(gossipNeighborLifecycle),
  'dedicated gossip neighbor lifecycle must keep compatibility with legacy gossip_webrtc signaling kinds',
)
assert(
  /function handleGossipNeighborSignal\(type,\s*senderPeerId,\s*payload\)[\s\S]*handleOffer[\s\S]*handleAnswer[\s\S]*handleIce/.test(gossipNeighborLifecycle),
  'gossip neighbor lifecycle must expose a dedicated signaling handler for offers, answers, and ICE',
)
assert(
  /handleGossipNeighborSignal/.test(callWorkspace)
    && /if \(handleGossipNeighborSignal\(type,\s*senderUserId,\s*payloadBody \|\| \{\}\)\) return;[\s\S]*const payloadKind/.test(socketLifecycle),
  'workspace socket handling must route gossip neighbor signaling before native media signaling',
)
assert(
  !/bindGossipDataChannelForNativePeer/.test(gossipDataLane)
    && !/closeGossipDataChannelForNativePeer/.test(callWorkspace)
    && !/bindGossipDataChannelForNativePeer,\s*\n\s*bumpMediaRenderVersion/.test(callWorkspace),
  'workspace gossip data lane must not attach media gossip to arbitrary native media peer connections',
)
assert(
  /bindGossipDataChannelForNativePeer = \(\) => false/.test(peerFactory),
  'native peer factory may keep an inert optional compatibility callback for older tests',
)
assert(
  !/bindGossipDataChannelForNativePeer: callbacks\.bindGossipDataChannelForNativePeer/.test(nativeStack),
  'call workspace native stack must not receive active gossip bind callbacks',
)
assert(
  !/closeGossipDataChannelForNativePeer: callbacks\.closeGossipDataChannelForNativePeer/.test(nativeStack),
  'call workspace native stack must not receive active gossip close callbacks',
)
assert(
  /closeGossipDataChannelForNativePeer = \(\) => false/.test(peerLifecycle)
    && /closeGossipDataChannelForNativePeer\(normalizedTargetUserId\)/.test(peerLifecycle),
  'native peer lifecycle keeps an inert optional close hook for compatibility but no active workspace callback is passed',
)
assert(
  packageJson.includes('gossip-native-webrtc-binding-contract.mjs'),
  'gossip contract suite must include the native WebRTC binding contract',
)

console.log('[gossip-native-webrtc-binding-contract] PASS')
