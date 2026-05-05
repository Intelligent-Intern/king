import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const callWorkspacePath = path.join(frontendRoot, 'src/domain/realtime/CallWorkspaceView.vue')
const gossipDataLanePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const mediaStackPath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/mediaStack.ts')
const publisherPipelinePath = path.join(frontendRoot, 'src/domain/realtime/local/publisherPipeline.ts')
const packagePath = path.join(frontendRoot, 'package.json')

const callWorkspace = fs.readFileSync(callWorkspacePath, 'utf8')
const gossipDataLane = fs.readFileSync(gossipDataLanePath, 'utf8')
const workspaceGossipSurface = `${callWorkspace}\n${gossipDataLane}`
const mediaStack = fs.readFileSync(mediaStackPath, 'utf8')
const publisherPipeline = fs.readFileSync(publisherPipelinePath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')

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
  /function publishLocalEncodedFrameToGossip\(frame\)[\s\S]*if \(!GOSSIP_DATA_LANE_CONFIG\.publish\) return false;[\s\S]*controller\.publishFrame\((String\(currentUserId\.value \|\| ''\)|peerId),\s*msg\);/.test(workspaceGossipSurface),
  'outbound live gossip publication must be gated by GOSSIP_DATA_LANE_CONFIG.publish before publishFrame()',
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
  /publishLocalEncodedFrameToGossip,/.test(workspaceGossipSurface)
    && /publishLocalEncodedFrameToGossip:\s*callbacks\.publishLocalEncodedFrameToGossip/.test(mediaStack),
  'call workspace must expose the live gossip publisher callback through mediaStack',
)
assert(
  /publishLocalEncodedFrameToGossip = \(\) => false/.test(publisherPipeline),
  'publisher pipeline must default the gossip hook to a no-op for non-gossip callers',
)

const sfuSendIndex = publisherPipeline.indexOf('const frameSent = await sendClient.sendEncodedFrame(outgoingFrame);')
const sendFailureIndex = publisherPipeline.indexOf('if (frameSent === false)', sfuSendIndex)
const gossipPublishIndex = publisherPipeline.indexOf('publishLocalEncodedFrameToGossip(outgoingFrame);', sendFailureIndex)
assert(
  sfuSendIndex >= 0 && sendFailureIndex > sfuSendIndex && gossipPublishIndex > sendFailureIndex,
  'publisher pipeline must keep sendClient.sendEncodedFrame(outgoingFrame) as the conservative path before gossip publish',
)
assert(
  /captureClientDiagnosticError\('gossip_data_lane_publish_failed'/.test(publisherPipeline),
  'gossip publication failures must be diagnosed without breaking the SFU send path',
)
assert(
  packageJson.includes('gossip-outbound-live-publication-contract.mjs'),
  'gossip contract suite must include the outbound live publication contract',
)

console.log('[gossip-outbound-live-publication-contract] PASS')
