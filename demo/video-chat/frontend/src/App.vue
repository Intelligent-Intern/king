<template>
  <div class="app-root">
    <section v-if="!isAuthenticated" class="auth-screen">
      <div class="auth-card">
        <h1>King Video Workspace</h1>
        <p>Sign in to join rooms, chat with multiple users, and start video calls.</p>

        <form class="auth-form" @submit.prevent="signIn">
          <label>
            Display name
            <input
              v-model.trim="authForm.name"
              type="text"
              maxlength="48"
              placeholder="Your name"
              required
            />
          </label>

          <label>
            Accent color
            <input v-model="authForm.color" type="color" />
          </label>

          <button type="submit">Continue</button>
        </form>
      </div>
    </section>

    <section v-else class="workspace">
      <aside class="rail">
        <header class="rail-header">
          <div class="user-chip">
            <span :style="{ backgroundColor: sessionView.color }" class="user-dot"></span>
            <div>
              <strong>{{ sessionView.name }}</strong>
              <p>{{ connectionLabel }}</p>
            </div>
          </div>
          <button class="quiet-btn" @click="signOut">Sign out</button>
        </header>

        <section class="rail-section">
          <h2>Rooms</h2>
          <form class="inline-form" @submit.prevent="createRoom">
            <input
              v-model.trim="createRoomName"
              type="text"
              maxlength="48"
              placeholder="Create room"
            />
            <button type="submit">Add</button>
          </form>

          <ul class="room-list">
            <li
              v-for="room in rooms"
              :key="room.id"
              :class="{ active: room.id === activeRoomId }"
              @click="switchRoom(room.id)"
            >
              <div>
                <strong>{{ room.name }}</strong>
                <p>{{ room.memberCount }} online</p>
              </div>
            </li>
          </ul>
        </section>

        <section class="rail-section">
          <h2>Join with invite</h2>
          <form class="inline-form" @submit.prevent="redeemInvite">
            <input
              v-model.trim="inviteCodeInput"
              type="text"
              maxlength="48"
              placeholder="Invite code"
            />
            <button type="submit">Join</button>
          </form>
        </section>
      </aside>

      <main class="stage">
        <header class="stage-header">
          <div>
            <h2>{{ activeRoom?.name || 'Room' }}</h2>
            <p>{{ activeParticipants.length }} participants</p>
          </div>

          <div class="stage-actions">
            <button :class="{ active: activeTab === 'chat' }" @click="activeTab = 'chat'">Chat</button>
            <button :class="{ active: activeTab === 'call' }" @click="activeTab = 'call'">Call</button>
            <button class="quiet-btn" @click="createInvite">Create invite</button>
          </div>
        </header>

        <Transition name="slide-panel" mode="out-in">
          <section v-if="activeTab === 'chat'" key="chat" class="chat-panel">
            <div ref="messageListRef" class="messages">
              <article
                v-for="message in activeMessages"
                :key="message.id"
                :class="{ mine: message.sender.userId === sessionView.userId }"
                class="message"
              >
                <header>
                  <strong>{{ message.sender.name }}</strong>
                  <time>{{ formatTime(message.serverTime) }}</time>
                </header>
                <p>{{ message.text }}</p>
              </article>
            </div>

            <div v-if="typingUsers.length > 0" class="typing-line">
              {{ typingUsers.join(', ') }} typing...
            </div>

            <form class="composer" @submit.prevent="sendMessage">
              <input
                v-model="messageInput"
                type="text"
                maxlength="4000"
                placeholder="Write a message"
                @input="onMessageInput"
              />
              <button type="submit" :disabled="!messageInput.trim()">Send</button>
            </form>
          </section>

          <section v-else key="call" class="call-panel">
            <div v-if="!callJoined" class="prejoin">
              <video ref="previewVideoRef" autoplay muted playsinline></video>
              <div class="prejoin-actions">
                <button @click="handleJoinCallClick">Join call</button>
              </div>
            </div>

            <div v-else class="call-live">
              <div class="video-grid" :class="{ single: remoteTiles.length === 0 }">
                <article class="video-tile local">
                  <video ref="localVideoRef" autoplay muted playsinline></video>
                  <footer>You</footer>
                </article>

                <article v-for="tile in remoteTiles" :key="tile.userId" class="video-tile">
                  <video :ref="(el) => bindRemoteVideo(tile.userId, el as HTMLVideoElement | null)" autoplay playsinline></video>
                  <footer>{{ tile.name }}</footer>
                </article>
              </div>

              <div class="call-controls">
                <button :class="{ active: isCameraEnabled }" @click="toggleCamera">
                  {{ isCameraEnabled ? 'Camera on' : 'Camera off' }}
                </button>
                <button :class="{ active: isMicEnabled }" @click="toggleMic">
                  {{ isMicEnabled ? 'Mic on' : 'Mic off' }}
                </button>
                <button class="danger" @click="leaveCall">Leave call</button>
              </div>
            </div>
          </section>
        </Transition>
      </main>

      <aside class="context">
        <section class="context-section">
          <h3>Invite</h3>
          <p v-if="lastInviteCode" class="invite-code">{{ lastInviteCode }}</p>
          <p v-else>Create an invite code for this room.</p>
          <button class="quiet-btn" :disabled="!lastInviteCode" @click="copyInviteCode">Copy code</button>
        </section>

        <section class="context-section">
          <h3>Participants</h3>
          <ul class="participant-list">
            <li v-for="participant in activeParticipants" :key="participant.userId">
              <span class="presence" :class="{ live: participant.callJoined }"></span>
              <div>
                <strong>{{ participant.name }}</strong>
                <p>{{ participant.callJoined ? 'In call' : 'Chat only' }}</p>
              </div>
            </li>
          </ul>
        </section>

        <section class="context-section compact">
          <h3>Live status</h3>
          <dl>
            <div>
              <dt>Room</dt>
              <dd>{{ activeRoom?.name || '-' }}</dd>
            </div>
            <div>
              <dt>Signal</dt>
              <dd>{{ connectionLabel }}</dd>
            </div>
            <div>
              <dt>Peers</dt>
              <dd>{{ remoteTiles.length }}</dd>
            </div>
          </dl>
        </section>
      </aside>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { IIBINDecoder, IIBINEncoder, MessageType } from '@intelligentintern/iibin'

