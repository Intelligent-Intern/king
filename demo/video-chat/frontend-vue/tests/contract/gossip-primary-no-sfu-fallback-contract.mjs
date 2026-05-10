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

const dispatch = read('demo/video-chat/frontend-vue/src/domain/realtime/local/publisherFrameDispatch.ts')
const gossipLane = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const clientDiagnostics = read('demo/video-chat/frontend-vue/src/support/clientDiagnostics.ts')
const backendDiagnostics = read('demo/video-chat/backend-king-php/domain/realtime/client_diagnostics.php')
const packageJson = read('demo/video-chat/frontend-vue/package.json')

const gossipFirstPublishIndex = dispatch.indexOf('if (gossipFirst) {')
const earlyReturnIndex = dispatch.indexOf('sfuFallbackSuppressed: true', gossipFirstPublishIndex)
const sfuLookupIndex = dispatch.indexOf('const sendClient = safeFunction(currentOpenSfuClient, () => null)();')
const sfuSendIndex = dispatch.indexOf('sendClient.sendEncodedFrame(frame)')

assert.ok(
  gossipFirstPublishIndex >= 0
    && earlyReturnIndex > gossipFirstPublishIndex
    && sfuLookupIndex > earlyReturnIndex
    && sfuSendIndex > sfuLookupIndex,
  'gossip_primary dispatch must return before any SFU lookup or send',
)

assert.match(
  dispatch,
  /gossip_primary_publish_failed_no_sfu_fallback[\s\S]*planned Gossip media does not fall back to SFU[\s\S]*fallback_reason:\s*'gossip_primary_no_sfu_fallback'[\s\S]*immediate:\s*true/s,
  'Gossip-primary publish failure must diagnose the parked SFU fallback without sending through SFU',
)

assert.equal(
  /sfu_fallback_after_gossip_primary_publish_failure|sfu_fallback_unavailable_after_gossip_publish_failure/.test(dispatch),
  false,
  'Gossip-primary dispatch must not retain legacy SFU fallback diagnostics',
)

assert.match(
  gossipLane,
  /const backendVisibleBacktrace = VIDEOCHAT_MEDIA_CARRIER_CONFIG\.gossipPrimary \|\| GOSSIP_DATA_LANE_CONFIG\.mode === 'active'/,
  'blocked Gossip-primary publication must force backend-visible diagnostics',
)

assert.match(
  gossipLane,
  /level:\s*backendVisibleBacktrace \? 'warning' : 'info'[\s\S]*eventType:\s*'gossip_data_lane_shadow_would_publish'[\s\S]*assigned_neighbor_count:\s*assignedGossipNeighborIds\.size[\s\S]*has_rollout_gate_ack:\s*Boolean\(lastGossipRolloutGateState\)[\s\S]*immediate:\s*backendVisibleBacktrace/s,
  'blocked Gossip publication diagnostics must include topology/gate state and flush immediately in Gossip-primary mode',
)

assert.match(
  clientDiagnostics,
  /return value\.slice\(0, 48\)\.map/,
  'client diagnostics must preserve enough array entries for transport/gossip backtraces',
)

assert.match(
  clientDiagnostics,
  /if \(count >= 64\)/,
  'client diagnostics must preserve enough object keys for transport/gossip backtraces',
)

assert.match(
  clientDiagnostics,
  /utf8Length\(encoded\) <= 12000/,
  'client diagnostics must not truncate detailed transport/gossip payloads before the backend can store them',
)

assert.match(
  backendDiagnostics,
  /function videochat_client_diagnostics_encode_payload\(mixed \$payload, int \$maxBytes = 16384\)/,
  'backend diagnostics must accept the expanded client-side reconnect/gossip backtrace payload',
)

assert.ok(
  packageJson.includes('gossip-primary-no-sfu-fallback-contract.mjs')
    && !packageJson.includes('gossip-primary-fallback-backtrace-contract.mjs'),
  'gossip contract suite must include the no-SFU-fallback contract',
)

console.log('[gossip-primary-no-sfu-fallback-contract] PASS')
