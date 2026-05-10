import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

const mediaCarrier = read('demo/video-chat/frontend-vue/src/lib/gossipmesh/mediaCarrierMode.ts')
const dispatch = read('demo/video-chat/frontend-vue/src/domain/realtime/local/publisherFrameDispatch.ts')
const mediaPlan = read('demo/video-chat/frontend-vue/src/domain/realtime/media/mediaSessionPlan.ts')
const mediaPlanBridge = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts')
const plannedParking = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/plannedGossipSfuRecovery.ts')
const runtimeHealth = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.ts')
const publisherBackpressure = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts')
const compose = read('demo/video-chat/docker-compose.v1.yml')
const envDefaults = read('demo/video-chat/.env')
const deploy = read('demo/video-chat/scripts/deploy.sh')
const packageJson = read('demo/video-chat/frontend-vue/package.json')

assert.match(
  mediaCarrier,
  /sfuSendIsOptional:\s*sfuMirror[\s\S]*sfuFallbackAllowed:\s*!gossipPrimary/,
  'gossip_primary carrier config must disable optional SFU sends and SFU fallback',
)

const gossipFirstPublishIndex = dispatch.indexOf('if (gossipFirst) {')
const earlyReturnIndex = dispatch.indexOf('sfuFallbackSuppressed: true', gossipFirstPublishIndex)
const sfuLookupIndex = dispatch.indexOf('const sendClient = safeFunction(currentOpenSfuClient, () => null)();')
assert.ok(
  gossipFirstPublishIndex >= 0
    && earlyReturnIndex > gossipFirstPublishIndex
    && sfuLookupIndex > earlyReturnIndex,
  'gossip_primary dispatch must return before SFU client lookup',
)
assert.match(
  dispatch,
  /gossip_primary_publish_failed_no_sfu_fallback/,
  'gossip_primary publish failure must expose no-SFU-fallback diagnostics',
)
assert.equal(
  /sfu_fallback_after_gossip_primary_publish_failure|sfu_fallback_unavailable_after_gossip_publish_failure/.test(dispatch),
  false,
  'gossip_primary dispatch must not retain legacy SFU fallback symbols',
)

assert.match(mediaPlan, /export function isMediaSessionPlanGossipTransport/, 'media plan must expose a Gossip transport predicate')
assert.match(mediaPlan, /export function mediaSessionPlanHasGossipTransport/, 'media plan must expose a planned Gossip transport predicate')
assert.match(
  mediaPlanBridge,
  /export function activeMediaSessionPlanHasGossipTransport[\s\S]*mediaSessionPlanHasGossipTransportForLastPlan/,
  'workspace plan bridge must expose active planned Gossip transport',
)

assert.match(
  plannedParking,
  /VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary[\s\S]*GOSSIP_DATA_LANE_CONFIG\.mode === 'active'[\s\S]*activeMediaSessionPlanHasGossipTransport/,
  'planned Gossip recovery parking must activate from gossip_primary config or active Gossip plan transport',
)
assert.match(
  plannedParking,
  /sfu_recovery_parked: true[\s\S]*eventType = 'planned_gossip_sfu_recovery_parked'/,
  'planned Gossip recovery parking must emit an explicit diagnostic payload',
)

assert.match(
  runtimeHealth,
  /diagnosePlannedGossipSfuRecoveryParked[\s\S]*eventType: 'planned_gossip_sfu_socket_restart_parked'/,
  'remote runtime health must park SFU socket restart as media recovery',
)
assert.match(
  runtimeHealth,
  /function checkRemoteVideoStalls\(\) \{[\s\S]*if \(plannedGossipTransportActive\(\{ media_runtime_path: mediaRuntimePath\.value \}\)\) return;/,
  'remote stall checks must no-op while planned Gossip transport is active',
)
assert.match(
  publisherBackpressure,
  /function restartSfuAfterVideoStall\(reason, payload = \{\}, options = \{\}\) \{[\s\S]*diagnosePlannedGossipSfuRecoveryParked/,
  'publisher backpressure must park SFU socket restart before reconnecting',
)

assert.ok(
  envDefaults.includes('VITE_VIDEOCHAT_GOSSIP_DATA_LANE=active')
    && envDefaults.includes('VITE_VIDEOCHAT_MEDIA_CARRIER=gossip_primary'),
  'local stack env must explicitly select active Gossip primary',
)
assert.match(
  compose,
  /VITE_VIDEOCHAT_GOSSIP_DATA_LANE:\s*"\$\{VITE_VIDEOCHAT_GOSSIP_DATA_LANE:-active\}"[\s\S]*VITE_VIDEOCHAT_MEDIA_CARRIER:\s*"\$\{VITE_VIDEOCHAT_MEDIA_CARRIER:-gossip_primary\}"/,
  'compose must preserve explicit active Gossip defaults for frontend builds',
)
assert.ok(
  deploy.includes('set_env_value VITE_VIDEOCHAT_GOSSIP_DATA_LANE active')
    && deploy.includes('set_env_value VITE_VIDEOCHAT_MEDIA_CARRIER gossip_primary'),
  'deploy refresh must persist active Gossip primary config',
)
assert.ok(
  packageJson.includes('gsp01-08-sfu-parking-contract.mjs'),
  'gossip contract suite must include the GSP01-08 parking contract',
)

console.log('[gsp01-08-sfu-parking-contract] PASS')
