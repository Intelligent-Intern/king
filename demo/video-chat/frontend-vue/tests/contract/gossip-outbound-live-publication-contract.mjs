import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const callWorkspacePath = path.join(frontendRoot, 'src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLanePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const mediaStackPath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/mediaStack.ts')
const nativeBridgeRuntimePath = path.join(frontendRoot, 'src/domain/realtime/native/bridgeRuntime.ts')
const socketLifecyclePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const publisherPipelinePath = path.join(frontendRoot, 'src/domain/realtime/local/publisherPipeline.ts')
const publisherFrameDispatchPath = path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts')
const packagePath = path.join(frontendRoot, 'package.json')

const callWorkspace = fs.readFileSync(callWorkspacePath, 'utf8')
const gossipDataLane = fs.readFileSync(gossipDataLanePath, 'utf8')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
const mediaStack = fs.readFileSync(mediaStackPath, 'utf8')
const nativeBridgeRuntime = fs.readFileSync(nativeBridgeRuntimePath, 'utf8')
const socketLifecycle = fs.readFileSync(socketLifecyclePath, 'utf8')
const publisherPipeline = fs.readFileSync(publisherPipelinePath, 'utf8')
const publisherFrameDispatch = fs.readFileSync(publisherFrameDispatchPath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')
const featureFlags = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/featureFlags.ts'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-outbound-live-publication-contract] ${message}`)
  }
}