interface Session {
  userId: string
  name: string
  color: string
}

interface Room {
  id: string
  name: string
  inviteCode: string
  memberCount: number
}

interface Participant {
  userId: string
  name: string
  roomId: string
  callJoined: boolean
}

interface ChatMessage {
  id: string
  roomId: string
  sender: {
    userId: string
    name: string
  }
  text: string
  serverTime: number
}

type ConnectionState = 'offline' | 'connecting' | 'online' | 'reconnecting'

const SESSION_STORAGE_KEY = 'king.video.chat.session.v2'
const ICE_SERVERS: RTCIceServer[] = [{ urls: 'stun:stun.l.google.com:19302' }]
const wireEncoder = new IIBINEncoder()
const DEFAULT_ACCENT_COLOR = resolveCssAccentColor()

const authForm = reactive({
  name: '',
  color: DEFAULT_ACCENT_COLOR,
})

const currentSession = ref<Session | null>(restoreSession())
const connectionState = ref<ConnectionState>('offline')
const activeTab = ref<'chat' | 'call'>('chat')
const createRoomName = ref('')
const inviteCodeInput = ref('')
const lastInviteCode = ref('')

const rooms = ref<Room[]>([])
const activeRoomId = ref('lobby')

const messagesByRoom = reactive<Record<string, ChatMessage[]>>({})
const participantsByRoom = reactive<Record<string, Participant[]>>({})
const typingByRoom = reactive<Record<string, string[]>>({})

const messageInput = ref('')
const messageListRef = ref<HTMLElement | null>(null)
let typingDebounce: ReturnType<typeof setTimeout> | null = null

const ws = ref<WebSocket | null>(null)
let reconnectTimer: ReturnType<typeof setTimeout> | null = null
let reconnectAttempt = 0

const callJoined = ref(false)
const callStatus = ref<'idle' | 'preparing' | 'live' | 'error'>('idle')
const isMicEnabled = ref(true)
const isCameraEnabled = ref(true)

const previewVideoRef = ref<HTMLVideoElement | null>(null)
const localVideoRef = ref<HTMLVideoElement | null>(null)
let localStream: MediaStream | null = null

const peerConnections = new Map<string, RTCPeerConnection>()
const remoteVideoElements = new Map<string, HTMLVideoElement>()
const remoteTiles = ref<Array<{ userId: string; name: string; stream: MediaStream }>>([])

const isAuthenticated = computed(() => currentSession.value !== null)
const sessionView = computed<Session>(() => currentSession.value || {
  userId: '',
  name: '',
  color: DEFAULT_ACCENT_COLOR,
})

const activeRoom = computed(() => rooms.value.find((room) => room.id === activeRoomId.value) || null)
const activeMessages = computed(() => messagesByRoom[activeRoomId.value] || [])
const activeParticipants = computed(() => participantsByRoom[activeRoomId.value] || [])

const typingUsers = computed(() => {
  const users = typingByRoom[activeRoomId.value] || []
  const me = currentSession.value?.userId
  return users.filter((userId) => userId !== me).map((userId) => participantName(userId))
})

const connectionLabel = computed(() => {
  if (connectionState.value === 'online') return 'Connected'
  if (connectionState.value === 'connecting') return 'Connecting'
  if (connectionState.value === 'reconnecting') return 'Reconnecting'
  return 'Offline'
})

