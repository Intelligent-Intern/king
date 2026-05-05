import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const controllerPath = path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts')
const wirePath = path.join(frontendRoot, 'src/lib/gossipmesh/wireContract.ts')
const controller = fs.readFileSync(controllerPath, 'utf8')
const wire = fs.readFileSync(wirePath, 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-topology-hint-contract] ${message}`)
  }
}

assert(
  /export interface TopologyHintMessage extends LaneTaggedMessage/.test(wire),
  'wire contract must define a topology hint message shape',
)
assert(
  /type:\s*'topology_hint'/.test(wire)
    && /topology_epoch:\s*number/.test(wire)
    && /neighbors:\s*TopologyHintNeighbor\[\]/.test(wire),
  'topology hint must include type, epoch, and neighbor list',
)
assert(
  /transport\?:\s*'rtc_datachannel'\s*\|\s*'in_memory'/.test(wire),
  'topology hint neighbors must identify the intended data transport',
)
assert(
  /applyTopologyHint\(peerId:\s*string,\s*msg:\s*TopologyHintMessage\):\s*boolean/.test(controller),
  'controller must expose applyTopologyHint()',
)
assert(
  /msg\.room_id !== this\.roomId \|\| msg\.call_id !== this\.callId/.test(controller),
  'topology hints must be scoped to the current room and call',
)
assert(
  /msg\.peer_id !== peerId/.test(controller),
  'topology hints must be bound to the target peer identity',
)
assert(
  /Number\(msg\.topology_epoch\) < peer\.topology_epoch/.test(controller),
  'older topology epochs must be rejected',
)
assert(
  /\.filter\(\(neighborId, index, all\) =>[\s\S]*neighborId === peerId[\s\S]*!this\.peers\.has\(neighborId\)[\s\S]*all\.indexOf\(neighborId\) === index/.test(controller),
  'topology hints must reject self, unknown peers, and duplicate neighbors',
)
assert(
  /\.slice\(0,\s*this\.fanout\)/.test(controller),
  'server-provided neighbors must still be bounded by local fanout',
)
assert(
  /if \(msg\.type === 'topology_hint'\)[\s\S]*this\.applyTopologyHint\(peerId,\s*msg as TopologyHintMessage\)/.test(controller),
  'ops lane topology_hint messages must apply server topology',
)

console.log('[gossip-topology-hint-contract] PASS')
