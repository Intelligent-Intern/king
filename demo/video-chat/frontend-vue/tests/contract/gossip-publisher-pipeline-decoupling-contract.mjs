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
  'gossip_primary must publish Gossip before the non-primary SFU send branch',
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
  'non-primary mirror modes must still diagnose optional SFU unavailability and send failure without blocking Gossip',
)
assert(
  /return \{\s*ok:\s*gossipPublished[\s\S]*sfuSendOptional:\s*true/.test(helper),
  'optional SFU mirror failure must return success based on Gossip publication',
)
assert(
  /gossip_primary_publish_failed_no_sfu_fallback/.test(helper)
    && !/sfu_fallback_after_gossip_primary_publish_failure|sfu_fallback_unavailable_after_gossip_publish_failure/.test(helper),
  'gossip_primary must diagnose parked SFU fallback without retaining the fallback send path',
)
assert(
  !helper.includes('GOSSIP_SERVER_RELAY_CONFIG')
    && /const sent = await sendClient\.sendEncodedFrame\(frame\)/.test(helper),
  'browser gossip primary must not depend on a server relay while non-primary modes can still mirror to an open SFU client',
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
  'optional SFU mirror pressure must be diagnostic after Gossip publication, not a Gossip blocker',
)
assert(
  packageJson.includes('gossip-publisher-pipeline-decoupling-contract.mjs'),
  'gossip contract suite must include publisher pipeline decoupling',
)
assert(
  /- \[x\] GSP01-08 Park SFU from the active stream path/.test(sprint),
  'SPRINT.md must mark GSP01-08 complete when the Gossip-only publisher proof exists',
)

console.log('[gossip-publisher-pipeline-decoupling-contract] PASS')