function restoreSession(): Session | null {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    const raw = window.localStorage.getItem(SESSION_STORAGE_KEY)
    if (!raw) {
      return null
    }
    const parsed = JSON.parse(raw)
    if (
      typeof parsed?.userId === 'string'
      && typeof parsed?.name === 'string'
      && typeof parsed?.color === 'string'
    ) {
      return {
        userId: parsed.userId,
        name: parsed.name,
        color: parsed.color,
      }
    }
  } catch {
    return null
  }

  return null
}

function resolveCssAccentColor(): string {
  if (typeof window === 'undefined') {
    return '#0f62fe'
  }

  const color = window.getComputedStyle(document.documentElement)
    .getPropertyValue('--king-color-accent')
    .trim()

  return color || '#0f62fe'
}

function persistSession(): void {
  if (typeof window === 'undefined') {
    return
  }

  if (!currentSession.value) {
    window.localStorage.removeItem(SESSION_STORAGE_KEY)
    return
  }

  window.localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(currentSession.value))
}

function normalizeRoomId(value: string): string {
  const normalized = value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return normalized || 'lobby'
}

function wsUrl(roomId: string): string {
  const protocol = window.location.protocol === 'https:' ? 'wss' : 'ws'
  const host = window.location.host || '127.0.0.1:3000'
  const session = currentSession.value
  if (!session) {
    return `${protocol}://${host}/ws`
  }

  const query = new URLSearchParams({
    userId: session.userId,
    name: session.name,
    color: session.color,
    room: roomId,
  })

  return `${protocol}://${host}/ws?${query.toString()}`
}

function ensureRoomState(roomId: string): void {
  if (!messagesByRoom[roomId]) {
    messagesByRoom[roomId] = []
  }
  if (!participantsByRoom[roomId]) {
    participantsByRoom[roomId] = []
  }
  if (!typingByRoom[roomId]) {
    typingByRoom[roomId] = []
  }
}

function encodeSocketMessage(type: string, data: Record<string, unknown> = {}): ArrayBuffer {
  return wireEncoder.encode({
    type: MessageType.TEXT_MESSAGE,
    data: {
      type,
      ...data,
    },
    timestamp: Date.now(),
  })
}

async function decodeSocketMessage(payload: string | ArrayBuffer | Blob): Promise<any | null> {
  if (typeof payload === 'string') {
    try {
      return JSON.parse(payload)
    } catch {
      return null
    }
  }

  let buffer: ArrayBuffer
  if (payload instanceof ArrayBuffer) {
    buffer = payload
  } else {
    buffer = await payload.arrayBuffer()
  }

  try {
    const decoded = new IIBINDecoder(buffer).decode()
    if (decoded.type === MessageType.TEXT_MESSAGE && decoded.data && typeof decoded.data === 'object') {
      return decoded.data
    }
  } catch {
    return null
  }

  return null
}

async function refreshRooms(): Promise<void> {
  try {
    const response = await fetch('/api/rooms')
    if (!response.ok) {
      return
    }

    const payload = await response.json()
    const nextRooms = Array.isArray(payload.rooms) ? payload.rooms : []
    rooms.value = nextRooms.map((room: any) => ({
      id: String(room.id || 'lobby'),
      name: String(room.name || room.id || 'Room'),
      inviteCode: String(room.inviteCode || room.id || ''),
      memberCount: Number(room.memberCount || 0),
    }))

    for (const room of rooms.value) {
      ensureRoomState(room.id)
    }

    if (!rooms.value.some((room) => room.id === activeRoomId.value)) {
      activeRoomId.value = rooms.value[0]?.id || 'lobby'
    }
  } catch {
    // ignore network errors in UI refresh path
  }
}

function connectSocket(): void {
  if (!currentSession.value) {
    return
  }

  if (ws.value) {
    ws.value.close()
    ws.value = null
  }

  connectionState.value = reconnectAttempt > 0 ? 'reconnecting' : 'connecting'

  const socket = new WebSocket(wsUrl(activeRoomId.value))
  socket.binaryType = 'arraybuffer'
  ws.value = socket

  socket.onopen = () => {
    reconnectAttempt = 0
    connectionState.value = 'online'
  }

  socket.onmessage = async (event) => {
    const message = await decodeSocketMessage(event.data as string | ArrayBuffer | Blob)
    if (!message) {
      return
    }

    handleServerEvent(message)
    await nextTick()
    scrollMessagesToBottom()
  }

  socket.onclose = () => {
    connectionState.value = 'offline'
    scheduleReconnect()
  }

  socket.onerror = () => {
    connectionState.value = 'offline'
  }
}

function scheduleReconnect(): void {
  if (!currentSession.value) {
    return
  }

  reconnectAttempt += 1
  const backoff = Math.min(4000, 500 * reconnectAttempt)
  connectionState.value = 'reconnecting'

  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
  }

  reconnectTimer = setTimeout(() => {
    connectSocket()
  }, backoff)
}

function emit(type: string, data: Record<string, unknown> = {}): void {
  const socket = ws.value
  if (!socket || socket.readyState !== WebSocket.OPEN) {
    return
  }

  socket.send(encodeSocketMessage(type, data))
}

