import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const helperPath = path.join(frontendRoot, 'src/domain/realtime/local/publisherFrameDispatch.ts')
const publisherPipelinePath = path.join(frontendRoot, 'src/domain/realtime/local/publisherPipeline.ts')
const browserEncoderPath = path.join(frontendRoot, 'src/domain/realtime/local/protectedBrowserVideoEncoder.ts')
const packagePath = path.join(frontendRoot, 'package.json')
const sprintPath = path.resolve(frontendRoot, '../../..', 'SPRINT.md')

const helper = fs.readFileSync(helperPath, 'utf8')
const publisherPipeline = fs.readFileSync(publisherPipelinePath, 'utf8')
const browserEncoder = fs.readFileSync(browserEncoderPath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')
const sprint = fs.readFileSync(sprintPath, 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-publisher-pipeline-decoupling-contract] ${message}`)
  }
}

const gossipFirstIndex = helper.indexOf('if (gossipFirst)')
const firstGossipPublishIndex = helper.indexOf('gossipPublished = publishGossipFrame', gossipFirstIndex)
const sfuSendIndex = helper.indexOf('sendClient.sendEncodedFrame(frame)')
const mirrorGossipPublishIndex = helper.indexOf('if (!gossipFirst)', sfuSendIndex)

assert(
  /VIDEOCHAT_MEDIA_CARRIER_CONFIG/.test(helper)
    && /gossipPrimary/.test(helper)
    && /sfuSendIsOptional/.test(helper),
  'publisher dispatch helper must use the runtime media carrier config',
)
assert(
  gossipFirstIndex >= 0 && firstGossipPublishIndex > gossipFirstIndex && firstGossipPublishIndex < sfuSendIndex,
  'gossip_primary must publish Gossip before attempting SFU send',
)
assert(
  mirrorGossipPublishIndex > sfuSendIndex,
  'non-gossip-primary modes must keep Gossip publication after the SFU send attempt',
)
assert(
  /if \(!sfuOptional\)[\s\S]*onRequiredSfuUnavailable/.test(helper)
    && /if \(!sfuOptional\)[\s\S]*onRequiredSfuFailure/.test(helper),
  'sfu_first must keep required SFU unavailable/failure handlers',
)
assert(
  /sfu_optional_send_unavailable_after_gossip_publish/.test(helper)
    && /sfu_optional_send_failed_after_gossip_publish/.test(helper),
  'gossip_primary must diagnose optional SFU unavailability and send failure without blocking Gossip',
)
assert(
  /return \{\s*ok:\s*gossipPublished[\s\S]*sfuSendOptional:\s*true/.test(helper),
  'optional SFU failure must return success based on Gossip publication',
)
assert(
  /publisherRequiresSfuBeforeEncode\(\) && !currentOpenSfuClient\(\)/.test(publisherPipeline)
    && /dispatchWlvcPublisherFrame\(\{[\s\S]*handleWlvcFrameSendFailure,[\s\S]*publishLocalEncodedFrameToGossip/.test(publisherPipeline),
  'WLVC publisher pipeline must stop requiring SFU before encode except in sfu_first and dispatch through the carrier helper with SFU failure handling wired',
)
assert(
  /publisherRequiresSfuBeforeEncode\(\) && !currentOpenSfuClient\(\)/.test(browserEncoder)
    && /dispatchProtectedBrowserPublisherFrame\(\{[\s\S]*publishLocalEncodedFrameToGossip/.test(browserEncoder),
  'protected browser publisher must use the same carrier decoupling as the WLVC pipeline',
)
assert(
  /sfu_optional_send_pressure_after_gossip_publish/.test(`${publisherPipeline}\n${helper}`),
  'optional SFU send pressure must be diagnostic after Gossip publication, not a Gossip blocker',
)
assert(
  packageJson.includes('gossip-publisher-pipeline-decoupling-contract.mjs'),
  'gossip contract suite must include publisher pipeline decoupling',
)
assert(
  /- \[x\] GSP-02 Publisher pipeline decoupling/.test(sprint),
  'SPRINT.md must mark GSP-02 complete when the decoupling proof exists',
)

console.log('[gossip-publisher-pipeline-decoupling-contract] PASS')
