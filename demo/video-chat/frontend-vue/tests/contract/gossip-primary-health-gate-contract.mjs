import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')
const workspaceGossip = fs.readFileSync(
  path.join(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'),
  'utf8',
)
const backendGossip = fs.readFileSync(
  path.join(repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php'),
  'utf8',
)
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-primary-health-gate-contract] ${message}`)
  }
}

assert(
  !workspaceGossip.includes('deriveGossipRolloutGateState')
    && !workspaceGossip.includes('lastGossipRolloutGateState')
    && !workspaceGossip.includes('gossip_topology_healthy')
    && !workspaceGossip.includes('media_security_recovery_ready')
    && !workspaceGossip.includes('sfu_baseline_healthy'),
  'browser Gossip nodes must not evaluate local health or rollout gates',
)
assert(
  workspaceGossip.includes("eventType: 'gossip_server_head_ops_state'")
    && workspaceGossip.includes('server_head_authoritative: true')
    && workspaceGossip.includes('client_health_gate: false')
    && workspaceGossip.includes('client_topology_repair: false')
    && workspaceGossip.includes('client_recovery_request: false'),
  'browser Gossip nodes must treat server-head ops lane state as authoritative diagnostics only',
)
assert(
  /function gossipDataPlaneAllowed\(\)[\s\S]*GOSSIP_DATA_LANE_CONFIG\.mode === 'active'[\s\S]*GOSSIP_DATA_LANE_CONFIG\.publish[\s\S]*GOSSIP_DATA_LANE_CONFIG\.receive/.test(workspaceGossip),
  'browser Gossip data-plane admission must be a static mode check, not a local health gate',
)
assert(
  backendGossip.includes('videochat_gossipmesh_rollout_gate_state')
    || backendGossip.includes("'kind' => 'gossip_rollout_gate_state'"),
  'server-side Gossip telemetry aggregation must remain the place for health/ops-lane gate state',
)
assert(packageJson.includes('gossip-primary-health-gate-contract.mjs'), 'gossip suite must include the server-head health authority contract')

console.log('[gossip-primary-health-gate-contract] PASS')