function updateRoomCounters(roomId: string): void {
  const room = rooms.value.find((entry) => entry.id === roomId)
  const participants = participantsByRoom[roomId] || []
  if (room) {
    room.memberCount = participants.length
  }
}

function handleServerEvent(message: any): void {
  const type = String(message.type || '')

  if (type === 'session/ready') {
    const roomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
    activeRoomId.value = roomId

    const serverRooms = Array.isArray(message.rooms) ? message.rooms : []
    rooms.value = serverRooms.map((room: any) => ({
      id: String(room.id || 'lobby'),
      name: String(room.name || room.id || 'Room'),
      inviteCode: String(room.inviteCode || room.id || ''),
      memberCount: Number(room.memberCount || 0),
    }))

    for (const room of rooms.value) {
      ensureRoomState(room.id)
    }
    return
  }

  if (type === 'room/switched') {
    const nextRoomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
    activeRoomId.value = nextRoomId
    callReset(false)
    syncLocationRoom(nextRoomId)
    return
  }

  if (type === 'room/snapshot') {
    const roomId = normalizeRoomId(String(message.roomId || 'lobby'))
    ensureRoomState(roomId)

    participantsByRoom[roomId] = (Array.isArray(message.participants) ? message.participants : []).map((participant: any) => ({
      userId: String(participant.userId || ''),
      name: String(participant.name || 'User'),
      roomId,
      callJoined: Boolean(participant.callJoined),
    }))

    updateRoomCounters(roomId)
    if (roomId === activeRoomId.value) {
      syncPeerTopology()
    }
    return
  }

  if (type === 'chat/message') {
    const roomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
    ensureRoomState(roomId)

    messagesByRoom[roomId].push({
      id: String(message.id || `${Date.now()}-${Math.random()}`),
      roomId,
      sender: {
        userId: String(message.sender?.userId || ''),
        name: String(message.sender?.name || 'User'),
      },
      text: String(message.text || ''),
      serverTime: Number(message.serverTime || Date.now()),
    })
    return
  }

  if (type === 'typing/start' || type === 'typing/stop') {
    const roomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
    const userId = String(message.user?.userId || '')
    if (!userId) {
      return
    }

    ensureRoomState(roomId)
    const next = new Set(typingByRoom[roomId])

    if (type === 'typing/start') {
      next.add(userId)
    } else {
      next.delete(userId)
    }

    typingByRoom[roomId] = [...next]
    return
  }

  if (type.startsWith('call/')) {
    handleCallSignal(message)
  }
}

async function createRoom(): Promise<void> {
  const name = createRoomName.value.trim()
  if (!name) {
    return
  }

  try {
    const response = await fetch('/api/rooms', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ name }),
    })

    if (!response.ok) {
      return
    }

    const payload = await response.json()
    const roomId = normalizeRoomId(String(payload.room?.id || 'lobby'))
    createRoomName.value = ''
    await refreshRooms()
    switchRoom(roomId)
  } catch {
    // ignore API errors in optimistic demo flow
  }
}

function switchRoom(roomId: string): void {
  const nextRoomId = normalizeRoomId(roomId)
  emit('room/switch', { roomId: nextRoomId })
}

async function createInvite(): Promise<void> {
  if (!activeRoom.value) {
    return
  }

  try {
    const response = await fetch(`/api/rooms/${encodeURIComponent(activeRoom.value.id)}/invite`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({}),
    })

    if (!response.ok) {
      return
    }

    const payload = await response.json()
    lastInviteCode.value = String(payload.inviteCode || '')
  } catch {
    // ignore
  }
}

async function redeemInvite(): Promise<void> {
  const code = inviteCodeInput.value.trim()
  if (!code) {
    return
  }

  try {
    const response = await fetch('/api/invite/redeem', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ code }),
    })

    if (!response.ok) {
      return
    }

    const payload = await response.json()
    const roomId = normalizeRoomId(String(payload.room?.id || 'lobby'))
    inviteCodeInput.value = ''
    await refreshRooms()
    switchRoom(roomId)
  } catch {
    // ignore
  }
}

async function copyInviteCode(): Promise<void> {
  if (!lastInviteCode.value) {
    return
  }

  try {
    await navigator.clipboard.writeText(lastInviteCode.value)
  } catch {
    // clipboard can fail on non-secure contexts
  }
}

function sendMessage(): void {
  const text = messageInput.value.trim()
  if (!text) {
    return
  }

  emit('chat/send', {
    roomId: activeRoomId.value,
    text,
  })

  messageInput.value = ''
  emit('typing/stop', { roomId: activeRoomId.value })
}

function onMessageInput(): void {
  emit('typing/start', { roomId: activeRoomId.value })

  if (typingDebounce) {
    clearTimeout(typingDebounce)
  }

  typingDebounce = setTimeout(() => {
    emit('typing/stop', { roomId: activeRoomId.value })
  }, 900)
}

