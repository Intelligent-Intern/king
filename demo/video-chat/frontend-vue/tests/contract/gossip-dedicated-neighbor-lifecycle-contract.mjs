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
    throw new Error(`[gossip-dedicated-neighbor-lifecycle-contract] ${message}`)
  }
}

const lifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipNeighborLifecycle.ts')
const dataLane = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts')
const socketLifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts')
const callWorkspace = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue')
const packageJson = read('demo/video-chat/frontend-vue/package.json')
const sprint = read('SPRINT.md')

assert(
  /createGossipNeighborLifecycle/.test(lifecycle)
    && /new RTCPeerConnection\(peerConnectionConfig\(\)\)/.test(lifecycle)
    && /transport\?\.bindPeerConnection\?\.\(normalizedPeerId,\s*pc,\s*peer\.initiator\)/.test(lifecycle),
  'dedicated Gossip neighbor lifecycle must own separate RTCPeerConnection instances and bind them to the data transport',
)
assert(
  /GOSSIP_NEIGHBOR_RUNTIME_PATH = 'gossip_primary_neighbor'/.test(lifecycle)
    && /gossip_neighbor_offer/.test(lifecycle)
    && /gossip_neighbor_answer/.test(lifecycle)
    && /gossip_neighbor_ice/.test(lifecycle)
    && /transport:\s*'rtc_datachannel'/.test(lifecycle),
  'dedicated Gossip neighbor signaling must use explicit gossip_neighbor offer/answer/ice payloads over rtc_datachannel',
)
assert(
  /function applyAssignedNeighbors\(topologyHint,\s*assignedPeerIds\)/.test(lifecycle)
    && /ensurePeer\(peerId,\s*true,\s*'server_assigned_neighbor'\)/.test(lifecycle)
    && /if \(!assigned\.has\(peerId\)\) closePeer\(peerId,\s*'retired_by_topology'\)/.test(lifecycle),
  'assigned neighbors must create dedicated links and retired assignments must close their edge',
)
assert(
  /function handleGossipNeighborSignal\(type,\s*senderPeerId,\s*payload\)/.test(lifecycle)
    && /if \(!isGossipNeighborPayload\(payload\)\) return false/.test(lifecycle)
    && /void handleOffer\(senderPeerId,\s*payload\)/.test(lifecycle)
    && /void handleAnswer\(senderPeerId,\s*payload\)/.test(lifecycle)
    && /void handleIce\(senderPeerId,\s*payload\)/.test(lifecycle),
  'gossip_neighbor signaling must be consumed by the dedicated lifecycle, not by native media signaling',
)
assert(
  /function scheduleQueuedRenegotiate\(peer,\s*reason = 'queued_renegotiate'\)/.test(lifecycle)
    && /queuedRenegotiateTimer/.test(lifecycle)
    && /GOSSIP_NEIGHBOR_RENEGOTIATE_MAX_ATTEMPTS/.test(lifecycle)
    && /gossip_neighbor_renegotiate_quarantined/.test(lifecycle)
    && /scheduleQueuedRenegotiate\(peer,\s*'queued_renegotiate'\)/.test(lifecycle)
    && !/void negotiatePeer\(peer,\s*'queued_renegotiate'\)/.test(lifecycle),
  'queued Gossip neighbor renegotiation must be deduped and bounded instead of recursively calling negotiatePeer from finally',
)
assert(
  /const preSetLocalState = String\(peer\.pc\.signalingState \|\| ''\)\.trim\(\)\.toLowerCase\(\)/.test(lifecycle)
    && /preSetLocalState !== 'stable'/.test(lifecycle)
    && /gossip_neighbor_offer_deferred/.test(lifecycle)
    && /await peer\.pc\.setLocalDescription\(offer\)/.test(lifecycle),
  'Gossip neighbor offer creation must re-check signaling state before setLocalDescription to avoid have-remote-offer glare',
)
assert(
  /function closePeer\(peerId,\s*reason = 'retired'\)[\s\S]*clearQueuedRenegotiate\(peer\)[\s\S]*peer\.pc\?\.close\?\.\(\)/.test(lifecycle),
  'closing a Gossip neighbor must clear pending queued renegotiation timers before closing the peer connection',
)
assert(
  /import \{ createGossipNeighborLifecycle \} from '\.\/gossipNeighborLifecycle'/.test(dataLane)
    && /const assignedGossipNeighborIds = new Set\(\)/.test(dataLane)
    && /ensureGossipNeighborLifecycle\(\)\?\.applyAssignedNeighbors\(topologyHint,\s*assignedGossipNeighborIds\)/.test(dataLane)
    && !/nativePeerConnectionsRef/.test(dataLane)
    && !/bindGossipDataChannelForNativePeer/.test(dataLane),
  'data lane must synchronize server-assigned neighbors through the dedicated lifecycle instead of arbitrary native peer connections',
)
assert(
  /handleGossipNeighborSignal = \(\) => false/.test(socketLifecycle)
    && /if \(handleGossipNeighborSignal\(type,\s*senderUserId,\s*payloadBody \|\| \{\}\)\) return;[\s\S]*const payloadKind/.test(socketLifecycle),
  'socket lifecycle must route gossip_neighbor signaling before native WebRTC SDP handling',
)
assert(
  /handleGossipNeighborSignal/.test(callWorkspace)
    && !/bindGossipDataChannelForNativePeer/.test(callWorkspace)
    && !/closeGossipDataChannelForNativePeer/.test(callWorkspace),
  'workspace wiring must expose the dedicated gossip neighbor handler and stop passing native peer binding callbacks',
)
assert(
  packageJson.includes('gossip-dedicated-neighbor-lifecycle-contract.mjs'),
  'gossip contract suite must include the dedicated neighbor lifecycle contract',
)
assert(
  packageJson.includes('gossip-neighbor-renegotiate-stack-contract.mjs'),
  'gossip contract suite must include the production stack-overflow renegotiation proof',
)
assert(
  /- \[x\] GSP-04 Dedicated bounded neighbor lifecycle/.test(sprint),
  'SPRINT.md must mark GSP-04 complete when dedicated neighbor lifecycle proof exists',
)

console.log('[gossip-dedicated-neighbor-lifecycle-contract] PASS')
