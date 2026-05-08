import assert from 'node:assert/strict'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { loadViteSsrModule } from './viteSsrLoader.mjs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')

const previousRtcPeerConnection = globalThis.RTCPeerConnection

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

try {
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

  console.log('[gossip-neighbor-renegotiate-stack-contract] PASS')
} finally {
  if (previousRtcPeerConnection === undefined) {
    delete globalThis.RTCPeerConnection
  } else {
    globalThis.RTCPeerConnection = previousRtcPeerConnection
  }
}