function scrollMessagesToBottom(): void {
  const container = messageListRef.value
  if (!container) {
    return
  }
  container.scrollTop = container.scrollHeight
}

function participantName(userId: string): string {
  const participant = activeParticipants.value.find((entry) => entry.userId === userId)
  return participant ? participant.name : userId.slice(0, 8)
}

async function prepareLocalStream(): Promise<void> {
  if (localStream) {
    return
  }

  localStream = await navigator.mediaDevices.getUserMedia({
    audio: true,
    video: true,
  })

  applyTrackState()
  attachLocalPreview()
}

function attachLocalPreview(): void {
  if (previewVideoRef.value && localStream) {
    previewVideoRef.value.srcObject = localStream
  }
  if (localVideoRef.value && localStream) {
    localVideoRef.value.srcObject = localStream
  }
}

function applyTrackState(): void {
  if (!localStream) {
    return
  }

  for (const track of localStream.getAudioTracks()) {
    track.enabled = isMicEnabled.value
  }
  for (const track of localStream.getVideoTracks()) {
    track.enabled = isCameraEnabled.value
  }
}

function bindRemoteVideo(userId: string, element: HTMLVideoElement | null): void {
  if (!element) {
    remoteVideoElements.delete(userId)
    return
  }

  remoteVideoElements.set(userId, element)
  const tile = remoteTiles.value.find((entry) => entry.userId === userId)
  if (tile) {
    element.srcObject = tile.stream
  }
}

function setRemoteStream(userId: string, stream: MediaStream): void {
  const existing = remoteTiles.value.find((entry) => entry.userId === userId)
  if (existing) {
    existing.stream = stream
    existing.name = participantName(userId)
  } else {
    remoteTiles.value.push({
      userId,
      name: participantName(userId),
      stream,
    })
  }

  const element = remoteVideoElements.get(userId)
  if (element) {
    element.srcObject = stream
  }
}

function removeRemoteStream(userId: string): void {
  remoteTiles.value = remoteTiles.value.filter((entry) => entry.userId !== userId)
  remoteVideoElements.delete(userId)
}

function shouldInitiateOffer(peerUserId: string): boolean {
  const myId = currentSession.value?.userId || ''
  return myId > peerUserId
}

function createPeerConnection(peerUserId: string): RTCPeerConnection {
  const connection = new RTCPeerConnection({ iceServers: ICE_SERVERS })

  if (localStream) {
    for (const track of localStream.getTracks()) {
      connection.addTrack(track, localStream)
    }
  }

  connection.onicecandidate = (event) => {
    if (!event.candidate) {
      return
    }

    emit('call/ice', {
      roomId: activeRoomId.value,
      targetUserId: peerUserId,
      payload: event.candidate.toJSON(),
    })
  }

  connection.ontrack = (event) => {
    const [stream] = event.streams
    if (stream) {
      setRemoteStream(peerUserId, stream)
    }
  }

  connection.onconnectionstatechange = () => {
    if (['failed', 'closed', 'disconnected'].includes(connection.connectionState)) {
      closePeer(peerUserId, false)
    }
  }

  peerConnections.set(peerUserId, connection)
  return connection
}

async function ensureOffer(peerUserId: string): Promise<void> {
  const participant = activeParticipants.value.find((entry) => entry.userId === peerUserId)
  if (!participant || !participant.callJoined) {
    return
  }

  let connection = peerConnections.get(peerUserId)
  if (!connection) {
    connection = createPeerConnection(peerUserId)
  }

  if (!shouldInitiateOffer(peerUserId)) {
    return
  }

  const offer = await connection.createOffer()
  await connection.setLocalDescription(offer)

  emit('call/offer', {
    roomId: activeRoomId.value,
    targetUserId: peerUserId,
    payload: offer,
  })
}

function closePeer(peerUserId: string, notify = true): void {
  const connection = peerConnections.get(peerUserId)
  if (connection) {
    peerConnections.delete(peerUserId)
    connection.onicecandidate = null
    connection.ontrack = null
    connection.onconnectionstatechange = null
    connection.close()
  }

  removeRemoteStream(peerUserId)

  if (notify) {
    emit('call/hangup', {
      roomId: activeRoomId.value,
      targetUserId: peerUserId,
      payload: null,
    })
  }
}

function syncPeerTopology(): void {
  if (!callJoined.value) {
    return
  }

  const me = currentSession.value?.userId
  if (!me) {
    return
  }

  const targetUsers = activeParticipants.value
    .filter((entry) => entry.userId !== me && entry.callJoined)
    .map((entry) => entry.userId)

  for (const userId of targetUsers) {
    if (!peerConnections.has(userId)) {
      void ensureOffer(userId)
    }
  }

  for (const existingUserId of [...peerConnections.keys()]) {
    if (!targetUsers.includes(existingUserId)) {
      closePeer(existingUserId, false)
    }
  }
}

