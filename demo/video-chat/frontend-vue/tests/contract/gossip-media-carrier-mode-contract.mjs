import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const mediaCarrierPath = path.join(frontendRoot, 'src/lib/gossipmesh/mediaCarrierMode.ts')
const featureFlagsPath = path.join(frontendRoot, 'src/lib/gossipmesh/featureFlags.ts')
const gossipDataLanePath = path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const packagePath = path.join(frontendRoot, 'package.json')

const mediaCarrier = fs.readFileSync(mediaCarrierPath, 'utf8')
const featureFlags = fs.readFileSync(featureFlagsPath, 'utf8')
const gossipDataLane = fs.readFileSync(gossipDataLanePath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-media-carrier-mode-contract] ${message}`)
  }
}

assert(
  /export type VideochatMediaCarrierMode = 'gossip_primary' \| 'sfu_first' \| 'sfu_mirror'/.test(mediaCarrier),
  'runtime media carrier mode must expose gossip_primary, sfu_first, and sfu_mirror',
)
assert(
  /VITE_VIDEOCHAT_MEDIA_CARRIER/.test(mediaCarrier),
  'media carrier mode must be driven by VITE_VIDEOCHAT_MEDIA_CARRIER',
)
assert(
  /return 'sfu_first'/.test(mediaCarrier),
  'media carrier mode must default to conservative sfu_first',
)
assert(
  /normalized === 'gossip_primary'/.test(mediaCarrier)
    && /return 'gossip_primary'/.test(mediaCarrier)
    && /normalized === 'sfu_mirror'/.test(mediaCarrier)
    && /return 'sfu_mirror'/.test(mediaCarrier),
  'normalizer must recognize gossip_primary and sfu_mirror explicitly',
)
assert(
  /gossipMayPublishWithoutSfu:\s*gossipPrimary/.test(mediaCarrier)
    && /sfuRequiredBeforeGossip:\s*!gossipPrimary/.test(mediaCarrier)
    && /sfuSendIsOptional:\s*gossipPrimary \|\| sfuMirror/.test(mediaCarrier)
    && /sfuFallbackAllowed:\s*true/.test(mediaCarrier),
  'runtime config must encode gossip-primary SFU independence and fallback availability',
)
assert(
  /media_carrier_gossip_primary/.test(mediaCarrier)
    && /media_carrier_sfu_first/.test(mediaCarrier)
    && /media_carrier_sfu_mirror/.test(mediaCarrier),
  'runtime config must expose diagnostics labels for all carrier modes',
)
assert(
  /VIDEOCHAT_MEDIA_CARRIER_CONFIG/.test(featureFlags)
    && /VideochatMediaCarrierConfig/.test(featureFlags)
    && /VideochatMediaCarrierMode/.test(featureFlags),
  'feature flag surface must re-export media carrier config and types for runtime consumers',
)
assert(
  /VIDEOCHAT_MEDIA_CARRIER_CONFIG/.test(gossipDataLane)
    && /media_carrier_mode:\s*VIDEOCHAT_MEDIA_CARRIER_CONFIG\.mode/.test(gossipDataLane)
    && /gossip_may_publish_without_sfu:\s*VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipMayPublishWithoutSfu/.test(gossipDataLane)
    && /sfu_send_optional:\s*VIDEOCHAT_MEDIA_CARRIER_CONFIG\.sfuSendIsOptional/.test(gossipDataLane),
  'workspace gossip diagnostics must include carrier mode semantics before publisher pipeline rewiring',
)
assert(
  packageJson.includes('gossip-media-carrier-mode-contract.mjs'),
  'gossip contract suite must include the media carrier mode contract',
)

console.log('[gossip-media-carrier-mode-contract] PASS')
