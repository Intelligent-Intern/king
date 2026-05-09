import { GossipController, type GossipDelivery, type GossipFrameMessage } from '../../src/lib/gossipmesh/gossipController'

const ROOM_ID = 'sputnik-local-gossip-room'
const CALL_ID = 'sputnik-local-gossip-call'
const ALICE_ID = 'alice'
const MEDIA_GENERATION = 1
const GOSSIP_FRAME_INTERVAL_MS = 220
const METRICS_INTERVAL_MS = 250

const BOT_DEFINITIONS = Object.freeze([
  { id: 'sputnik-1', label: 'Sputnik 1', color: '#13b981', toneHz: 330 },
  { id: 'sputnik-2', label: 'Sputnik 2', color: '#16a3b8', toneHz: 392 },
  { id: 'sputnik-3', label: 'Sputnik 3', color: '#8b5cf6', toneHz: 494 },
  { id: 'sputnik-4', label: 'Sputnik 4', color: '#ef4444', toneHz: 587 },
  { id: 'sputnik-5', label: 'Sputnik 5', color: '#84cc16', toneHz: 659 },
  { id: 'sputnik-6', label: 'Sputnik 6', color: '#06b6d4', toneHz: 698 },
  { id: 'sputnik-7', label: 'Sputnik 7', color: '#ec4899', toneHz: 784 },
  { id: 'sputnik-8', label: 'Sputnik 8', color: '#64748b', toneHz: 880 },
  { id: 'sputnik-9', label: 'Sputnik 9', color: '#f97316', toneHz: 988 },
  { id: 'sputnik-10', label: 'Sputnik 10', color: '#14b8a6', toneHz: 1047 },
])

type PeerConnectionPairState = 'new' | 'connecting' | 'connected' | 'disconnected' | 'failed' | 'closed'

interface BotRuntime {
  id: string
  label: string
  color: string
  toneHz: number
  generatedCanvas: HTMLCanvasElement
  generatedContext: CanvasRenderingContext2D
  generatedVideoStream: MediaStream
  generatedAudioStream: MediaStream | null
  audioContext: AudioContext | null
  alicePeerConnection: RTCPeerConnection
  botPeerConnection: RTCPeerConnection
  aliceReceivesVideo: HTMLVideoElement
  botReceivesVideo: HTMLVideoElement
  aliceRemoteStream: MediaStream
  botRemoteStream: MediaStream
  dataChannel: RTCDataChannel | null
  inboundDataChannel: RTCDataChannel | null
  renderRaf: number
  frameCount: number
  sawCamera: boolean
  gossipSequence: number
}

const startButton = byId<HTMLButtonElement>('startButton')
const stopButton = byId<HTMLButtonElement>('stopButton')
const soundToggle = byId<HTMLInputElement>('soundToggle')
const fakePeerCountSelect = byId<HTMLSelectElement>('fakePeerCountSelect')
const statusBadge = byId<HTMLSpanElement>('status')
const localCameraVideo = byId<HTMLVideoElement>('localCameraVideo')
const generatedGrid = byId<HTMLDivElement>('sputnikGeneratedGrid')
const aliceReceivesGrid = byId<HTMLDivElement>('aliceReceivesGrid')
const sputnikReceivesGrid = byId<HTMLDivElement>('sputnikReceivesGrid')
const aliceState = byId<HTMLElement>('aliceState')
const sputnikState = byId<HTMLElement>('sputnikState')
const iceState = byId<HTMLElement>('iceState')
const sputnikFrames = byId<HTMLElement>('sputnikFrames')
const botSawCamera = byId<HTMLElement>('botSawCamera')
const dataChannelState = byId<HTMLElement>('dataChannelState')
const audioState = byId<HTMLElement>('audioState')
const gossipReceived = byId<HTMLElement>('gossipReceived')
const gossipSends = byId<HTMLElement>('gossipSends')
const eventLog = byId<HTMLDivElement>('eventLog')

