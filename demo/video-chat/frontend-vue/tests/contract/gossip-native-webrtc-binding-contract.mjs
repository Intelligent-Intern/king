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
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
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
    && /const dedicatedGossipPeerConnections = new Map\(\);/.test(gossipDataLane),
  'gossip data lane must track server-assigned gossip neighbors separately from dedicated gossip RTCPeerConnections',
)
assert(
  /function ensureDedicatedGossipNeighborConnection\(peerId,\s*reason = 'topology_hint'\)[\s\S]*new RTCPeerConnection\(gossipRtcConfig\(\)\)/.test(gossipDataLane),
  'server-assigned gossip neighbors must create dedicated gossip-only RTCPeerConnections',
)
assert(
  /transport\.bindPeerConnection\(normalizedPeerId,\s*pc,\s*entry\.initiator\)/.test(gossipDataLane)
    && !/transport\.bindPeerConnection\(peerId,\s*peer\.pc,\s*Boolean\(peer\.initiator\)\)/.test(gossipDataLane),
  'gossip RTCDataChannels must bind to dedicated gossip RTCPeerConnections, not native media peer connections',
)
assert(
  /'gossip_webrtc_offer'/.test(gossipDataLane)
    && /'gossip_webrtc_answer'/.test(gossipDataLane)
    && /'gossip_webrtc_ice'/.test(gossipDataLane),
  'dedicated gossip peer connections must use gossip-specific WebRTC signaling kinds',
)
assert(
  /function handleGossipSignalingEvent\(type,\s*senderUserId,\s*payloadBody = \{\}\)[\s\S]*gossip_webrtc_offer[\s\S]*handleDedicatedGossipOffer[\s\S]*handleDedicatedGossipAnswer[\s\S]*handleDedicatedGossipIce/.test(gossipDataLane),
  'gossip data lane must expose a dedicated signaling handler for gossip offers, answers, and ICE',
)
assert(
  /handleGossipSignalingEvent,/.test(gossipDataLane)
    && /handleGossipSignalingEvent,/.test(callWorkspace)
    && /handleGossipSignalingEvent = \(\) => false/.test(socketLifecycle),
  'call workspace must pass dedicated gossip signaling into socket lifecycle with a safe default',
)
assert(
  /payloadKind\.startsWith\('gossip_webrtc_'\)[\s\S]*handleGossipSignalingEvent\(type,\s*senderUserId,\s*payloadBody \|\| \{\}\)[\s\S]*return;/.test(socketLifecycle),
  'socket lifecycle must consume gossip WebRTC signaling before native media signaling detection',
)
assert(
  /constants:\s*\{[\s\S]*defaultNativeIceServers:\s*DEFAULT_NATIVE_ICE_SERVERS[\s\S]*\}[\s\S]*refs:\s*\{\s*dynamicIceServers\s*\}/.test(callWorkspace),
  'call workspace must give gossip-only peer connections the same ICE-server source as native WebRTC',
)
assert(
  /function bindGossipDataChannelForNativePeer\(peer\)[\s\S]*gossipDataChannelState = 'dedicated_peer_connection'[\s\S]*return false;/.test(gossipDataLane),
  'legacy native binding hook must be compatibility-only after dedicated gossip peer connections are introduced',
)
assert(
  /function closeGossipDataChannelForNativePeer\(_peerId\)[\s\S]*return false;/.test(gossipDataLane),
  'legacy native close hook must not tear down dedicated gossip-only peer connections',
)
assert(
  /bindGossipDataChannelForNativePeer = \(\) => false/.test(peerFactory)
    && /bindGossipDataChannelForNativePeer\(existing\)/.test(peerFactory)
    && /bindGossipDataChannelForNativePeer\(peer\)/.test(peerFactory),
  'native peer factory may keep the optional compatibility hook but must not own the gossip carrier',
)
assert(
  /bindGossipDataChannelForNativePeer: callbacks\.bindGossipDataChannelForNativePeer/.test(nativeStack)
    && /closeGossipDataChannelForNativePeer: callbacks\.closeGossipDataChannelForNativePeer/.test(nativeStack)
    && /closeGossipDataChannelForNativePeer = \(\) => false/.test(peerLifecycle),
  'native stack compatibility wiring must remain non-breaking while gossip carrier lifecycle is dedicated',
)
assert(
  packageJson.includes('gossip-native-webrtc-binding-contract.mjs'),
  'gossip contract suite must include the native WebRTC binding contract',
)

console.log('[gossip-native-webrtc-binding-contract] PASS')
