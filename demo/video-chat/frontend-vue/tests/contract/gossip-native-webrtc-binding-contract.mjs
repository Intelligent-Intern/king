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
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
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
  /transport\.bindPeerConnection\(peerId,\s*peer\.pc,\s*Boolean\(peer\.initiator\)\)/.test(workspaceGossipSurface),
  'native peers must bind their RTCPeerConnection to the gossip data transport',
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
  /peer\.gossipDataLaneMode = GOSSIP_DATA_LANE_CONFIG\.mode/.test(workspaceGossipSurface)
    && /peer\.gossipDataChannelState = String\(channel\?\.readyState \|\| 'pending'\)/.test(workspaceGossipSurface),
  'native peer state must expose gossip data-lane mode and channel readiness',
)
assert(
  /bindGossipDataChannelForNativePeer,\s*\n\s*bumpMediaRenderVersion/.test(callWorkspace)
    && /closeGossipDataChannelForNativePeer,\s*\n\s*clearRemoteVideoContainer/.test(callWorkspace),
  'native stack callbacks must include gossip bind and close hooks',
)
assert(
  /bindGossipDataChannelForNativePeer = \(\) => false/.test(peerFactory),
  'native peer factory must keep gossip binding optional for off mode and tests',
)
assert(
  /bindGossipDataChannelForNativePeer\(existing\)/.test(peerFactory)
    && /bindGossipDataChannelForNativePeer\(peer\)/.test(peerFactory),
  'native peer factory must bind gossip channels for existing and new native peers',
)
assert(
  /bindGossipDataChannelForNativePeer: callbacks\.bindGossipDataChannelForNativePeer/.test(nativeStack),
  'call workspace native stack must pass the gossip bind callback into peer factory',
)
assert(
  /closeGossipDataChannelForNativePeer: callbacks\.closeGossipDataChannelForNativePeer/.test(nativeStack),
  'call workspace native stack must pass the gossip close callback into peer lifecycle',
)
assert(
  /closeGossipDataChannelForNativePeer = \(\) => false/.test(peerLifecycle)
    && /closeGossipDataChannelForNativePeer\(normalizedTargetUserId\)/.test(peerLifecycle),
  'native peer lifecycle must close gossip data channels when native peers close',
)
assert(
  packageJson.includes('gossip-native-webrtc-binding-contract.mjs'),
  'gossip contract suite must include the native WebRTC binding contract',
)

console.log('[gossip-native-webrtc-binding-contract] PASS')