async function handleCallSignal(message: any): Promise<void> {
  const roomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
  if (roomId !== activeRoomId.value) {
    return
  }

  const senderUserId = String(message.sender?.userId || '')
  const me = currentSession.value?.userId
  if (!senderUserId || senderUserId === me) {
    return
  }

  const targetUserId = message.targetUserId ? String(message.targetUserId) : ''
  if (targetUserId && targetUserId !== me) {
    return
  }

  if (!callJoined.value && ['call/offer', 'call/answer', 'call/ice'].includes(String(message.type))) {
    await joinCall(true)
  }

  let connection = peerConnections.get(senderUserId)
  if (!connection) {
    connection = createPeerConnection(senderUserId)
  }

  const payload = message.payload || null
  const type = String(message.type || '')

  if (type === 'call/offer') {
    if (!payload) {
      return
    }
    await connection.setRemoteDescription(new RTCSessionDescription(payload))
    const answer = await connection.createAnswer()
    await connection.setLocalDescription(answer)

    emit('call/answer', {
      roomId: activeRoomId.value,
      targetUserId: senderUserId,
      payload: answer,
    })
    return
  }

  if (type === 'call/answer') {
    if (!payload) {
      return
    }
    await connection.setRemoteDescription(new RTCSessionDescription(payload))
    return
  }

  if (type === 'call/ice') {
    if (!payload) {
      return
    }
    await connection.addIceCandidate(new RTCIceCandidate(payload))
    return
  }

  if (type === 'call/hangup') {
    closePeer(senderUserId, false)
  }
}

async function joinCall(silent = false): Promise<void> {
  if (callJoined.value) {
    return
  }

  callStatus.value = 'preparing'

  try {
    await prepareLocalStream()
    callJoined.value = true
    callStatus.value = 'live'

    emit('call/join', {
      roomId: activeRoomId.value,
      silent,
    })

    syncPeerTopology()
  } catch {
    callStatus.value = 'error'
  }
}

function handleJoinCallClick(): void {
  void joinCall(false)
}

function callReset(notify = true): void {
  for (const peerId of [...peerConnections.keys()]) {
    closePeer(peerId, false)
  }

  if (notify) {
    emit('call/leave', { roomId: activeRoomId.value })
  }

  callJoined.value = false
  callStatus.value = 'idle'
  remoteTiles.value = []
}

function leaveCall(): void {
  callReset(true)
}

function toggleMic(): void {
  isMicEnabled.value = !isMicEnabled.value
  applyTrackState()
}

function toggleCamera(): void {
  isCameraEnabled.value = !isCameraEnabled.value
  applyTrackState()
}

function stopLocalStream(): void {
  if (!localStream) {
    return
  }
  for (const track of localStream.getTracks()) {
    track.stop()
  }
  localStream = null
}

function syncLocationRoom(roomId: string): void {
  const url = new URL(window.location.href)
  url.searchParams.set('room', roomId)
  window.history.replaceState({}, '', url)
}

async function signIn(): Promise<void> {
  const name = authForm.name.trim()
  if (!name) {
    return
  }

  try {
    const response = await fetch('/api/auth/login', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        userId: currentSession.value?.userId || null,
        name,
        color: authForm.color,
      }),
    })

    if (!response.ok) {
      return
    }

    const payload = await response.json()
    currentSession.value = {
      userId: String(payload.session?.userId || `u-${crypto.randomUUID().slice(0, 8)}`),
      name: String(payload.session?.name || name),
      color: String(payload.session?.color || authForm.color),
    }

    persistSession()
  } catch {
    // ignore auth API failures in demo mode
  }
}

function signOut(): void {
  callReset(true)
  stopLocalStream()

  if (ws.value) {
    ws.value.close()
    ws.value = null
  }

  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }

  currentSession.value = null
  persistSession()
  connectionState.value = 'offline'
}

function formatTime(value: number): string {
  return new Date(value).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  })
}

watch(isAuthenticated, (value) => {
  if (!value) {
    return
  }

  const urlRoom = normalizeRoomId(new URLSearchParams(window.location.search).get('room') || 'lobby')
  activeRoomId.value = urlRoom
  ensureRoomState(urlRoom)

  connectSocket()
  refreshRooms()
})

watch(activeMessages, () => {
  nextTick().then(() => {
    scrollMessagesToBottom()
  })
})

watch(activeParticipants, () => {
  syncPeerTopology()
}, { deep: true })

onMounted(async () => {
  if (currentSession.value) {
    authForm.name = currentSession.value.name
    authForm.color = currentSession.value.color
    connectSocket()
    await refreshRooms()
  }

  try {
    await prepareLocalStream()
  } catch {
    // device permissions can fail before call join
  }
})

onUnmounted(() => {
  callReset(false)
  stopLocalStream()

  if (ws.value) {
    ws.value.close()
  }

  if (typingDebounce) {
    clearTimeout(typingDebounce)
  }

  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
  }
})
</script>

