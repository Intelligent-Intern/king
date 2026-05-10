import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { transformWithOxc } from 'vite'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const callWorkspacePath = path.join(frontendRoot, 'src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLanePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const gossipMediaFrameEnvelopePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts')
const mediaStackPath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/mediaStack.ts')
const nativeBridgeRuntimePath = path.join(frontendRoot, 'src/domain/realtime/native/bridgeRuntime.ts')
const socketLifecyclePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const publisherPipelinePath = path.join(frontendRoot, 'src/domain/realtime/local/publisherPipeline.ts')
const publisherFrameDispatchPath = path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts')
const packagePath = path.join(frontendRoot, 'package.json')

const callWorkspace = fs.readFileSync(callWorkspacePath, 'utf8')
const gossipDataLane = fs.readFileSync(gossipDataLanePath, 'utf8')
const gossipMediaFrameEnvelope = fs.readFileSync(gossipMediaFrameEnvelopePath, 'utf8')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}\n${gossipMediaFrameEnvelope}`
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

async function importSource(source, filename) {
  const transformed = await transformWithOxc(source, filename)
  return import(`data:text/javascript;base64,${Buffer.from(transformed.code).toString('base64')}`)
}

async function loadPublisherDispatchForMode(mode) {
  const gossipPrimary = mode === 'gossip_primary'
  const config = {
    mode,
    gossipPrimary,
    sfuFirst: mode === 'sfu_first',
    sfuMirror: mode === 'sfu_mirror',
    gossipMayPublishWithoutSfu: gossipPrimary,
    sfuRequiredBeforeGossip: !gossipPrimary,
    sfuSendIsOptional: mode === 'sfu_mirror',
    diagnosticsLabel: gossipPrimary ? 'media_carrier_gossip_primary' : 'media_carrier_sfu_first',
  }
  const source = publisherFrameDispatch
    .replace(
      "import { VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../lib/gossipmesh/featureFlags';\n",
      `const VIDEOCHAT_MEDIA_CARRIER_CONFIG = ${JSON.stringify(config)};\n`,
    )
    .replace(
      "import { reportSfuClientUnavailableAfterEncode } from './publisherPipelineSendFailures';\n",
      "function reportSfuClientUnavailableAfterEncode() {}\n",
    )
  return importSource(source, `publisherFrameDispatch.${mode}.ts`)
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
  /const msg = gossipFrameMessageFromEncodedFrame\(frame,\s*liveGossipFrameSequenceByTrack,\s*\{[\s\S]*plainRelay:\s*directGossipPrimary/.test(workspaceGossipSurface),
  'gossip_primary live publication must send plain transport frames instead of protected SFU frames',
)
assert(
  /function routeLiveGossipDeliveryToRemoteFrame\(delivery\)[\s\S]*const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary;[\s\S]*!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG\.receive[\s\S]*!directGossipPrimary && !gossipDataPlaneAllowed\(\)[\s\S]*transportPath: 'gossip_primary_direct'[\s\S]*protectionMode: 'transport_only'/.test(workspaceGossipSurface),
  'gossip_primary receive path must route frames directly to the decoder without data-plane gates',
)
assert(
  gossipMediaFrameEnvelope.includes("const GOSSIP_MEDIA_FRAME_CONTRACT_VERSION = 'v1.0.0';")
    && gossipMediaFrameEnvelope.includes("const GOSSIP_MEDIA_FRAME_PROFILE = 'video_720p30';")
    && gossipMediaFrameEnvelope.includes('const GOSSIP_MEDIA_FRAME_WIDTH = 1280;')
    && gossipMediaFrameEnvelope.includes('const GOSSIP_MEDIA_FRAME_HEIGHT = 720;')
    && gossipMediaFrameEnvelope.includes('const GOSSIP_MEDIA_FRAME_RATE = 30;'),
  'active gossip publication must pin the v1 720p30 external frame contract',
)
assert(
  gossipMediaFrameEnvelope.includes("const GOSSIP_MEDIA_WLVC_CODEC_ID = 'wlvc_v1';")
    && gossipMediaFrameEnvelope.includes("GOSSIP_MEDIA_WEB_CODECS_CODEC_IDS = Object.freeze(['webcodecs_vp8', 'webcodecs_vp9', 'webcodecs_av1'])")
    && /function gossipExternalCodecId\(frame\)[\s\S]*GOSSIP_MEDIA_WEB_CODECS_CODEC_IDS\.includes\(explicitCodecId\)[\s\S]*return GOSSIP_MEDIA_WLVC_CODEC_ID;/.test(gossipMediaFrameEnvelope)
    && /function gossipRuntimeEncoder\(frame\)[\s\S]*return 'webcodecs';[\s\S]*return 'wlvc_ts';[\s\S]*return 'wlvc_wasm';/.test(gossipMediaFrameEnvelope),
  'sprint publication must use WLVC as the active codec while preserving the existing WebCodecs branch',
)
assert(
  /function gossipFrameMessageFromEncodedFrame\(frame,\s*sequenceMap,[\s\S]*contract_version:\s*GOSSIP_MEDIA_FRAME_CONTRACT_VERSION[\s\S]*track_kind:\s*'video'[\s\S]*frame_kind:\s*frameKind[\s\S]*sequence:\s*frameSequence[\s\S]*runtime_path:\s*runtimePath[\s\S]*codec_id:\s*codecId[\s\S]*codec_runtime:\s*\{[\s\S]*encoder:\s*codecRuntimeEncoder[\s\S]*profile:\s*GOSSIP_MEDIA_FRAME_PROFILE[\s\S]*payload_encoding:\s*'base64url'[\s\S]*payload:\s*dataBase64/.test(gossipMediaFrameEnvelope),
  'outbound gossip.media.frame.v1 frames must carry the v1 codec/runtime/profile envelope before publication',
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

const gossipPrimaryDispatch = await loadPublisherDispatchForMode('gossip_primary')
let publishOrder = []
let diagnostics = []
let sfuAttempted = false
let result = await gossipPrimaryDispatch.dispatchPublisherFrame({
  frame: { trackId: 'camera-main', type: 'keyframe', data: new Uint8Array([1, 2, 3]).buffer },
  trackId: 'camera-main',
  mediaRuntimePath: 'wlvc_publisher',
  currentOpenSfuClient: () => ({
    sendEncodedFrame: async () => {
      sfuAttempted = true
      publishOrder.push('sfu')
      return true
    },
  }),
  getSfuClientBufferedAmount: () => 0,
  publishLocalEncodedFrameToGossip: () => {
    publishOrder.push('gossip')
    return true
  },
  captureClientDiagnostic: (event) => diagnostics.push(event),
  captureClientDiagnosticError: () => {},
})
assert(result.ok === true && result.gossipPublished === true && result.sfuSent === false, 'gossip_primary must not mirror a successful active Gossip publication into SFU')
assert(result.sfuFallbackSuppressed === true && sfuAttempted === false, 'gossip_primary must return before SFU lookup/send even when a socket is open')
assert(publishOrder.join(',') === 'gossip' && diagnostics.length === 0, 'successful gossip_primary publication must be Gossip-only')

publishOrder = []
diagnostics = []
sfuAttempted = false
result = await gossipPrimaryDispatch.dispatchPublisherFrame({
  frame: { trackId: 'camera-main', type: 'keyframe', data: new Uint8Array([1, 2, 3]).buffer },
  trackId: 'camera-main',
  mediaRuntimePath: 'wlvc_publisher',
  currentOpenSfuClient: () => ({
    sendEncodedFrame: async () => {
      sfuAttempted = true
      publishOrder.push('sfu')
      return true
    },
  }),
  getSfuClientBufferedAmount: () => 0,
  publishLocalEncodedFrameToGossip: () => {
    publishOrder.push('gossip')
    return false
  },
  captureClientDiagnostic: (event) => diagnostics.push(event),
  captureClientDiagnosticError: () => {},
})
assert(result.ok === false && result.gossipPublished === false && result.sfuSent === false, 'failed gossip_primary publication must not fall back to SFU')
assert(sfuAttempted === false && publishOrder.join(',') === 'gossip', 'gossip_primary failure path must remain Gossip-only')
assert(
  diagnostics.some((event) => event?.eventType === 'gossip_primary_publish_failed_no_sfu_fallback' && event?.immediate === true),
  'failed gossip_primary publication must diagnose suppressed SFU fallback',
)

console.log('[gossip-outbound-live-publication-contract] PASS')
