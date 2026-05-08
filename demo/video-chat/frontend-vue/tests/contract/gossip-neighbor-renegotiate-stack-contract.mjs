import assert from 'node:assert/strict'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { loadViteSsrModule } from './viteSsrLoader.mjs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

const previousRtcPeerConnection = globalThis.RTCPeerConnection
const previousRtcSessionDescription = globalThis.RTCSessionDescription

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

class ReentrantOfferFailurePeerConnection {
  static instances = []

  constructor() {
    this.listeners = new Map()
    this.signalingState = 'stable'
    this.connectionState = 'new'
    this.localDescription = null
    this.remoteDescription = null
    this.createOfferCalls = 0
    this.closed = false
    ReentrantOfferFailurePeerConnection.instances.push(this)
  }

  addEventListener(type, listener) {
    this.listeners.set(type, listener)
  }

  createOffer() {
    this.createOfferCalls += 1
    this.listeners.get('negotiationneeded')?.({ type: 'negotiationneeded' })
    throw new Error('sync createOffer failure')
  }

  async setLocalDescription(description) {
    this.localDescription = description
  }

  async setRemoteDescription(description) {
    this.remoteDescription = description
  }

  async createAnswer() {
    return { type: 'answer', sdp: 'v=0\r\n' }
  }

  async addIceCandidate() {}

  close() {
    this.closed = true
    this.signalingState = 'closed'
  }
}

class StableDeferredPeerConnection {
  static instances = []

  constructor() {
    this.listeners = new Map()
    this.signalingState = 'stable'
    this.connectionState = 'new'
    this.localDescription = null
    this.remoteDescription = null
    this.createOfferCalls = 0
    this.closed = false
    StableDeferredPeerConnection.instances.push(this)
  }

  addEventListener(type, listener) {
    this.listeners.set(type, listener)
  }

  async createOffer() {
    this.createOfferCalls += 1
    if (this.createOfferCalls === 1) {
      this.signalingState = 'have-remote-offer'
    }
    return { type: 'offer', sdp: 'v=0\r\n' }
  }

  async setLocalDescription(description) {
    this.localDescription = description
    if (description?.type === 'offer') this.signalingState = 'have-local-offer'
  }

  async setRemoteDescription(description) {
    this.remoteDescription = description
  }

  async createAnswer() {
    return { type: 'answer', sdp: 'v=0\r\n' }
  }

  async addIceCandidate() {}

  releaseStableSignaling() {
    this.signalingState = 'stable'
    this.listeners.get('signalingstatechange')?.({ type: 'signalingstatechange' })
  }

  close() {
    this.closed = true
    this.signalingState = 'closed'
  }
}

class SetLocalGlareDeferredPeerConnection {
  static instances = []

  constructor() {
    this.listeners = new Map()
    this.signalingState = 'stable'
    this.connectionState = 'new'
    this.localDescription = null
    this.remoteDescription = null
    this.createOfferCalls = 0
    this.setLocalOfferCalls = 0
    this.closed = false
    SetLocalGlareDeferredPeerConnection.instances.push(this)
  }

  addEventListener(type, listener) {
    this.listeners.set(type, listener)
  }

  async createOffer() {
    this.createOfferCalls += 1
    return { type: 'offer', sdp: 'v=0\r\n' }
  }

  async setLocalDescription(description) {
    if (description?.type === 'offer') {
      this.setLocalOfferCalls += 1
      if (this.setLocalOfferCalls === 1) {
        this.signalingState = 'have-remote-offer'
        throw new Error(
          "Failed to execute 'setLocalDescription' on 'RTCPeerConnection': Failed to set local offer sdp: Called in wrong state: have-remote-offer",
        )
      }
      this.signalingState = 'have-local-offer'
    }
    this.localDescription = description
  }

  async setRemoteDescription(description) {
    this.remoteDescription = description
  }

  async createAnswer() {
    return { type: 'answer', sdp: 'v=0\r\n' }
  }

  async addIceCandidate() {}

  releaseStableSignaling() {
    this.signalingState = 'stable'
    this.listeners.get('signalingstatechange')?.({ type: 'signalingstatechange' })
  }

  close() {
    this.closed = true
    this.signalingState = 'closed'
  }
}

class StableAfterRemoteOfferPeerConnection {
  static instances = []

  constructor() {
    this.listeners = new Map()
    this.signalingState = 'stable'
    this.connectionState = 'new'
    this.localDescription = null
    this.remoteDescription = null
    this.createAnswerCalls = 0
    this.setLocalAnswerCalls = 0
    this.closed = false
    StableAfterRemoteOfferPeerConnection.instances.push(this)
  }