let localCameraStream: MediaStream | null = null
let bots: BotRuntime[] = []
let gossipControllers: Map<string, GossipController> = new Map()
let gossipDeliveries: GossipDelivery[] = []
let gossipTransmissions: Array<{ fromPeerId: string; targetPeerId: string; frameId: string }> = []
let gossipFrameTimer = 0
let metricsTimer = 0
let running = false
let aliceGossipSequence = 0

startButton.addEventListener('click', () => {
  void startHarness()
})
stopButton.addEventListener('click', stopHarness)
window.addEventListener('beforeunload', stopHarness)

function byId<T extends HTMLElement>(id: string): T {
  const element = document.getElementById(id)
  if (!element) throw new Error(`Missing Sputnik harness element: ${id}`)
  return element as T
}

async function startHarness(): Promise<void> {
  if (running) return
  stopHarness()
  running = true
  startButton.disabled = true
  stopButton.disabled = false
  fakePeerCountSelect.disabled = true
  soundToggle.disabled = true
  setStatus('starting')
  logEvent('Starting local camera and fake Sputnik peers.')

  try {
    localCameraStream = await navigator.mediaDevices.getUserMedia({
      video: {
        width: { ideal: 1280 },
        height: { ideal: 720 },
        frameRate: { ideal: 30, max: 30 },
      },
      audio: false,
    })
    localCameraVideo.srcObject = localCameraStream
    await playMedia(localCameraVideo, 'local camera')

    const selectedBots = BOT_DEFINITIONS.slice(0, selectedFakePeerCount())
    createGossipControllers([ALICE_ID, ...selectedBots.map((bot) => bot.id)])

    for (const bot of selectedBots) {
      const runtime = await createBotRuntime(bot)
      bots.push(runtime)
    }

    startGossipPublisherLoop()
    metricsTimer = window.setInterval(updateMetrics, METRICS_INTERVAL_MS)
    updateMetrics()
    setStatus('connected')
    logEvent(`Started Alice plus ${bots.length} fake Sputnik peer${bots.length === 1 ? '' : 's'}.`)
  } catch (error) {
    logEvent(`Start failed: ${error instanceof Error ? error.message : String(error)}`)
    stopHarness()
    setStatus('error')
  }
}

function stopHarness(): void {
  running = false
  startButton.disabled = false
  stopButton.disabled = true
  fakePeerCountSelect.disabled = false
  soundToggle.disabled = false

  if (gossipFrameTimer) window.clearInterval(gossipFrameTimer)
  if (metricsTimer) window.clearInterval(metricsTimer)
  gossipFrameTimer = 0
  metricsTimer = 0

  for (const bot of bots) {
    stopBotRuntime(bot)
  }
  bots = []

  if (localCameraStream) {
    for (const track of localCameraStream.getTracks()) track.stop()
  }
  localCameraStream = null
  localCameraVideo.srcObject = null

  for (const controller of gossipControllers.values()) {
    controller.dispose()
  }
  gossipControllers = new Map()
  gossipDeliveries = []
  gossipTransmissions = []
  aliceGossipSequence = 0

  generatedGrid.replaceChildren()
  aliceReceivesGrid.replaceChildren()
  sputnikReceivesGrid.replaceChildren()
  updateMetrics()
  setStatus('idle')
}

