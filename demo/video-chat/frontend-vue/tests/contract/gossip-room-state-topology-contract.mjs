import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-room-state-topology-contract] ${message}`)
  }
}

const backendGossipRoomState = read('demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh_room_state.php')
const backendPresence = read('demo/video-chat/backend-king-php/domain/realtime/realtime_presence.php')
const backendSnapshot = read('demo/video-chat/backend-king-php/domain/realtime/realtime_room_snapshot.php')
const socketLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const roomStateTopology = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomStateTopology.ts')
const packageJson = read('demo/video-chat/frontend-vue/package.json')
const sprint = read('SPRINT.md')

assert(
  /function videochat_gossipmesh_room_state_payload\(/.test(backendGossipRoomState)
    && /'admitted_peers' => \$admittedPeers/.test(backendGossipRoomState)
    && /'capabilities' => \[/.test(backendGossipRoomState)
    && /'transport_candidates' => \[/.test(backendGossipRoomState)
    && /'assigned_neighbors' => \$hint\['neighbors'\]/.test(backendGossipRoomState),
  'backend must expose admitted peers, capabilities, transport candidates, and assigned neighbors as a room-state topology payload',
)
assert(
  /'gossip_topology' => \$gossipTopology/.test(backendSnapshot)
    && /videochat_gossipmesh_room_state_payload\(/.test(backendSnapshot)
    && /'gossip_topology' => \$payload\['gossip_topology'\] \?\? \[\]/.test(backendSnapshot),
  'room/snapshot payload and signature must include viewer-scoped gossip topology',
)
assert(
  /'gossip_topology_by_peer_id' => videochat_presence_room_gossip_topology_by_peer/.test(backendPresence)
    && /'participant_joined'/.test(backendPresence)
    && /'participant_left'/.test(backendPresence),
  'room/joined and room/left churn events must carry current per-peer topology hints',
)
assert(
  /export function applyGossipTopologyFromRoomStatePayload\(payload, localPeerId, applyGossipTopologyHint\)/.test(roomStateTopology)
    && /payload\?\.gossip_topology/.test(roomStateTopology)
    && /gossip_topology_by_peer_id/.test(roomStateTopology)
    && /String\(localPeerId \|\| ''\)\.trim\(\)/.test(roomStateTopology),
  'frontend socket lifecycle must extract topology from room-state payloads for the local peer',
)
assert(
  /if \(type === 'room\/snapshot'\)[\s\S]*applyRoomSnapshot\(payload\);[\s\S]*applyGossipTopologyFromRoomStatePayload\(payload, refs\.sessionState\?\.userId, applyGossipTopologyHint\);/.test(socketLifecycle),
  'frontend must apply room/snapshot topology without waiting for a separate diagnostic message',
)
assert(
  /if \(type === 'room\/left'\)[\s\S]*applyGossipTopologyFromRoomStatePayload\(payload, refs\.sessionState\?\.userId, applyGossipTopologyHint\);[\s\S]*requestRoomSnapshot\(\);/.test(socketLifecycle)
    && /if \(type === 'room\/joined'\)[\s\S]*applyGossipTopologyFromRoomStatePayload\(payload, refs\.sessionState\?\.userId, applyGossipTopologyHint\);[\s\S]*requestRoomSnapshot\(\);/.test(socketLifecycle),
  'frontend must consume topology from participant churn events before requesting snapshot backfill',
)
assert(
  packageJson.includes('gossip-room-state-topology-contract.mjs')
    && packageJson.includes('../backend-king-php/tests/realtime-gossipmesh-room-state-topology-contract.sh'),
  'gossip contract suite must include frontend and backend room-state topology contracts',
)
assert(
  /- \[x\] GSP-03 Join\/snapshot\/churn topology hints/.test(sprint),
  'SPRINT.md must mark GSP-03 complete when room-state topology proof exists',
)

console.log('[gossip-room-state-topology-contract] PASS')
