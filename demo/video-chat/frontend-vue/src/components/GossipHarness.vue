<template>
  <div class="gossip-harness">
    <div class="controls">
      <button @click="initPeers" :disabled="running">Start 4-Peer Mesh</button>
      <button @click="stopPeers" :disabled="!running">Stop</button>
      <button @click="clearEvents">Clear Events</button>
      <button @click="exportEvents" :disabled="events.length === 0">Export JSON</button>
      <label>
        View as:
        <select v-model="selectedPeerView">
          <option v-for="peer in peerPanes" :key="peer.id" :value="peer.id">{{ peer.label }}</option>
        </select>
      </label>
      <label>
        Fault injection:
        <select v-model="faultType">
          <option value="">None</option>
          <option value="data_drop">Data Drop (20%)</option>
          <option value="duplicate_frames">Duplicate Frames</option>
          <option value="neighbor_failure">Neighbor Failure</option>
          <option value="ops_heartbeat_loss">Ops Heartbeat Loss</option>
          <option value="ops_carrier_loss">Ops Carrier Loss</option>
        </select>
      </label>
    </div>

    <div class="peer-view">
      <h3>{{ getPeerLabel(selectedPeerView) }} Perspective</h3>
      <div class="video-grid">
        <div class="video-box own">
          <h4>Own Video ({{ getPeerLabel(selectedPeerView) }})</h4>
          <canvas :ref="el => setCanvasRef(selectedPeerView, el)" width="320" height="180"></canvas>
        </div>
        <div v-for="peer in peerPanes.filter(p => p.id !== selectedPeerView)" :key="peer.id" class="video-box">
          <h4>Received from {{ peer.label }}</h4>
          <canvas :ref="el => setCanvasRef(peer.id, el)" width="320" height="180"></canvas>
        </div>
      </div>
      <div class="peer-stats">
        <div>Sent: {{ getPeerStats(selectedPeerView).sent }}</div>
        <div>Received: {{ getPeerStats(selectedPeerView).received }}</div>
        <div>Duplicates: {{ getPeerStats(selectedPeerView).duplicates }}</div>
        <div>Neighbors: {{ getPeerStats(selectedPeerView).neighborCount }}</div>
      </div>
    </div>

    <div class="event-log">
      <h3>Event Trace ({{ events.length }} events)</h3>
      <div class="event-list">
        <div v-for="(evt, idx) in events.slice(-50)" :key="idx" :class="['event', evt.event, evt.lane]">
          <span class="ts">{{ (evt.timestamp % 10000).toFixed(0) }}</span>
          <span class="peer">{{ evt.peer_id }}</span>
          <span :class="['lane', evt.lane]">{{ evt.lane }}</span>
          <span class="event-type">{{ evt.event }}</span>
          <span class="details">{{ formatEventDetails(evt) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { GossipController, GossipEvent } from '../lib/gossipmesh/gossipController'
import { GossipLaneBus } from '../lib/gossipmesh/gossipLanes'

const PEER_COUNT = 4
const PEER_NAMES = ['Alice', 'Bob', 'Charlie', 'Diana']
const STYLEGUIDE_FRAME_COLORS = ['#00052d', '#03275a', '#1582bf', '#59c7f2']

interface PeerPane {
  id: string
  label: string
  carrierState: string
  videoStream: MediaStream | null
  ownVideoData: string | null
  receivedVideos: Record<string, string>
  stats: { sent: number; received: number; dropped: number; duplicates: number }
  neighborCount: number
  events: GossipEvent[]
}

const running = ref(false)
const cameraActive = ref(false)
const faultType = ref('')
const events = ref<GossipEvent[]>([])
const controller = ref<GossipController | null>(null)
const peerPanes = ref<PeerPane[]>([])
const selectedPeerView = ref('peer_1')
const cameraVideo = ref<HTMLVideoElement | null>(null)
const captureCanvas = ref<HTMLCanvasElement | null>(null)
const canvasRefs = ref<Record<string, HTMLCanvasElement>>({})
let cameraStream: MediaStream | null = null
let captureInterval: ReturnType<typeof setInterval> | null = null
let gossipInterval: ReturnType<typeof setInterval> | null = null
const frameSequences: Record<string, number> = {}
const receivedFrames: Record<string, Record<string, string>> = {}

function setCanvasRef(peerId: string, el: HTMLCanvasElement | null): void {
  if (el) canvasRefs.value[peerId] = el
}

async function initPeers(): Promise<void> {
  if (running.value) return
  running.value = true

  const ctrl = new GossipController(`room_1`, `call_1`)
  ctrl.setDataLaneConfig({
    mode: 'active',
    enabled: true,
    publish: true,
    receive: true,
    diagnosticsLabel: 'gossip_data_active',
  })
  controller.value = ctrl
  const panes: PeerPane[] = []

  for (let i = 0; i < PEER_COUNT; i++) {
    const peerId = `peer_${i + 1}`
    ctrl.addPeer(peerId)
    frameSequences[peerId] = 0
    panes.push({
      id: peerId,
      label: PEER_NAMES[i],
      carrierState: 'connected',
      videoStream: null,
      ownVideoData: null,
      receivedVideos: {},
      stats: { sent: 0, received: 0, dropped: 0, duplicates: 0 },
      neighborCount: 2,
      events: [],
    })
  }

  peerPanes.value = panes

  // Start stats updates and frame publishing
  gossipInterval = setInterval(() => {
    if (!ctrl) return
    events.value = ctrl.getEvents()
    for (const pane of panes) {
      const peer = ctrl.getPeer(pane.id)
      if (peer) {
        pane.carrierState = peer.carrier_state
        const stats = ctrl.getStats()
        const peerStats = stats[pane.id]
        if (peerStats) {
          pane.stats = {
            sent: peerStats.sent || 0,
            received: peerStats.received || 0,
            dropped: peerStats.dropped || 0,
            duplicates: peerStats.duplicates || 0,
          }
          pane.neighborCount = peer.neighbor_set.length
        }
      }
      pane.events = ctrl.getEvents().filter((e) => e.peer_id === pane.id).slice(-20)
    }
  }, 500)

  // Start publishing frames from all peers
  startAllPeersPublishing()
}

function stopPeers(): void {
  running.value = false
  cameraActive.value = false
  controller.value = null
  peerPanes.value = []
  events.value = []
  if (captureInterval) clearInterval(captureInterval)
  if (gossipInterval) clearInterval(gossipInterval)
  if (cameraStream) {
    cameraStream.getTracks().forEach((t) => t.stop())
    cameraStream = null
  }
}

async function toggleCamera(): Promise<void> {
  if (cameraActive.value) {
    // Stop camera
    if (cameraStream) {
      cameraStream.getTracks().forEach((t) => t.stop())
      cameraStream = null
    }
    cameraActive.value = false
    if (captureInterval) {
      clearInterval(captureInterval)
      captureInterval = null
    }
    return
  }

  try {
    cameraStream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 360 } })
    cameraActive.value = true
    if (cameraVideo.value) {
      cameraVideo.value.srcObject = cameraStream
    }
    startFrameCapture()
  } catch (err) {
    console.error('[gossip-harness] Camera error:', err)
    // Fallback to fake video
    startFakeVideo()
  }
}

