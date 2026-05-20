import assert from 'node:assert/strict'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { loadViteSsrModule } from './viteSsrLoader.mjs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

const previousRtcPeerConnection = globalThis.RTCPeerConnection
const previousRtcSessionDescription = globalThis.RTCSessionDescription
const previousRtcIceCandidate = globalThis.RTCIceCandidate

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

class TestSessionDescription {
  constructor(description) {
    Object.assign(this, description || {})
  }
}

class TestIceCandidate {
  constructor(candidate) {
    Object.assign(this, candidate || {})
  }
}

class DeterministicOffererPeerConnection {
  static instances = []

  constructor() {
    this.listeners = new Map()
    this.signalingState = 'stable'
    this.connectionState = 'new'
    this.localDescription = null
    this.remoteDescription = null
    this.createOfferCalls = 0
    this.createAnswerCalls = 0
    this.rollbackCalls = 0
    this.closed = false
    DeterministicOffererPeerConnection.instances.push(this)
  }

  addEventListener(type, listener) {
    this.listeners.set(type, listener)
  }

  async createOffer() {
    this.createOfferCalls += 1
    return { type: 'offer', sdp: 'v=0\r\n' }
  }

  async createAnswer() {
    this.createAnswerCalls += 1
    return { type: 'answer', sdp: 'v=0\r\n' }
  }

  async setLocalDescription(description) {
    if (description?.type === 'rollback') {
      this.rollbackCalls += 1
      this.localDescription = null
      this.signalingState = 'stable'
      return
    }

    this.localDescription = description
    if (description?.type === 'offer') this.signalingState = 'have-local-offer'
    if (description?.type === 'answer') this.signalingState = 'stable'
  }

  async setRemoteDescription(description) {
    this.remoteDescription = description
    if (description?.type === 'offer') this.signalingState = 'have-remote-offer'
    if (description?.type === 'answer') this.signalingState = 'stable'
  }

  async addIceCandidate() {}

  close() {
    this.closed = true
    this.signalingState = 'closed'
  }
}

function createHarness(createGossipNeighborLifecycle, userId) {
  const boundPeers = []
  const sentFrames = []
  const diagnostics = []
  const lifecycle = createGossipNeighborLifecycle({
    callbacks: {
      activeCallId: () => 'call-deterministic-offerer',
      activeRoomId: () => 'room-deterministic-offerer',
      captureClientDiagnostic: (event) => diagnostics.push(event),
      currentUserId: () => userId,
      getDataTransport: () => ({
        bindPeerConnection: (peerId, pc, initiator) => {
          boundPeers.push({ peerId, pc, initiator })
        },
        close: () => {},
      }),
      sendSocketFrame: (frame) => {
        sentFrames.push(frame)
        return true
      },
    },
  })

  return { boundPeers, diagnostics, lifecycle, sentFrames }
}

try {
  globalThis.RTCPeerConnection = DeterministicOffererPeerConnection
  globalThis.RTCSessionDescription = TestSessionDescription
  globalThis.RTCIceCandidate = TestIceCandidate

  const { createGossipNeighborLifecycle } = await loadViteSsrModule(
    frontendRoot,
    '/src/domain/realtime/workspace/callWorkspace/gossipNeighborLifecycle.ts',
  )

  const lower = createHarness(createGossipNeighborLifecycle, 2)
  const higher = createHarness(createGossipNeighborLifecycle, 10)

  lower.lifecycle.applyAssignedNeighbors(
    { topology_epoch: 1, admitted_peers: [{ peer_id: 10 }] },
    new Set(['10']),
  )
  higher.lifecycle.applyAssignedNeighbors(
    { topology_epoch: 1, admitted_peers: [{ peer_id: 2 }] },
    new Set(['2']),
  )

  await delay(25)

  assert.deepEqual(
    lower.boundPeers.map((peer) => ({ peerId: peer.peerId, initiator: peer.initiator })),
    [{ peerId: '10', initiator: true }],
    'the lower numeric peer id must initiate the assigned gossip neighbor edge',
  )
  assert.deepEqual(
    higher.boundPeers.map((peer) => ({ peerId: peer.peerId, initiator: peer.initiator })),
    [{ peerId: '2', initiator: false }],
    'the higher numeric peer id must wait for the assigned gossip neighbor offer',
  )
  assert.equal(
    lower.sentFrames.filter((frame) => frame?.payload?.kind === 'gossip_neighbor_offer').length,
    1,
    'exactly one side of the assigned pair must emit the initial gossip offer',
  )
  assert.equal(
    higher.sentFrames.filter((frame) => frame?.payload?.kind === 'gossip_neighbor_offer').length,
    0,
    'the non-offerer side must not emit a competing assigned-neighbor offer',
  )

  const higherPeerConnection = higher.boundPeers[0].pc
  higherPeerConnection.signalingState = 'have-local-offer'
  higher.lifecycle.handleGossipNeighborSignal('call/offer', '2', {
    kind: 'gossip_neighbor_offer',
    runtime_path: 'gossip_primary_neighbor',
    sdp: { type: 'offer', sdp: 'v=0\r\n' },
  })

  await delay(25)

  assert.equal(
    higherPeerConnection.rollbackCalls,
    1,
    'glare handling must keep the same deterministic lower-peer offer priority',
  )
  assert.equal(
    higher.sentFrames.filter((frame) => frame?.payload?.kind === 'gossip_neighbor_answer').length,
    1,
    'the higher peer must answer after rolling back to the deterministic lower-peer offer',
  )

  const lowerPeerConnection = lower.boundPeers[0].pc
  lowerPeerConnection.signalingState = 'have-local-offer'
  lower.lifecycle.handleGossipNeighborSignal('call/offer', '10', {
    kind: 'gossip_neighbor_offer',
    runtime_path: 'gossip_primary_neighbor',
    sdp: { type: 'offer', sdp: 'v=0\r\n' },
  })

  await delay(25)

  assert.equal(
    lowerPeerConnection.rollbackCalls,
    0,
    'the deterministic offerer must not roll back for a colliding higher-peer offer',
  )

  console.log('[gossip-deterministic-offerer-contract] PASS')
} finally {
  globalThis.RTCPeerConnection = previousRtcPeerConnection
  globalThis.RTCSessionDescription = previousRtcSessionDescription
  globalThis.RTCIceCandidate = previousRtcIceCandidate
}
