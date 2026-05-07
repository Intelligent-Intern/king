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
  /import \{ createCallWorkspaceGossipDataLane \} from '\.\/workspace\/callWorkspace\/gossipDataLane';/.test(callWorkspace)
    || /import \{ GOSSIP_DATA_LANE_CONFIG \} from '..\/..\/lib\/gossipmesh\/featureFlags';/.test(callWorkspace),
  'call workspace must import the gossip data-lane helper or feature flag',
)
assert(
  /import \{ GossipRtcDataChannelTransport \} from '(..\/..\/)?(\.\.\/\.\.\/)?lib\/gossipmesh\/rtcDataChannelTransport';/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must import the RTCDataChannel gossip transport',
)
assert(
  /if \(!GOSSIP_DATA_LANE_CONFIG\.enabled\) return null;/.test(workspaceGossipSurface)
    && /if \(!GOSSIP_DATA_LANE_CONFIG\.enabled\) return false;/.test(workspaceGossipSurface),
  'off mode must avoid creating or binding gossip data channels',
)
assert(
  /new GossipRtcDataChannelTransport\(\{[\s\S]*localPeerId/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must lazily create a gossip RTCDataChannel transport for the local peer',
)
assert(
  /transport\?\.bindPeerConnection\?\.\(normalizedPeerId,\s*pc,\s*peer\.initiator\)/.test(gossipNeighborLifecycle),
  'dedicated gossip neighbor peers must bind their RTCPeerConnection to the gossip data transport',
)
assert(
  /if \(!GOSSIP_DATA_LANE_CONFIG\.receive\)[\s\S]*gossip_data_lane_shadow_message_dropped[\s\S]*return;/.test(workspaceGossipSurface),
  'shadow mode must observe inbound data channel messages but drop before media decode',
)
assert(
  /gossip_data_lane_frame_routed/.test(workspaceGossipSurface),
  'active mode must emit a diagnostic when an accepted gossip frame is routed toward decode',
)
assert(
  /gossip_neighbor_offer/.test(gossipNeighborLifecycle)
    && /gossip_neighbor_answer/.test(gossipNeighborLifecycle)
    && /gossip_neighbor_ice/.test(gossipNeighborLifecycle)
    && /GOSSIP_NEIGHBOR_RUNTIME_PATH = 'gossip_primary_neighbor'/.test(gossipNeighborLifecycle),
  'dedicated gossip neighbor lifecycle must use its own signaling kinds and runtime path',
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