async function createBotRuntime(definition: typeof BOT_DEFINITIONS[number]): Promise<BotRuntime> {
  const generatedCanvas = document.createElement('canvas')
  generatedCanvas.width = 1280
  generatedCanvas.height = 720
  const generatedContext = generatedCanvas.getContext('2d')
  if (!generatedContext) throw new Error(`Cannot create canvas context for ${definition.label}`)

  const generatedVideoStream = generatedCanvas.captureStream(30)
  const audio = soundToggle.checked ? createSputnikAudioStream(definition.toneHz) : { stream: null, context: null }
  const botLocalStream = new MediaStream([
    ...generatedVideoStream.getVideoTracks(),
    ...(audio.stream ? audio.stream.getAudioTracks() : []),
  ])

  const aliceReceivesVideo = document.createElement('video')
  aliceReceivesVideo.autoplay = true
  aliceReceivesVideo.playsInline = true
  aliceReceivesVideo.muted = !soundToggle.checked

  const botReceivesVideo = document.createElement('video')
  botReceivesVideo.autoplay = true
  botReceivesVideo.playsInline = true
  botReceivesVideo.muted = true

  generatedGrid.appendChild(createMediaCard(definition.label, 'generated', generatedCanvas))
  aliceReceivesGrid.appendChild(createMediaCard(definition.label, 'Alice receives', aliceReceivesVideo))
  sputnikReceivesGrid.appendChild(createMediaCard(definition.label, 'receives Alice', botReceivesVideo))

  const alicePeerConnection = new RTCPeerConnection({ iceServers: [] })
  const botPeerConnection = new RTCPeerConnection({ iceServers: [] })
  const runtime: BotRuntime = {
    id: definition.id,
    label: definition.label,
    color: definition.color,
    toneHz: definition.toneHz,
    generatedCanvas,
    generatedContext,
    generatedVideoStream,
    generatedAudioStream: audio.stream,
    audioContext: audio.context,
    alicePeerConnection,
    botPeerConnection,
    aliceReceivesVideo,
    botReceivesVideo,
    aliceRemoteStream: new MediaStream(),
    botRemoteStream: new MediaStream(),
    dataChannel: null,
    inboundDataChannel: null,
    renderRaf: 0,
    frameCount: 0,
    sawCamera: false,
    gossipSequence: 0,
  }

  wirePeerConnectionPair(runtime)
  renderSputnikFrame(runtime)
  await negotiateBot(runtime, botLocalStream)
  return runtime
}

function createMediaCard(title: string, note: string, media: HTMLElement): HTMLElement {
  const card = document.createElement('div')
  card.className = 'bot-card'
  const label = document.createElement('div')
  label.className = 'bot-label'
  label.innerHTML = `<strong>${escapeHtml(title)}</strong><span>${escapeHtml(note)}</span>`
  card.appendChild(label)
  card.appendChild(media)
  return card
}

function wirePeerConnectionPair(bot: BotRuntime): void {
  bot.alicePeerConnection.onicecandidate = (event) => {
    if (event.candidate) {
      void bot.botPeerConnection.addIceCandidate(event.candidate).catch((error) => {
        logEvent(`${bot.label} bot ICE candidate failed: ${error instanceof Error ? error.message : String(error)}`)
      })
    }
  }
  bot.botPeerConnection.onicecandidate = (event) => {
    if (event.candidate) {
      void bot.alicePeerConnection.addIceCandidate(event.candidate).catch((error) => {
        logEvent(`${bot.label} Alice ICE candidate failed: ${error instanceof Error ? error.message : String(error)}`)
      })
    }
  }

  bot.alicePeerConnection.ontrack = (event) => {
    for (const track of event.streams[0]?.getTracks() || [event.track]) {
      if (!bot.aliceRemoteStream.getTracks().some((existingTrack) => existingTrack.id === track.id)) {
        bot.aliceRemoteStream.addTrack(track)
      }
    }
    bot.aliceReceivesVideo.srcObject = bot.aliceRemoteStream
    void playMedia(bot.aliceReceivesVideo, `${bot.label} remote view`)
  }

  bot.botPeerConnection.ontrack = (event) => {
    bot.sawCamera = true
    for (const track of event.streams[0]?.getTracks() || [event.track]) {
      if (!bot.botRemoteStream.getTracks().some((existingTrack) => existingTrack.id === track.id)) {
        bot.botRemoteStream.addTrack(track)
      }
    }
    bot.botReceivesVideo.srcObject = bot.botRemoteStream
    void playMedia(bot.botReceivesVideo, `${bot.label} camera receive`)
  }

  bot.alicePeerConnection.onconnectionstatechange = () => updateMetrics()
  bot.botPeerConnection.onconnectionstatechange = () => updateMetrics()
  bot.alicePeerConnection.oniceconnectionstatechange = () => updateMetrics()
  bot.botPeerConnection.oniceconnectionstatechange = () => updateMetrics()

  bot.dataChannel = bot.alicePeerConnection.createDataChannel(`sputnik-control-${bot.id}`, {
    ordered: true,
    maxRetransmits: 2,
  })
  setupDataChannel(bot, bot.dataChannel, 'alice-outbound')
  bot.botPeerConnection.ondatachannel = (event) => {
    bot.inboundDataChannel = event.channel
    setupDataChannel(bot, event.channel, 'sputnik-inbound')
  }
}

