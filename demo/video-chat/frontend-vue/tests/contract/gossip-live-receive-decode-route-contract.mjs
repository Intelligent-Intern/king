import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const callWorkspace = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/CallWorkspaceView.vue'), 'utf8')
const gossipDataLane = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.js'), 'utf8')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
const controller = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts'), 'utf8')
const lifecycle = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/lifecycle.js'), 'utf8')
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-live-receive-decode-route-contract] ${message}`)
  }
}

assert(
  /import \{ createCallWorkspaceGossipDataLane \} from '\.\/workspace\/callWorkspace\/gossipDataLane';/.test(callWorkspace)
    || /import \{ GossipController \} from '..\/..\/lib\/gossipmesh\/gossipController';/.test(callWorkspace),
  'call workspace must import the gossip data-lane helper or GossipController for the live receive path',
)
assert(
  /let liveGossipController = null;/.test(workspaceGossipSurface)
    && /let liveGossipControllerKey = '';/.test(workspaceGossipSurface)
    && /let unsubscribeLiveGossipDelivery = null;/.test(workspaceGossipSurface),
  'live gossip controller state must be explicit and resettable',
)
assert(
  /function ensureLiveGossipController\(\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.enabled\) return null;/.test(workspaceGossipSurface),
  'live GossipController must exist in enabled shadow or active mode for topology observation',
)
assert(
  /new GossipController\((roomId|roomId\(\)),\s*(callId|callId\(\))\)/.test(workspaceGossipSurface)
    && /controller\.setDataLaneConfig\(GOSSIP_DATA_LANE_CONFIG\)/.test(workspaceGossipSurface)
    && /controller\.setDataTransport\(transport\)/.test(workspaceGossipSurface),
  'live GossipController must be room/call scoped, feature-configured, and transport-backed',
)
assert(
  /if \(GOSSIP_DATA_LANE_CONFIG\.receive\) \{[\s\S]*controller\.onDataMessage\(\(delivery\) => \{[\s\S]*routeLiveGossipDeliveryToRemoteFrame\(delivery\);[\s\S]*\}\)/.test(workspaceGossipSurface),
  'accepted gossip deliveries must be routed toward the remote frame path only in active receive mode',
)
assert(
  /if \(!GOSSIP_DATA_LANE_CONFIG\.receive\)[\s\S]*gossip_data_lane_shadow_message_dropped[\s\S]*return;/.test(workspaceGossipSurface),
  'shadow mode must still drop incoming RTCDataChannel data before GossipController handling',
)
assert(
  /controller\.handleData\((String\(currentUserId\.value \|\| ''\)|localPeerId\(\)),\s*msg,\s*String\(fromPeerId \|\| ''\)\)/.test(workspaceGossipSurface),
  'active inbound RTCDataChannel messages must enter GossipController.handleData() as local receives',
)
assert(
  /function routeLiveGossipDeliveryToRemoteFrame\(delivery\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.receive\) return false;[\s\S]*msg\.type !== 'sfu\/frame'[\s\S]*handleSFUEncodedFrame\(frame\);/.test(workspaceGossipSurface),
  'accepted sfu/frame gossip deliveries must route to the existing remote decode entry point only in active receive mode',
)
assert(
  /function sfuFrameFromGossipMessage\(msg,\s*delivery\)[\s\S]*base64UrlToArrayBuffer\(dataBase64\)[\s\S]*transportPath:\s*'gossip_rtc_datachannel'/.test(workspaceGossipSurface),
  'gossip messages must be adapted into SFU frame objects with explicit gossip transport provenance',
)
assert(
  /gossip_data_lane_frame_routed/.test(workspaceGossipSurface),
  'active live routing must emit a diagnostic when a gossip frame enters the remote frame path',
)
assert(
  /dispose\(\):\s*void/.test(controller)
    && /this\.heartbeatTimers\.clear\(\)/.test(controller)
    && /this\.dataListeners = \[\]/.test(controller),
  'GossipController must expose dispose() to clear live heartbeat timers and listeners',
)
assert(
  /function teardownGossipDataLane\(\)[\s\S]*unsubscribeLiveGossipDelivery[\s\S]*liveGossipController\?\.dispose\?\.\(\)[\s\S]*gossipDataChannelTransport\?\.close\(\)/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must tear down live gossip controller and data channels',
)
assert(
  /callbacks\.teardownGossipDataLane\?\.\(\);[\s\S]*teardownNativePeerConnections\(\);/.test(lifecycle),
  'workspace lifecycle must tear down gossip data lane before native peer teardown',
)
assert(
  !/gossip_data_lane_frame_received_unrouted/.test(workspaceGossipSurface),
  'the live active path must no longer stop at the previous unrouted diagnostic',
)
assert(
  packageJson.includes('gossip-live-receive-decode-route-contract.mjs'),
  'gossip contract suite must include the live receive/decode route contract',
)

console.log('[gossip-live-receive-decode-route-contract] PASS')