assert(
  /import \{ arrayBufferToBase64Url,\s*base64UrlToArrayBuffer \} from '(..\/..\/)?(\.\.\/\.\.\/)?lib\/sfu\/framePayload';/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must import arrayBufferToBase64Url for outbound gossip payload conversion',
)
assert(
  /function publishLocalEncodedFrameToGossip\(frame\)[\s\S]*const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary;[\s\S]*if \(!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG\.publish\)[\s\S]*recordGossipShadowWouldPublish\(frame, 'publish_disabled'\);[\s\S]*if \(!directGossipPrimary && !gossipDataPlaneAllowed\(\)\)[\s\S]*recordGossipShadowWouldPublish\(frame, 'rollout_gate_blocked'\);[\s\S]*controller\.publishFrame\((String\(currentUserId\.value \|\| ''\)|peerId),\s*msg\);/.test(workspaceGossipSurface),
  'outbound live gossip publication must bypass publish and rollout gates for gossip_primary before publishFrame()',
)
assert(
  /const msg = gossipFrameMessageFromEncodedFrame\(frame,\s*liveGossipFrameSequenceByTrack,\s*directGossipPrimary\);/.test(workspaceGossipSurface),
  'gossip_primary live publication must send plain transport frames instead of protected SFU frames',
)
assert(
  /function routeLiveGossipDeliveryToRemoteFrame\(delivery\)[\s\S]*const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary;[\s\S]*!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG\.receive[\s\S]*!directGossipPrimary && !gossipDataPlaneAllowed\(\)[\s\S]*transportPath: 'gossip_primary_direct'[\s\S]*protectionMode: 'transport_only'/.test(workspaceGossipSurface),
  'gossip_primary receive path must route frames directly to the decoder without data-plane gates',
)
assert(
  /function gossipDataPlaneAllowed\(\)[\s\S]*if \(gossipActiveDataLaneAllowed\(\)\) return true;[\s\S]*gossipPrimaryTopologyReady\(\)/.test(workspaceGossipSurface),
  'gossip_primary must accept outbound frames on assigned topology instead of deadlocking on pre-publication telemetry',
)
assert(
  /const dataBase64 = dataBuffer\.byteLength > 0 \? arrayBufferToBase64Url\(dataBuffer\) : '';/.test(workspaceGossipSurface)
    && /data_base64:\s*dataBase64/.test(workspaceGossipSurface)
    && /protected_frame:\s*protectedFrame/.test(workspaceGossipSurface)
    && /protection_mode:\s*protectionMode/.test(workspaceGossipSurface),
  'outbound gossip frames must preserve SFU payload and protection fields',
)
assert(
  /const liveGossipFrameSequenceByTrack = new Map\(\);/.test(workspaceGossipSurface)
    && /frame_sequence:\s*frameSequence/.test(workspaceGossipSurface)
    && /liveGossipFrameSequenceByTrack\.clear\(\);/.test(workspaceGossipSurface),
  'outbound gossip frames must have local per-track sequences that reset with the live controller',
)
assert(
  !callWorkspace.includes('relaySocketUrlForCall')
    && !callWorkspace.includes("relay', 'media'")
    && !callWorkspace.includes('relay=media'),
  'call workspace must not open a dedicated gossip server relay websocket',
)
assert(
  /function publishLocalEncodedFrameToGossip\(frame\)[\s\S]*const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary;[\s\S]*controller\.publishFrame\((String\(currentUserId\.value \|\| ''\)|peerId),\s*msg\);/.test(workspaceGossipSurface)
    && !gossipDataLane.includes('publishLocalEncodedFrameToServerRelay'),
  'gossip_primary must publish directly through the browser gossip controller, not through a server relay',
)
assert(
  !socketLifecycle.includes('call/gossip-server-frame')
    && /handleGossipNeighborSignal:\s*\(\.\.\.args\) => handleGossipRecoveryOpsMessage\(\.\.\.args\) \|\| ensureGossipNeighborLifecycle\(\)\?\.handleGossipNeighborSignal\?\.\(\.\.\.args\) \|\| false/.test(gossipDataLane),
  'active websocket and gossip-neighbor paths must not consume gossip server relay frames',
)
assert(
  !featureFlags.includes('GOSSIP_SERVER_RELAY_CONFIG')
    && !featureFlags.includes('VITE_VIDEOCHAT_GOSSIP_SERVER_RELAY'),
  'gossip server relay config must not exist in the frontend feature flags',
)
assert(
  /publishLocalEncodedFrameToGossip,/.test(workspaceGossipSurface)
    && /publishLocalEncodedFrameToGossip:\s*callbacks\.publishLocalEncodedFrameToGossip/.test(mediaStack),
  'call workspace must expose the live gossip publisher callback through mediaStack',
)
assert(
  /publishLocalEncodedFrameToGossip = \(\) => false/.test(publisherPipeline),
  'publisher pipeline must default the gossip hook to a no-op for non-gossip callers',
)
assert(
  !callWorkspace.includes('createCallWorkspaceMediaSecurityRuntime')
    && !callWorkspace.includes('createMediaSecuritySession'),
  'call workspace must not attach the media-security runtime in gossip_primary mode',
)
assert(
  /const MEDIA_SECURITY_SIGNAL_TYPES = Object\.freeze\(\[\]\);/.test(callWorkspace)
    && /protectedMediaEnabled:\s*false/.test(callWorkspace),
  'gossip_primary must publish transport-only frames without media-security signal handling',
)
assert(
  !socketLifecycle.includes('media-security')
    && !socketLifecycle.includes('media_security')
    && !socketLifecycle.includes('MediaSecurity'),
  'websocket lifecycle must not run media-security handshake, sync, or sender-key handling',
)
assert(
  /function attachMediaSecurityNativeSender\(sender, track\)[\s\S]*if \(!refs\.MediaSecuritySession\.supportsNativeTransforms\(\)\) return true;/.test(nativeBridgeRuntime)
    && /function attachMediaSecurityNativeReceiver\(receiver, senderUserId, track\)[\s\S]*if \(!refs\.MediaSecuritySession\.supportsNativeTransforms\(\)\) return true;/.test(nativeBridgeRuntime),
  'native WebRTC sender and receiver attachment must not block on media-security when transforms are disabled',
)

const sfuSendIndex = publisherFrameDispatch.indexOf('sendClient.sendEncodedFrame(frame)')
const mirrorGossipIndex = publisherFrameDispatch.indexOf('if (!gossipFirst)', sfuSendIndex)
assert(
  sfuSendIndex >= 0 && mirrorGossipIndex > sfuSendIndex,
  'publisher frame dispatch must keep SFU-first modes conservative before mirrored Gossip publish',
)
assert(
  /captureClientDiagnosticError\)\('gossip_data_lane_publish_failed'/.test(publisherFrameDispatch),
  'gossip publication failures must be diagnosed without breaking the SFU send path',
)
assert(
  packageJson.includes('gossip-outbound-live-publication-contract.mjs'),
  'gossip contract suite must include the outbound live publication contract',
)

console.log('[gossip-outbound-live-publication-contract] PASS')