function startFrameCapture(): void {
  if (!captureCanvas.value || !cameraStream) return

  const canvas = captureCanvas.value
  const ctx = canvas.getContext('2d')
  if (!ctx) return

  // Draw camera to canvas, then capture frames
  const video = document.createElement('video')
  video.srcObject = cameraStream
  video.play()

  captureInterval = setInterval(() => {
    if (!running.value) return

    ctx.drawImage(video, 0, 0, 640, 360)

    // Capture frame as data
    const frameData = canvas.toDataURL('image/jpeg', 0.8)
    sendGossipFrame(frameData)
  }, 100) // 10 fps
}

function startFakeVideo(): void {
  cameraActive.value = true
  if (!captureCanvas.value) return

  const canvas = captureCanvas.value
  const ctx = canvas.getContext('2d')
  if (!ctx) return

  let angle = 0
  captureInterval = setInterval(() => {
    if (!running.value) return

    // Draw fake video
    const time = Date.now() / 1000
    ctx.fillStyle = STYLEGUIDE_FRAME_COLORS[Math.floor(time * 4) % STYLEGUIDE_FRAME_COLORS.length]
    ctx.fillRect(0, 0, 640, 360)

    ctx.fillStyle = '#ffffff'
    ctx.beginPath()
    ctx.arc(320 + Math.cos(angle) * 150, 180 + Math.sin(angle) * 100, 50, 0, Math.PI * 2)
    ctx.fill()

    ctx.fillStyle = '#ffffff'
    ctx.font = '20px monospace'
    ctx.fillText(`Frame: ${frameSequence}`, 20, 30)

    angle += 0.05

    // Capture frame
    const frameData = canvas.toDataURL('image/jpeg', 0.8)
    sendGossipFrame(frameData)
  }, 100)
}

function sendGossipFrame(frameData: string, publisherId: string): void {
  if (!running.value || !controller.value) return

  frameSequences[publisherId] = (frameSequences[publisherId] || 0) + 1
  const seq = frameSequences[publisherId]
  const trackId = `${publisherId}_video`

  const msg = {
    type: 'sfu/frame',
    publisher_id: publisherId,
    track_id: trackId,
    frame_type: seq % 30 === 0 ? 'keyframe' : 'delta',
    frame_sequence: seq,
    media_generation: 1,
    ttl: 2,
    timestamp: Date.now(),
    payload: frameData,
  }

  controller.value.publishFrame(publisherId, msg)
}

