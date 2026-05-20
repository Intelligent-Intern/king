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

function readFrontend(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8')
}

const dataLane = readFrontend('src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const websocketConnect = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_connect.php')
const contract = JSON.parse(read('demo/video-chat/contracts/v1/gossip-media-frame.contract.json'))
const packageJson = readFrontend('package.json')

assert.doesNotMatch(dataLane, /createGossipConnectQuorum|evaluateBeforePublish|gossip_connect_quorum/)
assert.doesNotMatch(dataLane, /gossip\/topology-repair\/request|gossip_topology_repair_requested/)
assert.doesNotMatch(dataLane, /createGossipRecoveryOps|gossip\/recovery\/request|gossip_native_recovery_requested/)
assert.doesNotMatch(dataLane, /deriveGossipRolloutGateState|lastGossipRolloutGateState/)
assert.match(
  dataLane,
  /function gossipDataPlaneAllowed\(\)\s*\{[\s\S]*GOSSIP_DATA_LANE_CONFIG\.mode === 'active'[\s\S]*GOSSIP_DATA_LANE_CONFIG\.publish[\s\S]*GOSSIP_DATA_LANE_CONFIG\.receive[\s\S]*!strictGossipMediaDisabled\(\)[\s\S]*\}/,
  'gossip data-plane publication gate must be static client capability only; dynamic health belongs to the server head ops lane',
)
assert.match(dataLane, /function applyGossipTelemetryAck\(payload\)[\s\S]*gossip_server_head_ops_state[\s\S]*client_health_gate:\s*false/)
assert.match(dataLane, /handleGossipNeighborSignal:\s*\(\.\.\.args\)\s*=>\s*ensureGossipNeighborLifecycle\(\)\?\.handleGossipNeighborSignal/)
assert.match(
  websocketConnect,
  /auto_reconnect' => false[\s\S]*restart_policy' => 'new_participant_only'[\s\S]*connect_quorum/,
  'server websocket connect-quorum payload must stay on the ops lane with auto reconnect disabled',
)

assert.equal(contract.publication_gate?.ops_lane_authority?.server_head_authoritative, true)
assert.equal(contract.publication_gate?.ops_lane_authority?.client_health_checks, false)
assert.equal(contract.publication_gate?.ops_lane_authority?.client_topology_repair_requests, false)
assert.equal(contract.publication_gate?.ops_lane_authority?.client_recovery_requests, false)
assert.deepEqual(contract.publication_gate?.ops_lane_authority?.allowed_egress, ['open_websocket', 'open_rtc_datachannel'])
assert.deepEqual(
  contract.publication_gate?.ops_lane_authority?.forbidden_client_behaviors,
  ['health_gate', 'topology_repair_request', 'missing_frame_recovery_request', 'sfu_fallback', 'media_security_fallback', 'reconnect_loop'],
)

assert.ok(
  packageJson.includes('gossip-connect-quorum-contract.mjs'),
  'gossip contract suite must include the server-head ops authority contract',
)

console.log('[gossip-connect-quorum-contract] PASS')