function setupDataChannel(bot: BotRuntime, channel: RTCDataChannel, label: string): void {
  channel.onopen = () => {
    updateMetrics()
    logEvent(`${bot.label} data channel open (${label}).`)
    if (label === 'alice-outbound') {
      channel.send(JSON.stringify({ type: 'sputnik_hello', peer_id: bot.id, at: Date.now() }))
    }
  }
  channel.onclose = () => {
    updateMetrics()
    logEvent(`${bot.label} data channel closed (${label}).`)
  }
  channel.onerror = () => {
    updateMetrics()
    logEvent(`${bot.label} data channel error (${label}).`)
  }
  channel.onmessage = (event) => {
    logEvent(`${bot.label} data channel message (${label}): ${String(event.data).slice(0, 140)}`)
  }
}

async function negotiateBot(bot: BotRuntime, botLocalStream: MediaStream): Promise<void> {
  if (!localCameraStream) throw new Error('Local camera stream is not ready')

  for (const track of localCameraStream.getTracks()) {
    bot.alicePeerConnection.addTrack(track, localCameraStream)
  }
  for (const track of botLocalStream.getTracks()) {
    bot.botPeerConnection.addTrack(track, botLocalStream)
  }

  const offer = await bot.alicePeerConnection.createOffer()
  await bot.alicePeerConnection.setLocalDescription(offer)
  await bot.botPeerConnection.setRemoteDescription(offer)
  const answer = await bot.botPeerConnection.createAnswer()
  await bot.botPeerConnection.setLocalDescription(answer)
  await bot.alicePeerConnection.setRemoteDescription(answer)
  logEvent(`${bot.label} local peer negotiation completed.`)
}

function createSputnikAudioStream(toneHz: number): { stream: MediaStream | null; context: AudioContext | null } {
  const AudioContextConstructor = window.AudioContext || (window as typeof window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!AudioContextConstructor) return { stream: null, context: null }

  const context = new AudioContextConstructor()
  const oscillator = context.createOscillator()
  const gain = context.createGain()
  const destination = context.createMediaStreamDestination()
  oscillator.type = 'sine'
  oscillator.frequency.value = toneHz
  gain.gain.value = 0.018
  oscillator.connect(gain)
  gain.connect(destination)
  oscillator.start()
  return { stream: destination.stream, context }
}

function renderSputnikFrame(bot: BotRuntime, timestamp = performance.now()): void {
  const { generatedCanvas: canvas, generatedContext: context } = bot
  const width = canvas.width
  const height = canvas.height
  const seconds = timestamp / 1000
  const orbitX = width * 0.5 + Math.cos(seconds * 2.2 + bot.id.length) * width * 0.28
  const orbitY = height * 0.5 + Math.sin(seconds * 1.7 + bot.id.length) * height * 0.25
  const radius = 62 + Math.sin(seconds * 3) * 10

  context.fillStyle = bot.color
  context.fillRect(0, 0, width, height)

  context.strokeStyle = 'rgba(255,255,255,0.18)'
  context.lineWidth = 4
  for (let x = 0; x <= width; x += 120) {
    context.beginPath()
    context.moveTo(x, 0)
    context.lineTo(x, height)
    context.stroke()
  }
  for (let y = 0; y <= height; y += 120) {
    context.beginPath()
    context.moveTo(0, y)
    context.lineTo(width, y)
    context.stroke()
  }

  context.save()
  context.shadowColor = 'rgba(255,255,255,0.85)'
  context.shadowBlur = 28
  context.fillStyle = 'rgba(255,255,255,0.96)'
  context.beginPath()
  context.arc(orbitX, orbitY, radius, 0, Math.PI * 2)
  context.fill()
  context.restore()

  context.fillStyle = 'rgba(4, 18, 32, 0.72)'
  context.fillRect(0, 0, width, 108)
  context.fillStyle = '#ffffff'
  context.font = '700 40px ui-monospace, Menlo, Consolas, monospace'
  context.fillText(bot.label, 36, 48)
  context.font = '26px ui-monospace, Menlo, Consolas, monospace'
  context.fillText(`generated frame ${bot.frameCount}`, 36, 88)

  bot.frameCount += 1
  bot.renderRaf = window.requestAnimationFrame((nextTimestamp) => renderSputnikFrame(bot, nextTimestamp))
}

