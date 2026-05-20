import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const callWorkspace = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/CallWorkspaceView.vue'), 'utf8')
const gossipDataLane = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'), 'utf8')
const gossipMediaFrameEnvelope = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipMediaFrameEnvelope.ts'), 'utf8')
const publisherFrameDispatch = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts'), 'utf8')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}\n${gossipMediaFrameEnvelope}`
const controller = fs.readFileSync(path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts'), 'utf8')
const lifecycle = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/lifecycle.ts'), 'utf8')
const frameDecode = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/sfu/frameDecode.ts'), 'utf8')
const browserRenderer = fs.readFileSync(path.join(frontendRoot, 'src/domain/realtime/sfu/remoteBrowserEncodedVideo.ts'), 'utf8')
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8')
const gossipPixelProofSpec = fs.readFileSync(path.join(frontendRoot, 'tests/e2e/gossip-frame-pixel-proof.spec.js'), 'utf8')
const gossipPixelProofHarness = fs.readFileSync(path.join(frontendRoot, 'tests/e2e/helpers/gossipFramePixelProofHarness.js'), 'utf8')

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
  /if \(!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG\.receive\)[\s\S]*gossip_data_lane_shadow_message_dropped[\s\S]*return;/.test(workspaceGossipSurface),
  'shadow mode must still drop incoming RTCDataChannel data before GossipController handling',
)
assert(
  /controller\.handleData\((String\(currentUserId\.value \|\| ''\)|localPeerId\(\)),\s*msg,\s*String\(fromPeerId \|\| ''\)\)/.test(workspaceGossipSurface),
  'active inbound RTCDataChannel messages must enter GossipController.handleData() as local receives',
)
assert(
  /const GOSSIP_MEDIA_FRAME_TYPE = 'gossip\.media\.frame\.v1';/.test(gossipMediaFrameEnvelope)
    && /function routeLiveGossipDeliveryToRemoteFrame\(delivery\)[\s\S]*if \(!directGossipPrimary && !GOSSIP_DATA_LANE_CONFIG\.receive\) return false;[\s\S]*isGossipMediaFrameMessage\(msg\)[\s\S]*routeGossipMediaFrameToRenderer\(frame,\s*directGossipPrimary\)/.test(workspaceGossipSurface),
  'accepted gossip.media.frame.v1 gossip deliveries must route to the existing remote decode entry point only in active receive mode',
)
assert(
  /function isGossipMediaFrameMessage\(msg\)[\s\S]*type === GOSSIP_MEDIA_FRAME_TYPE \|\| type === 'sfu\/frame'/.test(gossipMediaFrameEnvelope)
    && /function sfuFrameFromGossipMessage\(msg,\s*delivery\)[\s\S]*const dataBinary = normalizeGossipFrameArrayBuffer\(msg\.dataBinary \|\| msg\.data_binary \|\| msg\.data\);[\s\S]*data:\s*dataBinary[\s\S]*transportPath:\s*runtimePath/.test(workspaceGossipSurface)
    && gossipMediaFrameEnvelope.includes('msg.frame_kind')
    && gossipMediaFrameEnvelope.includes('msg.sequence')
    && gossipMediaFrameEnvelope.includes('msg.timestamp_unix_ms')
    && gossipMediaFrameEnvelope.includes('msg.runtime_path'),
  'gossip.media.frame.v1 messages must be adapted from binary payloads into SFU frame objects with explicit gossip transport provenance',
)
assert(
  /function routeGossipMediaFrameToRenderer\(frame,\s*directGossipPrimary\)[\s\S]*handleSFUEncodedFrame\(directGossipPrimary[\s\S]*transportPath:\s*'gossip_primary_direct'[\s\S]*protected:\s*null[\s\S]*protectedFrame:\s*null[\s\S]*protectionMode:\s*'transport_only'/.test(gossipDataLane),
  'active gossip_primary receive must hand transport-only frames to the renderer without SFU fallback state',
)
assert(
  /renderer_path:\s*'remote_decoded_canvas'/.test(gossipDataLane)
    && /renderer_entry:\s*'handleSFUEncodedFrame'/.test(gossipDataLane)
    && /decoded_pixels_required:\s*true/.test(gossipDataLane)
    && /frame_count_min:\s*1/.test(gossipDataLane),
  'gossip receive diagnostics must require decoded pixels and frameCount proof for the active renderer path',
)
assert(
  /function gossipFrameMessageFromEncodedFrame\(frame,\s*sequenceMap,[\s\S]*type:\s*GOSSIP_MEDIA_FRAME_TYPE[\s\S]*envelope_contract:\s*GOSSIP_MEDIA_FRAME_TYPE/.test(gossipMediaFrameEnvelope),
  'active outbound Gossip envelopes must publish gossip.media.frame.v1 instead of external sfu/frame messages',
)
assert(
  /gossip_data_lane_frame_routed/.test(workspaceGossipSurface),
  'active live routing must emit a diagnostic when a gossip frame enters the remote frame path',
)
const gossipFirstPublishIndex = publisherFrameDispatch.indexOf('if (gossipFirst) {')
const gossipPrimaryEarlyReturnIndex = publisherFrameDispatch.indexOf('alternatePathSuppressed: true', gossipFirstPublishIndex)
const sfuClientLookupIndex = publisherFrameDispatch.indexOf('const sendClient = safeFunction(currentOpenSfuClient, () => null)();')
const sfuSendIndex = publisherFrameDispatch.indexOf('sendClient.sendEncodedFrame(frame)')
assert(
  gossipFirstPublishIndex >= 0
    && gossipPrimaryEarlyReturnIndex > gossipFirstPublishIndex
    && sfuClientLookupIndex > gossipPrimaryEarlyReturnIndex
    && sfuSendIndex > sfuClientLookupIndex,
  'gossip_primary dispatch must return after Gossip publication and must not fall back or mirror into the SFU send path',
)
assert(
  /gossip_primary_publish_failed/.test(publisherFrameDispatch)
    && !/sfu_fallback_after_gossip_primary_publish_failure/.test(publisherFrameDispatch)
    && !/sfu_fallback_unavailable_after_gossip_publish_failure/.test(publisherFrameDispatch),
  'gossip_primary publish failure must diagnose suppressed fallback instead of sending an SFU frame',
)
assert(
  /dispose\(\):\s*void/.test(controller)
    && /this\.dataListeners = \[\]/.test(controller),
  'GossipController must expose dispose() to clear live listeners',
)
assert(
  /checkCarrierState\(peerId: string\): void \{[\s\S]*void peerId[\s\S]*\}/.test(controller)
    && /reason: eventType === 'open' \? 'rtc_datachannel_open' : 'rtc_datachannel_health_ignored'/.test(controller)
    && !/heartbeatTimers|startHeartbeat|heartbeat_timeout|reconnect_requested|rtc_datachannel_lost/.test(controller),
  'GossipController must not run client-side heartbeat health or reconnect decisions',
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
const wlvcImageDataIndex = frameDecode.indexOf('const imageData = new ImageData(decoded.data, decoded.width, decoded.height);')
const wlvcCanvasWriteIndex = frameDecode.indexOf('ctx.putImageData(imageData, 0, 0);', wlvcImageDataIndex)
const wlvcFrameCountIndex = frameDecode.indexOf('peer.frameCount = Number(peer.frameCount || 0) + 1;', wlvcCanvasWriteIndex)
assert(
  frameDecode.includes('const decodedHasPixels = decoded && decoded.data && Number(decoded.data.length || 0) > 0;')
    && wlvcImageDataIndex >= 0
    && wlvcCanvasWriteIndex > wlvcImageDataIndex
    && wlvcFrameCountIndex > wlvcCanvasWriteIndex,
  'WLVC receiver proof must require decoded pixels, write them to canvas, and then increment frameCount',
)
const browserCanvasWriteIndex = browserRenderer.indexOf('ctx.drawImage(videoFrame, 0, 0, width, height);')
const browserFrameCountIndex = browserRenderer.indexOf('peer.frameCount = Number(peer.frameCount || 0) + 1;', browserCanvasWriteIndex)
assert(
  browserCanvasWriteIndex >= 0
    && browserFrameCountIndex > browserCanvasWriteIndex
    && browserRenderer.includes('noteSfuRemoteVideoFrameStable(peer, frame'),
  'WebCodecs receiver branch must also draw decoded pixels before frameCount/stability updates',
)
assert(
  gossipPixelProofSpec.includes("test('gossip.media.frame.v1 writes decoded pixels into a remote participant tile'")
    && /runGossipFramePixelProof\(page\)/.test(gossipPixelProofSpec),
  'GSP01-11 must include an executable browser proof for a rendered gossip.media.frame.v1 remote tile',
)
assert(
  /gossipFrameMessageFromEncodedFrame\(avatarEncodedFrame,\s*sequenceMap/.test(gossipPixelProofHarness)
    && /publisherController\.publishFrame\(String\(remoteUserId\),\s*publishedGossipMessage\)/.test(gossipPixelProofHarness)
    && /sfuFrameFromGossipMessage\(delivery\?\.message,\s*delivery\)/.test(gossipPixelProofHarness)
    && /frameDecodeHelpers\.handleSFUEncodedFrame\(adaptedFrame\)/.test(gossipPixelProofHarness),
  'browser proof must publish an avatar frame as gossip.media.frame.v1, receive it, adapt it, and enter the existing renderer path',
)
assert(
  /avatarCanvas\.captureStream/.test(gossipPixelProofHarness)
    && /mediaSessionPlanAllowsLocalPublication\(blockedPlan/.test(gossipPixelProofHarness)
    && /mediaSessionPlanAllowsLocalPublication\(activePlan/.test(gossipPixelProofHarness)
    && /blockedPlanAllowsPublish/.test(gossipPixelProofSpec)
    && /activePlanAllowsPublish/.test(gossipPixelProofSpec),
  'browser proof must use a synthetic avatar/canvas source and prove the media_session_plan publish gate',
)
assert(
  /canvas\.parentElement === tile/.test(gossipPixelProofHarness)
    && /Number\(peer\.frameCount \|\| 0\) > 0/.test(gossipPixelProofHarness)
    && /getImageData\(0, 0, 1, 1\)/.test(gossipPixelProofHarness)
    && /pixel\.every\(\(value, index\) => value === expectedPixel\[index\]\)/.test(gossipPixelProofHarness),
  'browser proof must require remote tile attachment, decoded pixel readback, and frameCount > 0',
)
assert(
  /expect\(proof\.frameCount\)\.toBeGreaterThan\(0\)/.test(gossipPixelProofSpec)
    && /expect\(proof\.receivedFrameCount\)\.toBeGreaterThan\(0\)/.test(gossipPixelProofSpec)
    && /expect\(proof\.pixel\)\.toEqual\(proof\.expectedPixel\)/.test(gossipPixelProofSpec)
    && /expect\(proof\.publishedTransportMessageCount\)\.toBeGreaterThan\(0\)/.test(gossipPixelProofSpec)
    && /expect\(proof\.gossipControllerDeliveryCount\)\.toBeGreaterThan\(0\)/.test(gossipPixelProofSpec)
    && /expect\(proof\.tileCanvasParentId\)\.toBe/.test(gossipPixelProofSpec),
  'browser proof assertions must fail if the test only publishes, creates a peer/canvas, or omits decoded pixels',
)
assert(
  packageJson.includes('gossip-live-receive-decode-route-contract.mjs'),
  'gossip contract suite must include the live receive/decode route contract',
)

console.log('[gossip-live-receive-decode-route-contract] PASS')