  addEventListener(type, listener) {
    this.listeners.set(type, listener)
  }

  async createOffer() {
    return { type: 'offer', sdp: 'v=0\r\n' }
  }

  async setLocalDescription(description) {
    if (description?.type === 'answer') {
      this.setLocalAnswerCalls += 1
      throw new Error(
        "Failed to execute 'setLocalDescription' on 'RTCPeerConnection': Called in wrong signalingState: stable",
      )
    }
    this.localDescription = description
  }

  async setRemoteDescription(description) {
    this.remoteDescription = description
    this.signalingState = 'stable'
  }

  async createAnswer() {
    this.createAnswerCalls += 1
    return { type: 'answer', sdp: 'v=0\r\n' }
  }

  async addIceCandidate() {}

  close() {
    this.closed = true
    this.signalingState = 'closed'
  }
}

class RollbackStableRacePeerConnection {
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
    RollbackStableRacePeerConnection.instances.push(this)
  }

  addEventListener(type, listener) {
    this.listeners.set(type, listener)
  }

  async createOffer() {
    this.createOfferCalls += 1
    return { type: 'offer', sdp: 'v=0\r\n' }
  }

  async setLocalDescription(description) {
    if (description?.type === 'offer') {
      this.localDescription = description
      this.signalingState = 'have-local-offer'
      return
    }
    if (description?.type === 'rollback') {
      this.rollbackCalls += 1
      this.signalingState = 'stable'
      throw new Error(
        "Failed to execute 'setLocalDescription' on 'RTCPeerConnection': Called in wrong signalingState: stable",
      )
    }
    if (description?.type === 'answer') {
      this.localDescription = description
      this.signalingState = 'stable'
    }
  }

  async setRemoteDescription(description) {
    this.remoteDescription = description
    if (description?.type === 'offer') this.signalingState = 'have-remote-offer'
    if (description?.type === 'answer') this.signalingState = 'stable'
  }

  async createAnswer() {
    this.createAnswerCalls += 1
    return { type: 'answer', sdp: 'v=0\r\n' }
  }

  async addIceCandidate() {}

  close() {
    this.closed = true
    this.signalingState = 'closed'
  }
}