function stopBotRuntime(bot: BotRuntime): void {
  if (bot.renderRaf) window.cancelAnimationFrame(bot.renderRaf)
  bot.renderRaf = 0

  closeDataChannel(bot.dataChannel)
  closeDataChannel(bot.inboundDataChannel)
  bot.alicePeerConnection.close()
  bot.botPeerConnection.close()

  for (const stream of [
    bot.generatedVideoStream,
    bot.generatedAudioStream,
    bot.aliceRemoteStream,
    bot.botRemoteStream,
  ]) {
    if (!stream) continue
    for (const track of stream.getTracks()) {
      if (track.readyState !== 'ended') track.stop()
    }
  }

  if (bot.audioContext && bot.audioContext.state !== 'closed') {
    void bot.audioContext.close().catch(() => undefined)
  }
}

function closeDataChannel(channel: RTCDataChannel | null): void {
  if (!channel) return
  if (channel.readyState === 'closed') return
  channel.close()
}

function createGossipControllers(peerIds: string[]): void {
  gossipControllers = new Map()
  gossipDeliveries = []
  gossipTransmissions = []

  for (const localPeerId of peerIds) {
    const controller = new GossipController(ROOM_ID, CALL_ID)
    controller.setDataLaneConfig({
      enabled: true,
      mode: 'active',
      publish: true,
      receive: true,
      diagnosticsLabel: 'sputnik_browser_fake_peer_mesh',
    })
    controller.setDataTransport({
      kind: 'rtc_datachannel',
      sendData: (targetPeerId: string, message: GossipFrameMessage, fromPeerId: string) => {
        gossipTransmissions.push({
          fromPeerId: String(fromPeerId),
          targetPeerId: String(targetPeerId),
          frameId: frameId(message),
        })
        gossipControllers.get(String(targetPeerId))?.handleData(String(targetPeerId), message, String(fromPeerId))
      },
    })
    controller.onDataMessage((delivery) => {
      gossipDeliveries.push(delivery)
    })
    for (const peerId of peerIds) controller.addPeer(peerId)
    gossipControllers.set(localPeerId, controller)
  }

  for (const peerId of peerIds) {
    gossipControllers.get(peerId)?.applyTopologyHint(peerId, createTopologyHint(peerId, peerIds, 1))
  }
}

function createTopologyHint(peerId: string, peerIds: string[], topologyEpoch: number): {
  lane: 'ops'
  type: 'topology_hint'
  room_id: string
  call_id: string
  peer_id: string
  topology_epoch: number
  neighbors: Array<{ peer_id: string; transport: 'rtc_datachannel' }>
} {
  const index = peerIds.indexOf(peerId)
  const previous = peerIds[(index + peerIds.length - 1) % peerIds.length]
  const next = peerIds[(index + 1) % peerIds.length]
  const neighbors = Array.from(new Set([previous, next]))
    .filter((neighborId) => neighborId && neighborId !== peerId)
    .map((neighborId) => ({ peer_id: neighborId, transport: 'rtc_datachannel' as const }))
  return {
    lane: 'ops',
    type: 'topology_hint',
    room_id: ROOM_ID,
    call_id: CALL_ID,
    peer_id: peerId,
    topology_epoch: topologyEpoch,
    neighbors,
  }
}

function startGossipPublisherLoop(): void {
  publishGossipFrames()
  gossipFrameTimer = window.setInterval(publishGossipFrames, GOSSIP_FRAME_INTERVAL_MS)
}

function publishGossipFrames(): void {
  if (!running) return
  aliceGossipSequence += 1
  publishGossipFrame(ALICE_ID, aliceGossipSequence, 'camera_metadata')
  for (const bot of bots) {
    bot.gossipSequence += 1
    publishGossipFrame(bot.id, bot.gossipSequence, 'sputnik_canvas_metadata')
  }
}