<style scoped>
.app-root {
  min-height: 100vh;
  color: var(--king-text);
}

.auth-screen {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: var(--king-space-5);
}

.auth-card {
  width: min(28rem, 100%);
  background: var(--king-surface);
  border: var(--king-border-default);
  border-radius: var(--king-radius-4);
  padding: var(--king-space-6);
  box-shadow: var(--king-elevation-2);
}

.auth-card h1 {
  margin: 0;
  font-size: 1.5rem;
}

.auth-card p {
  margin: var(--king-space-dense) 0 var(--king-space-5);
  color: var(--king-muted);
}

.auth-form {
  display: grid;
  gap: var(--king-space-panel);
}

.auth-form label {
  display: grid;
  gap: var(--king-space-sm);
  font-size: 0.92rem;
}

.auth-form input,
.inline-form input,
.composer input {
  border: var(--king-border-default);
  border-radius: var(--king-radius-1);
  padding: var(--king-space-input-y) var(--king-space-input-x);
  background: var(--king-color-bg-surface);
  color: var(--king-text);
}

.auth-form button,
.inline-form button,
.composer button,
.stage-actions button,
.prejoin-actions button,
.call-controls button,
.quiet-btn {
  border: var(--king-border-default);
  border-radius: var(--king-radius-1);
  background: var(--king-surface);
  color: var(--king-text);
  padding: var(--king-space-control-y) var(--king-space-control-x);
  cursor: pointer;
  transition: transform var(--king-motion-fast) var(--king-motion-ease), border-color var(--king-motion-fast) var(--king-motion-ease), background-color var(--king-motion-fast) var(--king-motion-ease);
}

.auth-form button,
.inline-form button,
.composer button,
.prejoin-actions button {
  background: var(--king-accent);
  border-color: var(--king-accent);
  color: var(--king-color-on-accent);
  font-weight: 600;
}

.stage-actions button.active,
.call-controls button.active {
  border-color: var(--king-accent);
  color: var(--king-accent);
  background: var(--king-color-bg-selected);
}

.call-controls button.danger {
  background: var(--king-color-danger);
  border-color: var(--king-color-danger);
  color: var(--king-color-on-danger);
}

.workspace {
  min-height: 100vh;
  display: grid;
  grid-template-areas: 'rail stage context';
  grid-template-columns: clamp(15.5rem, 20vw, 18rem) minmax(0, 1fr) clamp(16rem, 22vw, 19rem);
  gap: var(--king-space-4);
  padding: var(--king-space-4);
}

.rail,
.stage,
.context {
  background: var(--king-surface);
  border: var(--king-border-default);
  border-radius: var(--king-radius-4);
  box-shadow: var(--king-elevation-1);
  min-height: 0;
}

.rail {
  grid-area: rail;
}

.stage {
  grid-area: stage;
}

.context {
  grid-area: context;
}

.rail,
.context {
  padding: var(--king-space-4);
  display: grid;
  align-content: start;
  gap: var(--king-space-4);
  overflow: auto;
}

.rail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--king-space-3);
}

.user-chip {
  display: flex;
  align-items: center;
  gap: var(--king-space-cozy);
}

.user-dot {
  width: var(--king-size-user-dot);
  height: var(--king-size-user-dot);
  border-radius: var(--king-radius-pill);
}

.user-chip p {
  margin: var(--king-space-micro) 0 0;
  color: var(--king-muted);
  font-size: 0.82rem;
}

.rail-section h2,
.context-section h3 {
  margin: 0;
  font-size: 1rem;
}

.inline-form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--king-space-tight);
  margin-top: var(--king-space-2);
}

.room-list {
  list-style: none;
  margin: var(--king-space-3) 0 0;
  padding: 0;
  display: grid;
  gap: var(--king-space-sm);
}

.room-list li {
  border: var(--king-border-default);
  border-radius: var(--king-radius-0);
  padding: var(--king-space-dense) var(--king-space-relaxed);
  cursor: pointer;
}

.room-list li.active {
  border-color: var(--king-accent);
  background: var(--king-color-bg-selected);
}

.room-list li p {
  margin: var(--king-space-xs) 0 0;
  color: var(--king-muted);
  font-size: 0.82rem;
}

.stage {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  overflow: hidden;
}

.stage-header {
  padding: var(--king-space-4);
  border-bottom: var(--king-border-default);
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--king-space-4);
}

.stage-header h2 {
  margin: 0;
}

.stage-header p {
  margin: var(--king-space-xs) 0 0;
  color: var(--king-muted);
  font-size: 0.9rem;
}

.stage-actions {
  display: flex;
  gap: var(--king-space-tight);
  flex-wrap: wrap;
}

.chat-panel,
.call-panel {
  padding: var(--king-space-4);
  min-height: 0;
  display: grid;
}

.chat-panel {
  grid-template-rows: minmax(0, 1fr) auto auto;
  gap: var(--king-space-relaxed);
}

