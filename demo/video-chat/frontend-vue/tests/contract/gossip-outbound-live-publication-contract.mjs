import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { transformWithOxc } from 'vite'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const callWorkspacePath = path.join(frontendRoot, 'src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLanePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const gossipMediaRelaySocketPath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipMediaRelaySocket.ts')
const gossipMediaFrameEnvelopePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts')
const mediaStackPath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/mediaStack.ts')
const nativeBridgeRuntimePath = path.join(frontendRoot, 'src/domain/realtime/native/bridgeRuntime.ts')
const socketLifecyclePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const publisherPipelinePath = path.join(frontendRoot, 'src/domain/realtime/local/publisherPipeline.ts')
const publisherFrameDispatchPath = path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts')
const packagePath = path.join(frontendRoot, 'package.json')

const callWorkspace = fs.readFileSync(callWorkspacePath, 'utf8')
const gossipDataLane = fs.readFileSync(gossipDataLanePath, 'utf8')
const gossipMediaRelaySocket = fs.readFileSync(gossipMediaRelaySocketPath, 'utf8')
const gossipMediaFrameEnvelope = fs.readFileSync(gossipMediaFrameEnvelopePath, 'utf8')
const workspaceApi = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/api.ts'), 'utf8')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}\n${gossipMediaRelaySocket}\n${gossipMediaFrameEnvelope}\n${workspaceApi}`
const mediaStack = fs.readFileSync(mediaStackPath, 'utf8')
const nativeBridgeRuntime = fs.readFileSync(nativeBridgeRuntimePath, 'utf8')
const socketLifecycle = fs.readFileSync(socketLifecyclePath, 'utf8')
const publisherPipeline = fs.readFileSync(publisherPipelinePath, 'utf8')
const publisherFrameDispatch = fs.readFileSync(publisherFrameDispatchPath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')
const featureFlags = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/featureFlags.ts'), 'utf8')
const liveGossipReceiveStart = gossipDataLane.indexOf('function routeLiveGossipDeliveryToRemoteFrame(delivery)')
const liveGossipRendererStart = gossipDataLane.indexOf('function routeGossipMediaFrameToRenderer(frame, directGossipPrimary)')
const liveGossipReceiveBody = liveGossipReceiveStart >= 0 && liveGossipRendererStart > liveGossipReceiveStart
  ? gossipDataLane.slice(liveGossipReceiveStart, liveGossipRendererStart)
  : ''
const liveGossipRendererBody = liveGossipRendererStart >= 0
  ? gossipDataLane.slice(liveGossipRendererStart, gossipDataLane.indexOf('function sendGossipFrameOverCallSocket', liveGossipRendererStart))
  : ''

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
    .replace(
      "import { normalizeAuthoritativePublisherTransport } from './authoritativePublisherMediaProfile';\n",
      "function normalizeAuthoritativePublisherTransport(value) { return String(value || '').trim().toLowerCase(); }\n",
    )
  return importSource(source, `publisherFrameDispatch.${mode}.ts`)
}