function publishGossipFrame(peerId: string, sequence: number, payloadKind: string): void {
  gossipControllers.get(peerId)?.publishFrame(peerId, {
    type: 'sfu/frame',
    protocol_version: 2,
    publisher_id: peerId,
    publisher_user_id: peerId,
    track_id: `${peerId}_camera`,
    frame_sequence: sequence,
    media_generation: MEDIA_GENERATION,
    frame_type: sequence % 30 === 1 ? 'keyframe' : 'delta',
    sender_sent_at_ms: Date.now(),
    payload_kind: payloadKind,
    data_base64: `${payloadKind}:${sequence}`,
  })
}

function updateMetrics(): void {
  const aliceConnectionStates = bots.map((bot) => bot.alicePeerConnection.connectionState)
  const botConnectionStates = bots.map((bot) => bot.botPeerConnection.connectionState)
  const dataStates = bots.map((bot) => bot.dataChannel?.readyState || 'closed')
  const totalFrames = bots.reduce((sum, bot) => sum + bot.frameCount, 0)
  const sawCount = bots.filter((bot) => bot.sawCamera).length
  const aggregateStats = aggregateGossipStats()

  aliceState.textContent = summarizeStates(aliceConnectionStates)
  sputnikState.textContent = summarizeStates(botConnectionStates)
  iceState.textContent = summarizeStates(bots.flatMap((bot) => [
    bot.alicePeerConnection.iceConnectionState,
    bot.botPeerConnection.iceConnectionState,
  ] as PeerConnectionPairState[]))
  sputnikFrames.textContent = String(totalFrames)
  botSawCamera.textContent = bots.length > 0 ? `${sawCount}/${bots.length}` : 'no'
  dataChannelState.textContent = summarizeStates(dataStates)
  audioState.textContent = soundToggle.checked && bots.some((bot) => bot.generatedAudioStream) ? 'on' : 'off'
  gossipReceived.textContent = String(aggregateStats.received)
  gossipSends.textContent = String(aggregateStats.sent)
}

function aggregateGossipStats(): { sent: number; received: number; duplicates: number } {
  let sent = 0
  let received = 0
  let duplicates = 0
  for (const [peerId, controller] of gossipControllers.entries()) {
    const peer = controller.getPeer(peerId)
    sent += Number(peer?.sent || 0)
    received += Number(peer?.received || 0)
    duplicates += Number(peer?.duplicates || 0)
  }
  return { sent, received, duplicates }
}

function summarizeStates(states: Array<string | undefined>): string {
  const cleanStates = states.map((state) => state || 'new')
  if (cleanStates.length === 0) return 'new'
  const counts = new Map<string, number>()
  for (const state of cleanStates) counts.set(state, (counts.get(state) || 0) + 1)
  return Array.from(counts.entries())
    .map(([state, count]) => (count > 1 ? `${state} x${count}` : state))
    .join(', ')
}

function selectedFakePeerCount(): number {
  const count = Number(fakePeerCountSelect.value)
  if (!Number.isFinite(count)) return 2
  return Math.max(1, Math.min(BOT_DEFINITIONS.length, Math.floor(count)))
}

async function playMedia(element: HTMLMediaElement, label: string): Promise<void> {
  try {
    await element.play()
  } catch (error) {
    logEvent(`${label} autoplay was blocked: ${error instanceof Error ? error.message : String(error)}`)
  }
}

function setStatus(status: 'idle' | 'starting' | 'connected' | 'error'): void {
  statusBadge.textContent = status
  statusBadge.classList.toggle('connected', status === 'connected')
  statusBadge.classList.toggle('error', status === 'error')
}

function logEvent(message: string): void {
  const row = document.createElement('div')
  row.className = 'log-row'
  row.textContent = `${new Date().toLocaleTimeString()} ${message}`
  eventLog.prepend(row)
  while (eventLog.children.length > 120) {
    eventLog.lastElementChild?.remove()
  }
}

function frameId(message: GossipFrameMessage): string {
  return `${message.publisher_id}:${message.track_id}:${message.media_generation}:${message.frame_sequence}`
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}
