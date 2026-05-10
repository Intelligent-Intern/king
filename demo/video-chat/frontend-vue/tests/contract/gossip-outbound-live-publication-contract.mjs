import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const callWorkspacePath = path.join(frontendRoot, 'src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLanePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const mediaStackPath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/mediaStack.ts')
const publisherPipelinePath = path.join(frontendRoot, 'src/domain/realtime/local/publisherPipeline.ts')
const publisherFrameDispatchPath = path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts')
const packagePath = path.join(frontendRoot, 'package.json')

const callWorkspace = fs.readFileSync(callWorkspacePath, 'utf8')
const gossipDataLane = fs.readFileSync(gossipDataLanePath, 'utf8')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
const mediaStack = fs.readFileSync(mediaStackPath, 'utf8')
const publisherPipeline = fs.readFileSync(publisherPipelinePath, 'utf8')
const publisherFrameDispatch = fs.readFileSync(publisherFrameDispatchPath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')
const relayPublishStart = gossipDataLane.indexOf('function publishLocalEncodedFrameToServerRelay(frame)')
const relayPublishEnd = gossipDataLane.indexOf('function serverRelayFrameDedupeKey', relayPublishStart)
const relayPublishBody = relayPublishStart >= 0 && relayPublishEnd > relayPublishStart
  ? gossipDataLane.slice(relayPublishStart, relayPublishEnd)
  : ''

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
  /function publishLocalEncodedFrameToGossip\(frame\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.publish\)[\s\S]*recordGossipShadowWouldPublish\(frame, 'publish_disabled'\);[\s\S]*if \(!gossipDataPlaneAllowed\(\)\)[\s\S]*recordGossipShadowWouldPublish\(frame, 'rollout_gate_blocked'\);[\s\S]*controller\.publishFrame\((String\(currentUserId\.value \|\| ''\)|peerId),\s*msg\);/.test(workspaceGossipSurface),
  'outbound live gossip publication must be gated by publish mode and gossip data-plane admission before publishFrame()',
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
  /function relaySocketUrlForCall\(\)[\s\S]*parsed\.searchParams\.set\('relay', 'media'\)/.test(callWorkspace)
    && /relaySocketUrl:\s*relaySocketUrlForCall/.test(callWorkspace),
  'call workspace must provide a dedicated relay=media websocket URL to the gossip data lane',
)
assert(
  /function ensureGossipServerRelaySocket\(\)[\s\S]*new WebSocket\(relayUrl\)[\s\S]*socket\.addEventListener\('message', handleGossipServerRelaySocketMessage\)/.test(gossipDataLane)
    && /const socket = ensureGossipServerRelaySocket\(\);[\s\S]*socket\.send\(JSON\.stringify\(\{[\s\S]*type:\s*'gossip\/server-frame'/.test(relayPublishBody)
    && !/sendSocketFrame\(/.test(relayPublishBody),
  'server relay primary must publish media over a dedicated relay websocket instead of the control websocket',
)
assert(
  /const serverRelayReceivedFrameIds = new Map\(\);/.test(gossipDataLane)
    && /function rememberServerRelayReceivedFrame\(frame,\s*body\)[\s\S]*serverRelayReceivedFrameIds\.has\(key\)/.test(gossipDataLane)
    && /if \(!rememberServerRelayReceivedFrame\(frame,\s*body\)\) return true;/.test(gossipDataLane),
  'server relay receive path must suppress duplicate frame delivery from multiple sockets',
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