function startAllPeersPublishing(): void {
  if (!captureCanvas.value) return

  const canvas = captureCanvas.value
  const ctx = canvas.getContext('2d')
  if (!ctx) return

  // Draw fake video for each peer with different colors
  const colors = STYLEGUIDE_FRAME_COLORS

  captureInterval = setInterval(() => {
    if (!running.value || !controller.value) return

    for (let i = 0; i < PEER_COUNT; i++) {
      const peerId = `peer_${i + 1}`
      const seq = (frameSequences[peerId] || 0) + 1
      frameSequences[peerId] = seq

      // Draw unique frame for this peer
      const time = Date.now() / 1000
      ctx.fillStyle = colors[i]
      ctx.fillRect(0, 0, 640, 360)

      ctx.fillStyle = 'white'
      ctx.beginPath()
      ctx.arc(320 + Math.cos(time + i) * 150, 180 + Math.sin(time + i) * 100, 50, 0, Math.PI * 2)
      ctx.fill()

      ctx.fillStyle = 'white'
      ctx.font = '20px monospace'
      ctx.fillText(`${PEER_NAMES[i]}: Frame ${seq}`, 20, 30)

      // Capture and publish
      const frameData = canvas.toDataURL('image/jpeg', 0.8)
      sendGossipFrame(frameData, peerId)
    }
  }, 100)
}

function shouldDropData(): boolean {
  if (faultType.value === 'data_drop') return Math.random() < 0.2
  return false
}

function clearEvents(): void {
  events.value = []
  for (const c of controllers.value) {
    // Clear events in controller
  }
}

function exportEvents(): void {
  const blob = new Blob([JSON.stringify(events.value, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'gossip-events.json'
  a.click()
  URL.revokeObjectURL(url)
}

function formatEvent(evt: GossipEvent): string {
  return `${evt.event} ${JSON.stringify(evt).slice(0, 80)}`
}

function formatEventDetails(evt: GossipEvent): string {
  const p = evt as any
  return Object.entries(p).filter(([k]) => !['timestamp', 'peer_id', 'event', 'lane'].includes(k)).map(([k, v]) => `${k}=${v}`).join(' ')
}

function getPeerLabel(peerId: string): string {
  const peer = peerPanes.value.find(p => p.id === peerId)
  return peer ? peer.label : peerId
}

function getPeerStats(peerId: string) {
  const pane = peerPanes.value.find(p => p.id === peerId)
  if (!pane) return { sent: 0, received: 0, duplicates: 0, neighborCount: 0 }
  return {
    sent: pane.stats.sent,
    received: pane.stats.received,
    duplicates: pane.stats.duplicates,
    neighborCount: pane.neighborCount,
  }
}

onBeforeUnmount(() => {
  stopPeers()
})
</script>

<style scoped>
.gossip-harness { padding: 20px; font-family: monospace; }
.controls { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.panes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px; }
.pane { border: 2px solid var(--color-border); padding: 10px; border-radius: 8px; background: var(--color-text-primary); }
.pane h3 { margin: 0 0 10px 0; display: flex; align-items: center; gap: 10px; }
.video-container { position: relative; width: 100%; aspect-ratio: 16/9; background: var(--color-primary-navy); border-radius: 4px; overflow: hidden; }
.video-container video, .video-container canvas { width: 100%; height: 100%; object-fit: contain; }
.pane-stats { display: flex; gap: 10px; font-size: 12px; margin-top: 10px; flex-wrap: wrap; }
.pane-log { font-size: 11px; max-height: 100px; overflow-y: auto; border-top: 1px solid var(--color-heading); padding-top: 8px; margin-top: 10px; }
.event-log { border: 1px solid var(--color-border); padding: 15px; background: var(--color-text-primary); border-radius: 8px; }
.event-list { max-height: 400px; overflow-y: auto; font-size: 12px; }
.event { padding: 4px 0; border-bottom: 1px solid var(--color-heading); display: flex; gap: 8px; }
.connected { color: var(--color-success); font-size: 12px; }
.degraded { color: var(--color-warning); font-size: 12px; }
.lost { color: var(--color-error); font-size: 12px; }
.ts { color: var(--color-border); min-width: 60px; }
.peer { font-weight: bold; min-width: 80px; }
.lane { padding: 2px 6px; border-radius: 3px; font-size: 11px; min-width: 40px; text-align: center; }
.lane.ops { background: var(--color-cyan-hover); color: var(--color-primary-navy); }
.lane.data { background: var(--color-success); color: var(--color-text-primary); }
.event-type { min-width: 150px; font-weight: bold; }
.details { color: var(--color-border); font-size: 11px; }
button { padding: 8px 16px; cursor: pointer; border: 1px solid var(--color-border); border-radius: 4px; background: var(--color-text-primary); }
button:hover { background: var(--color-heading); }
button:disabled { cursor: not-allowed; opacity: 0.5; }
select { padding: 4px 8px; border: 1px solid var(--color-border); border-radius: 4px; }
.camera-source { margin-bottom: 20px; }
.camera-source video { width: 320px; height: 180px; border-radius: 4px; }
</style>
