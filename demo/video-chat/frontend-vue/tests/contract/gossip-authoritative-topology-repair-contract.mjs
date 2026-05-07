import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const workspaceGossip = fs.readFileSync(
  path.join(root, 'src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts'),
  'utf8',
);
const roomState = fs.readFileSync(
  path.join(root, '../backend-king-php/domain/realtime/realtime_gossipmesh_room_state.php'),
  'utf8',
);
const websocketCommands = fs.readFileSync(
  path.join(root, '../backend-king-php/http/module_realtime_websocket_commands.php'),
  'utf8',
);
const runtimeContract = fs.readFileSync(
  path.join(root, '../backend-king-php/tests/realtime-gossipmesh-runtime-contract.php'),
  'utf8',
);
const packageJson = fs.readFileSync(path.join(root, 'package.json'), 'utf8');

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-authoritative-topology-repair-contract] ${message}`);
  }
}

assert(
  roomState.includes('function videochat_gossipmesh_room_state_repair_for_peer')
    && roomState.includes("'retired_peer_ids'")
    && roomState.includes("'replacement_peer_ids'"),
  'room-state topology repair must expose peer-scoped retired and replacement assignments',
);

assert(
  websocketCommands.includes("videochat_gossipmesh_room_state_payloads_by_peer")
    && websocketCommands.includes("'topology_feature' => 'topology_repair'")
    && websocketCommands.includes("videochat_presence_room_key($roomId, $tenantId)")
    && websocketCommands.includes("videochat_presence_send_frame($targetConnection['socket'] ?? null"),
  'websocket repair command must send authoritative peer-scoped topology hints to room connections',
);

assert(
  !websocketCommands.includes('videochat_gossipmesh_call_topology_payload(\n            $topologyPlan,\n            $peerId'),
  'repair must not remain a single requester-only call/gossip-topology diagnostic response',
);

assert(
  workspaceGossip.includes('function topologyRepairRetiredPeerIdsForLocalPeer')
    && workspaceGossip.includes('repair.authoritative !== true')
    && workspaceGossip.includes("gossipNeighborLifecycle?.closePeer?.(retiredPeerId, 'repair_retired_edge')")
    && workspaceGossip.includes('repair_retired_peer_count'),
  'client must consume authoritative repair metadata and retire old dedicated neighbor edges',
);

assert(
  runtimeContract.includes('peer-scoped room reassignment frames')
    && runtimeContract.includes('requesting peer must retire the failed neighbor edge')
    && runtimeContract.includes('lost neighbor peer must retire the reverse failed edge')
    && runtimeContract.includes('must not distribute media frames'),
  'backend runtime contract must prove reassignment, cleanup, and no media fanout',
);

assert(
  packageJson.includes('gossip-authoritative-topology-repair-contract.mjs')
    && packageJson.includes('../backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh'),
  'gossip contract suite must include authoritative repair and backend runtime contracts',
);

console.log('[gossip-authoritative-topology-repair-contract] PASS');