assert(
  /encodeSfuBinaryFrameEnvelope/.test(workspaceGossipSurface)
    && /prepareSfuOutboundFramePayload/.test(workspaceGossipSurface)
    && /decodeSfuBinaryFrameEnvelope/.test(workspaceGossipSurface),
  'workspace gossip data-lane implementation must use the binary SFU envelope for Gossip media fanout',
)
assert(
  /function publishLocalEncodedFrameToGossip\(frame\)[\s\S]*const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary;[\s\S]*if \(!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG\.publish\)[\s\S]*recordGossipShadowWouldPublish\(frame, 'publish_disabled'\);[\s\S]*controller\.publishFrame\((String\(currentUserId\.value \|\| ''\)|peerId),\s*directMsg\);/.test(workspaceGossipSurface)
    && !/publishLocalEncodedFrameToGossip\(frame\)[\s\S]*gossipDataPlaneAllowed\(\)[\s\S]*recordGossipShadowWouldPublish\(frame, 'rollout_gate_blocked'\)/.test(workspaceGossipSurface),
  'outbound live gossip publication must not run client health or rollout gates before publishFrame()',
)
assert(
  /const msg = gossipFrameMetadataFromEncodedFrame\(frame,\s*liveGossipFrameSequenceByTrack,\s*\{[\s\S]*plainRelay:\s*directGossipPrimary/.test(workspaceGossipSurface),
  'gossip_primary live publication must allocate metadata without base64 payload before binary fanout',
)
assert(
  /const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary;[\s\S]*!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG\.receive[\s\S]*routeGossipMediaFrameToRenderer\(frame, directGossipPrimary\)/.test(liveGossipReceiveBody)
    && /transportPath: 'gossip_primary_direct'[\s\S]*protectionMode: 'transport_only'/.test(liveGossipRendererBody)
    && !liveGossipReceiveBody.includes('gossipDataPlaneAllowed()'),
  'gossip receive path must route frames directly without client data-plane health gates',
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
  /function gossipFrameMessageFromEncodedFrame\(frame,\s*sequenceMap,[\s\S]*contract_version:\s*GOSSIP_MEDIA_FRAME_CONTRACT_VERSION[\s\S]*track_kind:\s*'video'[\s\S]*frame_kind:\s*frameKind[\s\S]*sequence:\s*frameSequence[\s\S]*runtime_path:\s*runtimePath[\s\S]*codec_id:\s*codecId[\s\S]*codec_runtime:\s*\{[\s\S]*encoder:\s*codecRuntimeEncoder[\s\S]*profile:\s*GOSSIP_MEDIA_FRAME_PROFILE[\s\S]*payload_encoding:\s*'binary'/.test(gossipMediaFrameEnvelope),
  'outbound gossip.media.frame.v1 frames must carry the v1 codec/runtime/profile envelope before publication',
)
assert(
  /function gossipDataPlaneAllowed\(\)[\s\S]*return GOSSIP_DATA_LANE_CONFIG\.mode === 'active'[\s\S]*GOSSIP_DATA_LANE_CONFIG\.publish[\s\S]*GOSSIP_DATA_LANE_CONFIG\.receive[\s\S]*!strictGossipMediaDisabled\(\);/.test(workspaceGossipSurface)
    && !/gossipActiveDataLaneAllowed|gossipPrimaryTopologyReady|diagnoseGossipPrimaryTopologyAdmission/.test(workspaceGossipSurface),
  'gossip clients must not run local health/topology admission gates; server-head ops lane owns that state',
)
assert(
  /function gossipBinaryEnvelopeFromEncodedFrame\(frame,\s*msg\)[\s\S]*prepareSfuOutboundFramePayload\(\{[\s\S]*data:\s*dataBuffer[\s\S]*protectionMode:\s*'transport_only'[\s\S]*return encodeSfuBinaryFrameEnvelope\(prepared\);/.test(workspaceGossipSurface)
    && /function gossipFrameBinaryMessageFromMetadata\(frame,\s*msg\)[\s\S]*data_binary:\s*new Uint8Array\(dataBuffer\)/.test(workspaceGossipSurface)
    && /function sendGossipFrameOverCallSocket\(msg,\s*frame = null\)[\s\S]*sendMediaRelayBinaryFrame[\s\S]*sendSocketBinaryFrame[\s\S]*return true;[\s\S]*return false;/.test(workspaceGossipSurface)
    && !/type:\s*'gossip\/server-frame'/.test(gossipDataLane)
    && !/arrayBufferToBase64Url|base64UrlToArrayBuffer|data_base64|dataBase64/.test(gossipMediaFrameEnvelope),
  'outbound Gossip server fanout and direct data-channel messages must carry binary media with no JSON/Base64 media fallback',
)
assert(
  /const liveGossipFrameSequenceByTrack = new Map\(\);/.test(workspaceGossipSurface)
    && /frame_sequence:\s*frameSequence/.test(workspaceGossipSurface)
    && /liveGossipFrameSequenceByTrack\.clear\(\);/.test(workspaceGossipSurface),
  'outbound gossip frames must have local per-track sequences that reset with the live controller',
)
assert(
  callWorkspace.includes('createGossipMediaRelaySocket')
    && workspaceApi.includes('mediaRelaySocketUrlForRoom')
    && workspaceApi.includes("query.set('relay', 'media')")
    && gossipMediaRelaySocket.includes('new WebSocket(url)')
    && gossipMediaRelaySocket.includes("nextSocket.binaryType = 'arraybuffer'")
    && gossipMediaRelaySocket.includes('handleGossipBinaryServerFrame(binaryPayload)')
    && !/data_base64|dataBase64|base64/.test(gossipMediaRelaySocket),
  'call workspace must open the authenticated binary Gossip media relay socket and route inbound binary frames without Base64',
)
assert(
  /function publishLocalEncodedFrameToGossip\(frame\)[\s\S]*const directGossipPrimary = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary;[\s\S]*controller\.publishFrame\((String\(currentUserId\.value \|\| ''\)|peerId),\s*directMsg\);/.test(workspaceGossipSurface)
    && /sendGossipFrameOverCallSocket\(msg,\s*frame\)/.test(gossipDataLane),
  'gossip_primary must publish through the browser gossip controller and mirror frames over the existing call socket',
)
assert(
  /const openGossipDataChannelPeerIds = new Set\(\);/.test(gossipDataLane)
    && /function directGossipEgressCanAcceptLocalFrame\(controller,\s*peerId\)[\s\S]*GOSSIP_DATA_LANE_CONFIG\.publish[\s\S]*gossipDataChannelTransport[\s\S]*assignedGossipNeighborIds\.has\(normalizedNeighborId\)[\s\S]*openGossipDataChannelPeerIds\.has\(normalizedNeighborId\)/.test(gossipDataLane),
  'gossip_primary local publication success must require an open assigned RTC data-channel egress',
)
assert(
  /const directGossipEgressAccepted = directGossipPrimary[\s\S]*directGossipEgressCanAcceptLocalFrame\(controller,\s*peerId\)[\s\S]*const gossipPrimaryEgressAvailable = serverFanoutSent \|\| directGossipEgressAccepted;/.test(gossipDataLane),
  'gossip_primary publication success must be gated on a real websocket send or open data-channel egress acceptance',
)
assert(
  /if \(directGossipPrimary && !gossipPrimaryEgressAvailable\) \{[\s\S]*open_data_channel_neighbor_count: openGossipDataChannelPeerIds\.size[\s\S]*direct_gossip_egress_accepted:\s*false[\s\S]*eventType: 'gossip_server_fanout_socket_unavailable'[\s\S]*immediate:\s*true[\s\S]*return false;[\s\S]*\}[\s\S]*if \(controller && directGossipEgressAccepted\) \{/.test(gossipDataLane)
    && !/gossipRecoveryState\.rememberPublishedFrame/.test(gossipDataLane),
  'gossip_primary must return publication failure, flush diagnostics, and suppress delivery marking when server fanout and datachannel egress are unavailable',
)
assert(
  !/if \(directGossipPrimary\) \{[\s\S]*eventType: 'gossip_server_fanout_socket_unavailable'[\s\S]*return true;[\s\S]*\}/.test(gossipDataLane)
    && /if \(directGossipPrimary\) \{[\s\S]*return gossipPrimaryEgressAvailable;[\s\S]*\}/.test(gossipDataLane),
  'gossip_primary must not treat unavailable server fanout as successful publication',
)
assert(
  socketLifecycle.includes("type === 'call/gossip-server-frame'")
    && /handleGossipServerFrame,/.test(gossipDataLane)
    && /socket\.binaryType = 'arraybuffer';/.test(socketLifecycle)
    && /handleGossipBinaryServerFrame,/.test(gossipDataLane),
  'active websocket must consume JSON and binary Gossip server fanout frames and route them to the decoder',
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
  /connectionState:\s*refs\.connectionState/.test(mediaStack)
    && /isSocketOnline:\s*refs\.isSocketOnline/.test(mediaStack),
  'media stack must pass realtime socket state into the publisher pipeline',
)
assert(
  /function realtimeSocketAllowsOutboundMedia\(\)[\s\S]*refs\.isSocketOnline\.value !== true[\s\S]*connectionState !== '' && connectionState !== 'online'/.test(publisherPipeline),
  'publisher pipeline must block outbound encoding unless the realtime socket is online',
)
assert(
  /local_publisher_stopped_realtime_socket_unavailable/.test(publisherPipeline)
    && /if \(!realtimeSocketAllowsOutboundMedia\(\)\) \{[\s\S]*stopOutboundMediaForRealtimeSocketState\('realtime_socket_not_online_before_encode'\);[\s\S]*return;[\s\S]*\}/.test(publisherPipeline),
  'publisher pipeline must stop before encode when the socket enters retrying or offline state',
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
const mirrorGossipIndex = publisherFrameDispatch.indexOf('if (!gossipFirst', sfuSendIndex)
assert(
  sfuSendIndex >= 0 && mirrorGossipIndex > sfuSendIndex,
  'publisher frame dispatch must keep SFU-first modes conservative before mirrored Gossip publish',
)
assert(
  /captureClientDiagnosticError\)\('gossip_data_lane_publish_failed'/.test(publisherFrameDispatch),
  'gossip publication failures must be diagnosed without breaking the SFU send path',
)
assert(
  !/console\.(debug|log|info|warn|error)\s*\(/.test(`${gossipDataLane}\n${publisherFrameDispatch}`),
  'gossip_primary publication failure diagnostics must not spam the browser console',
)
assert(
  !/eventType: 'gossip_server_fanout_socket_unavailable'[\s\S]{0,900}(data_base64|payload|protected_frame|protectedFrame)/.test(gossipDataLane),
  'gossip_server_fanout_socket_unavailable diagnostics must not include encoded frame payload material',
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
assert(result.alternatePathSuppressed === true && sfuAttempted === false, 'gossip_primary must return before SFU lookup/send even when a socket is open')
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
  diagnostics.some((event) => event?.eventType === 'gossip_primary_publish_failed' && event?.immediate === true),
  'failed gossip_primary publication must diagnose suppressed SFU fallback',
)

console.log('[gossip-outbound-live-publication-contract] PASS')