try {
  globalThis.RTCSessionDescription = function RTCSessionDescription(description) {
    return description
  }
  globalThis.RTCPeerConnection = ReentrantOfferFailurePeerConnection

  const { createGossipNeighborLifecycle } = await loadViteSsrModule(
    frontendRoot,
    '/src/domain/realtime/workspace/callWorkspace/gossipNeighborLifecycle.ts',
  )
  const diagnostics = []
  const lifecycle = createGossipNeighborLifecycle({
    callbacks: {
      activeCallId: () => 'call-prod-reentry',
      activeRoomId: () => 'room-prod-reentry',
      captureClientDiagnostic: (event) => diagnostics.push(event),
      currentUserId: () => 1001,
      getDataTransport: () => ({
        bindPeerConnection: () => {},
        close: () => {},
      }),
      sendSocketFrame: () => false,
    },
  })

  lifecycle.applyAssignedNeighbors(
    { topology_epoch: 42, admitted_peers: [{ peer_id: 1002 }] },
    new Set(['1002']),
  )

  await delay(350)

  const peerConnection = ReentrantOfferFailurePeerConnection.instances[0]
  assert.ok(peerConnection, 'assigned gossip neighbor must create a peer connection')
  assert.equal(
    peerConnection.createOfferCalls,
    9,
    'reentrant negotiation must be capped at one initial offer plus eight queued retries',
  )
  assert.equal(
    diagnostics.filter((event) => event?.eventType === 'gossip_neighbor_renegotiate_quarantined').length,
    1,
    'reentrant negotiation failures must be quarantined once after the bounded retry budget is exhausted',
  )
  assert.ok(
    diagnostics.some((event) => event?.eventType === 'gossip_neighbor_offer_failed'),
    'synchronous offer failures must be captured as client diagnostics',
  )

  lifecycle.closePeer('1002', 'contract_cleanup')
  const callsAtClose = peerConnection.createOfferCalls
  await delay(75)
  assert.equal(
    peerConnection.createOfferCalls,
    callsAtClose,
    'closing a gossip neighbor must cancel any queued renegotiation timer',
  )

  globalThis.RTCPeerConnection = StableDeferredPeerConnection
  const stableDiagnostics = []
  const sentFrames = []
  const stableLifecycle = createGossipNeighborLifecycle({
    callbacks: {
      activeCallId: () => 'call-prod-stable-wait',
      activeRoomId: () => 'room-prod-stable-wait',
      captureClientDiagnostic: (event) => stableDiagnostics.push(event),
      currentUserId: () => 2001,
      getDataTransport: () => ({
        bindPeerConnection: () => {},
        close: () => {},
      }),
      sendSocketFrame: (frame) => {
        sentFrames.push(frame)
        return true
      },
    },
  })

  stableLifecycle.applyAssignedNeighbors(
    { topology_epoch: 43, admitted_peers: [{ peer_id: 2002 }] },
    new Set(['2002']),
  )

  await delay(125)
  const stablePeerConnection = StableDeferredPeerConnection.instances[0]
  assert.equal(
    stablePeerConnection.createOfferCalls,
    1,
    'non-stable signaling must not spin queued gossip renegotiation attempts',
  )
  assert.equal(
    stableDiagnostics.filter((event) => event?.eventType === 'gossip_neighbor_renegotiate_quarantined').length,
    0,
    'non-stable signaling deferral must not burn the quarantine budget',
  )
  assert.ok(
    stableDiagnostics.some((event) => event?.eventType === 'gossip_neighbor_renegotiate_waiting_stable'),
    'non-stable signaling deferral must emit a stable-wait diagnostic',
  )
  stablePeerConnection.releaseStableSignaling()
  await delay(75)
  assert.equal(
    stablePeerConnection.createOfferCalls,
    2,
    'stable signaling transition must release the queued gossip renegotiation exactly once',
  )
  assert.equal(sentFrames.length, 1, 'stable signaling release must send the deferred gossip offer')

  globalThis.RTCPeerConnection = SetLocalGlareDeferredPeerConnection
  const glareDiagnostics = []
  const glareSentFrames = []
  const glareLifecycle = createGossipNeighborLifecycle({
    callbacks: {
      activeCallId: () => 'call-prod-set-local-glare',
      activeRoomId: () => 'room-prod-set-local-glare',
      captureClientDiagnostic: (event) => glareDiagnostics.push(event),
      currentUserId: () => 3001,
      getDataTransport: () => ({
        bindPeerConnection: () => {},
        close: () => {},
      }),
      sendSocketFrame: (frame) => {
        glareSentFrames.push(frame)
        return true
      },
    },
  })

  glareLifecycle.applyAssignedNeighbors(
    { topology_epoch: 44, admitted_peers: [{ peer_id: 3002 }] },
    new Set(['3002']),
  )

  await delay(75)
  const glarePeerConnection = SetLocalGlareDeferredPeerConnection.instances[0]
  assert.equal(
    glarePeerConnection.createOfferCalls,
    1,
    'setLocalDescription wrong-state glare must not spin before signaling returns to stable',
  )
  assert.equal(
    glareDiagnostics.filter((event) => event?.eventType === 'gossip_neighbor_offer_failed').length,
    0,
    'setLocalDescription wrong-state glare must be deferred rather than reported as a dead offer failure',
  )
  assert.ok(
    glareDiagnostics.some((event) => event?.eventType === 'gossip_neighbor_offer_deferred'),
    'setLocalDescription wrong-state glare must emit a deferred offer diagnostic',
  )
  glarePeerConnection.releaseStableSignaling()
  await delay(75)
  assert.equal(
    glarePeerConnection.setLocalOfferCalls,
    2,
    'stable signaling after setLocalDescription glare must retry the local offer once',
  )
  assert.equal(glareSentFrames.length, 1, 'setLocalDescription glare recovery must send the deferred gossip offer')

  globalThis.RTCPeerConnection = StableAfterRemoteOfferPeerConnection
  const staleRemoteDiagnostics = []
  const staleRemoteSentFrames = []
  const staleRemoteLifecycle = createGossipNeighborLifecycle({
    callbacks: {
      activeCallId: () => 'call-prod-stale-remote-offer',
      activeRoomId: () => 'room-prod-stale-remote-offer',
      captureClientDiagnostic: (event) => staleRemoteDiagnostics.push(event),
      currentUserId: () => 4001,
      getDataTransport: () => ({
        bindPeerConnection: () => {},
        close: () => {},
      }),
      sendSocketFrame: (frame) => {
        staleRemoteSentFrames.push(frame)
        return true
      },
    },
  })

  staleRemoteLifecycle.applyAssignedNeighbors(
    { topology_epoch: 45, admitted_peers: [{ peer_id: 4002 }] },
    new Set(),
  )
  staleRemoteLifecycle.handleGossipNeighborSignal('call/offer', '4002', {
    kind: 'gossip_neighbor_offer',
    runtime_path: 'gossip_primary_neighbor',
    sdp: { type: 'offer', sdp: 'v=0\r\n' },
  })

  await delay(75)
  const staleRemotePeerConnection = StableAfterRemoteOfferPeerConnection.instances[0]
  assert.ok(staleRemotePeerConnection, 'remote gossip offer must create a non-initiator peer connection')
  assert.equal(
    staleRemotePeerConnection.createAnswerCalls,
    0,
    'stale remote offer that leaves signaling stable must not create an invalid answer',
  )
  assert.equal(
    staleRemotePeerConnection.setLocalAnswerCalls,
    0,
    'stale remote offer that leaves signaling stable must not call setLocalDescription(answer)',
  )
  assert.equal(
    staleRemoteSentFrames.length,
    0,
    'stale remote offer must not emit a gossip answer frame',
  )
  assert.equal(
    staleRemoteDiagnostics.filter((event) => event?.eventType === 'gossip_neighbor_offer_handle_failed').length,
    0,
    'stable-state stale remote offers must not be reported as offer-handle failures',
  )
  assert.ok(
    staleRemoteDiagnostics.some((event) => event?.eventType === 'gossip_neighbor_offer_stale'),
    'stable-state stale remote offers must emit an idempotent stale-offer diagnostic',
  )

  globalThis.RTCPeerConnection = RollbackStableRacePeerConnection
  const rollbackRaceDiagnostics = []
  const rollbackRaceSentFrames = []
  const rollbackRaceLifecycle = createGossipNeighborLifecycle({
    callbacks: {
      activeCallId: () => 'call-prod-rollback-stable-race',
      activeRoomId: () => 'room-prod-rollback-stable-race',
      captureClientDiagnostic: (event) => rollbackRaceDiagnostics.push(event),
      currentUserId: () => 5002,
      getDataTransport: () => ({
        bindPeerConnection: () => {},
        close: () => {},
      }),
      sendSocketFrame: (frame) => {
        rollbackRaceSentFrames.push(frame)
        return true
      },
    },
  })

  rollbackRaceLifecycle.applyAssignedNeighbors(
    { topology_epoch: 46, admitted_peers: [{ peer_id: 5001 }] },
    new Set(['5001']),
  )
  await delay(75)
  rollbackRaceLifecycle.handleGossipNeighborSignal('call/offer', '5001', {
    kind: 'gossip_neighbor_offer',
    runtime_path: 'gossip_primary_neighbor',
    sdp: { type: 'offer', sdp: 'v=0\r\n' },
  })

  await delay(75)
  const rollbackRacePeerConnection = RollbackStableRacePeerConnection.instances[0]
  assert.ok(rollbackRacePeerConnection, 'rollback-stable race must reuse the assigned initiator peer connection')
  assert.equal(
    rollbackRacePeerConnection.rollbackCalls,
    1,
    'remote-wins glare must attempt rollback before accepting the remote offer',
  )
  assert.equal(
    rollbackRacePeerConnection.createAnswerCalls,
    1,
    'stable rollback race must continue into a valid answer instead of abandoning the remote offer',
  )
  assert.ok(
    rollbackRaceSentFrames.some((frame) => frame?.payload?.kind === 'gossip_neighbor_answer'),
    'stable rollback race must send a gossip neighbor answer',
  )
  assert.equal(
    rollbackRaceDiagnostics.filter((event) => event?.eventType === 'gossip_neighbor_offer_handle_failed').length,
    0,
    'stable rollback race must not be reported as an offer-handle failure',
  )
  assert.ok(
    rollbackRaceDiagnostics.some((event) => event?.eventType === 'gossip_neighbor_offer_stale'),
    'stable rollback race must emit an idempotent stale-offer diagnostic',
  )

  console.log('[gossip-neighbor-renegotiate-stack-contract] PASS')
} finally {
  if (previousRtcPeerConnection === undefined) {
    delete globalThis.RTCPeerConnection
  } else {
    globalThis.RTCPeerConnection = previousRtcPeerConnection
  }
  if (previousRtcSessionDescription === undefined) {
    delete globalThis.RTCSessionDescription
  } else {
    globalThis.RTCSessionDescription = previousRtcSessionDescription
  }
}