.messages {
  border: var(--king-border-default);
  border-radius: var(--king-radius-3);
  background: var(--king-color-bg-surface);
  padding: var(--king-space-loose);
  overflow: auto;
  display: grid;
  gap: var(--king-space-dense);
}

.message {
  max-width: 78%;
  border: var(--king-border-default);
  border-radius: var(--king-radius-2);
  padding: var(--king-space-soft) var(--king-space-cozy);
  background: var(--king-color-bg-surface);
}

.message.mine {
  margin-left: auto;
  background: var(--king-color-bg-message-mine);
  border-color: var(--king-color-border-accent);
}

.message header {
  display: flex;
  justify-content: space-between;
  gap: var(--king-space-3);
  font-size: 0.8rem;
  color: var(--king-muted);
}

.message p {
  margin: var(--king-space-1) 0 0;
  line-height: 1.45;
}

.typing-line {
  color: var(--king-muted);
  font-size: 0.85rem;
}

.composer {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--king-space-2);
}

.prejoin {
  height: 100%;
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  gap: var(--king-space-control-x);
}

.prejoin video,
.video-tile video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  background: var(--king-color-bg-video);
  border-radius: var(--king-radius-3);
}

.prejoin-actions {
  display: flex;
  justify-content: flex-end;
}

.call-live {
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  gap: var(--king-space-control-x);
}

.video-grid {
  min-height: 0;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: var(--king-space-relaxed);
}

.video-grid.single {
  grid-template-columns: minmax(0, 1fr);
}

.video-tile {
  border: var(--king-border-default);
  border-radius: var(--king-radius-3);
  overflow: hidden;
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  min-height: 10rem;
}

.video-tile footer {
  padding: var(--king-space-2) var(--king-space-cozy);
  border-top: var(--king-border-default);
  background: var(--king-color-bg-surface);
  font-size: 0.82rem;
}

.call-controls {
  display: flex;
  justify-content: flex-end;
  gap: var(--king-space-2);
  flex-wrap: wrap;
}

.context {
  gap: var(--king-space-panel);
}

.context-section {
  border: var(--king-border-default);
  border-radius: var(--king-radius-2);
  padding: var(--king-space-3);
}

.context-section p {
  margin: var(--king-space-2) 0;
  color: var(--king-muted);
}

.invite-code {
  font-family: 'IBM Plex Mono', 'SFMono-Regular', Consolas, monospace;
  background: var(--king-color-bg-surface-alt);
  border: var(--king-border-default);
  border-radius: var(--king-radius-1);
  padding: var(--king-space-tight) var(--king-space-2);
  color: var(--king-text);
}

.participant-list {
  list-style: none;
  margin: var(--king-space-relaxed) 0 0;
  padding: 0;
  display: grid;
  gap: var(--king-space-tight);
}

.participant-list li {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: var(--king-space-soft);
  align-items: center;
}

.presence {
  width: var(--king-size-presence-dot);
  height: var(--king-size-presence-dot);
  border-radius: var(--king-radius-pill);
  background: var(--king-color-presence-idle);
}

.presence.live {
  background: var(--king-color-presence-live);
}

.participant-list p {
  margin: var(--king-space-min) 0 0;
  font-size: 0.8rem;
}

.context-section dl {
  margin: var(--king-space-dense) 0 0;
  display: grid;
  gap: var(--king-space-sm);
}

.context-section dl div {
  display: flex;
  justify-content: space-between;
  gap: var(--king-space-2);
  font-size: 0.88rem;
}

.context-section dt {
  color: var(--king-muted);
}

.slide-panel-enter-active,
.slide-panel-leave-active {
  transition: transform var(--king-motion-standard) var(--king-motion-ease), opacity var(--king-motion-standard) var(--king-motion-ease);
}

.slide-panel-enter-from {
  opacity: 0;
  transform: translateX(14px);
}

.slide-panel-leave-to {
  opacity: 0;
  transform: translateX(-14px);
}

@media (prefers-reduced-motion: reduce) {
  .slide-panel-enter-active,
  .slide-panel-leave-active,
  button {
    transition: none !important;
  }
}

@media (max-width: 1200px) {
  .workspace {
    grid-template-areas:
      'rail stage'
      'context context';
    grid-template-columns: minmax(15rem, 16.5rem) minmax(0, 1fr);
  }

  .context {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 900px) {
  .workspace {
    grid-template-areas:
      'rail'
      'stage'
      'context';
    grid-template-columns: 1fr;
    padding: var(--king-space-3);
    gap: var(--king-space-3);
  }

  .rail {
    overflow: visible;
  }

  .stage {
    min-height: 70vh;
  }

  .context {
    overflow: visible;
    grid-template-columns: 1fr;
  }

  .stage-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .call-controls {
    justify-content: stretch;
  }

  .call-controls button {
    flex: 1;
  }
}
</style>
