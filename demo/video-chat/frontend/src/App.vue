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

          <p v-if="authState.error" class="auth-error">{{ authState.error }}</p>
          <button type="submit" :disabled="authState.submitting || !canSubmitAuth">
            {{ authState.submitting ? 'Signing in...' : 'Continue' }}
          </button>
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
            <button type="submit" :disabled="createRoomState.submitting || !canCreateRoom">
              {{ createRoomState.submitting ? 'Adding...' : 'Add' }}
            </button>
          </form>
          <p v-if="createRoomState.error" class="inline-error">{{ createRoomState.error }}</p>

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
            <button type="submit" :disabled="redeemInviteState.submitting || !inviteCodeInput.trim()">
              {{ redeemInviteState.submitting ? 'Joining...' : 'Join' }}
            </button>
          </form>
          <p v-if="redeemInviteState.error" class="inline-error">{{ redeemInviteState.error }}</p>
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
            <button
              class="quiet-btn"
              :disabled="createInviteState.submitting || !activeRoom"
              @click="createInvite"
            >
              {{ createInviteState.submitting ? 'Creating invite...' : 'Create invite' }}
            </button>
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
                  <time>{{ formatChatTimestamp(message.serverTime) }}</time>
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
                :maxlength="CHAT_COMPOSER_MAX_LENGTH"
                placeholder="Write a message"
                @input="onMessageInput"
              />
              <button type="submit" :disabled="!canSendMessage">Send</button>
            </form>
          </section>

          <section v-else key="call" class="call-panel">
            <div v-if="!callJoined" class="prejoin">
              <video ref="previewVideoRef" autoplay muted playsinline></video>
              <p v-if="mediaPreviewState.error" class="inline-error">{{ mediaPreviewState.error }}</p>
              <p v-else-if="mediaPreviewState.status === 'ready'" class="inline-note">
                Preview ready. Join when you are ready.
              </p>
              <div class="prejoin-actions">
                <button
                  class="quiet-btn"
                  :disabled="mediaPreviewState.status === 'requesting'"
                  @click="requestCallPreview"
                >
                  {{
                    mediaPreviewState.status === 'requesting'
                      ? 'Starting preview...'
                      : mediaPreviewState.status === 'ready'
                        ? 'Refresh preview'
                        : 'Enable preview'
                  }}
                </button>
                <button :disabled="!canJoinCallFromPreview || callStatus === 'preparing'" @click="handleJoinCallClick">
                  {{ callStatus === 'preparing' ? 'Joining...' : 'Join call' }}
                </button>
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
          <p v-if="createInviteState.error" class="inline-error">{{ createInviteState.error }}</p>
          <p v-if="copyInviteStatus === 'copied'" class="inline-note">Invite code copied.</p>
          <button class="quiet-btn" :disabled="!lastInviteCode" @click="copyInviteCode">Copy code</button>
        </section>

        <section class="context-section">
          <h3>Participants</h3>
          <p v-if="activeParticipantSnapshotAt > 0" class="inline-note">
            Live snapshot {{ formatChatTimestamp(activeParticipantSnapshotAt) }}
          </p>
          <ul class="participant-list">
            <li v-for="participant in activeParticipants" :key="participant.userId">
              <span class="presence" :class="{ live: participant.callJoined }"></span>
              <div>
                <strong>{{ participant.name }}</strong>
                <p>{{ participant.callJoined ? 'In call' : 'Chat only' }}</p>
              </div>
            </li>
          </ul>
          <p v-if="activeParticipants.length === 0" class="inline-note">No participants in this room yet.</p>
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
import {
  appendFanoutChatMessage,
  normalizeFanoutChatMessage,
} from './lib/chatFanout'
import { applyCallPresenceSignal, setParticipantCallJoined } from './lib/callPresence'
import { shouldAcceptInboundCallSignal } from './lib/callNegotiationRouting'
import { formatChatTimestamp } from './lib/chatTimestamp'
import {
  CHAT_COMPOSER_MAX_LENGTH,
  clampComposerDraft,
  resolveComposerPayload,
} from './lib/chatComposer'
import { normalizeInboundIceCandidate } from './lib/iceCandidate'
import {
  buildSessionFromLogin,
  normalizeDisplayName,
  persistSessionIdentity,
  restorePersistedSession,
  type SessionIdentity,
} from './lib/authSession'
import { copyTextWithFallback } from './lib/copyText'
import { resolveInviteCodeFromCreatePayload } from './lib/inviteCreate'
import { resolveRoomIdFromInviteRedeemPayload } from './lib/inviteRedeem'
import { normalizeParticipantRosterSnapshot } from './lib/participantRoster'
import { createPeerConnectionManager } from './lib/peerConnectionManager'
import { applyMediaTrackPreferences, setTrackKindEnabled } from './lib/mediaTrackToggle'
import { pruneRemoteTiles, upsertRemoteTile } from './lib/remoteTiles'
import {
  canJoinFromPreview,
  mapPreviewAccessError,
  type MediaPreviewState,
} from './lib/prejoinPreview'
import { normalizeRoomCreateName, optimisticRoomId, roomIdCandidateForAttempt } from './lib/roomCreate'
import { normalizeRoomDirectory } from './lib/roomDirectory'
import { decideRoomSwitch, roomSwitchUiReset } from './lib/roomSwitch'
import { activeTypingUsers, applyTypingSignal } from './lib/typingPresence'
import type { Room } from './lib/types'
import { hasAuthenticatedSession } from './lib/workspaceAuth'

type Session = SessionIdentity

interface Participant {
  userId: string
  name: string
  roomId: string
  callJoined: boolean
  connectedAt: number
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

const ICE_SERVERS: RTCIceServer[] = [{ urls: 'stun:stun.l.google.com:19302' }]
const TYPING_IDLE_TIMEOUT_MS = 3000
const TYPING_SWEEP_INTERVAL_MS = 1000
const wireEncoder = new IIBINEncoder()
const DEFAULT_ACCENT_COLOR = resolveCssAccentColor()

const authForm = reactive({
  name: '',
  color: DEFAULT_ACCENT_COLOR,
})
const authState = reactive({
  submitting: false,
  error: '',
})

const currentSession = ref<Session | null>(restoreSession())
const connectionState = ref<ConnectionState>('offline')
const activeTab = ref<'chat' | 'call'>('chat')
const createRoomName = ref('')
const createRoomState = reactive({
  submitting: false,
  error: '',
})
const createInviteState = reactive({
  submitting: false,
  error: '',
})
const redeemInviteState = reactive({
  submitting: false,
  error: '',
})
const copyInviteStatus = ref<'idle' | 'copied'>('idle')
const inviteCodeInput = ref('')
const lastInviteCode = ref('')

const rooms = ref<Room[]>([])
const activeRoomId = ref('lobby')

const messagesByRoom = reactive<Record<string, ChatMessage[]>>({})
const participantsByRoom = reactive<Record<string, Participant[]>>({})
const participantSnapshotAtByRoom = reactive<Record<string, number>>({})
const typingStateByRoom = reactive<Record<string, Record<string, number>>>({})
const typingByRoom = reactive<Record<string, string[]>>({})

const messageInput = ref('')
const messageListRef = ref<HTMLElement | null>(null)
let typingDebounce: ReturnType<typeof setTimeout> | null = null
let copyInviteResetTimer: ReturnType<typeof setTimeout> | null = null
let typingSweepTimer: ReturnType<typeof setInterval> | null = null

const ws = ref<WebSocket | null>(null)
let reconnectTimer: ReturnType<typeof setTimeout> | null = null
let reconnectAttempt = 0
let reconnectSuppressed = false

const callJoined = ref(false)
const callStatus = ref<'idle' | 'preparing' | 'live' | 'error'>('idle')
const mediaPreviewState = reactive<{
  status: MediaPreviewState
  error: string
}>({
  status: 'idle',
  error: '',
})
const isMicEnabled = ref(true)
const isCameraEnabled = ref(true)

const previewVideoRef = ref<HTMLVideoElement | null>(null)
const localVideoRef = ref<HTMLVideoElement | null>(null)
let localStream: MediaStream | null = null

const peerConnections = createPeerConnectionManager<RTCPeerConnection>()
const remoteVideoElements = new Map<string, HTMLVideoElement>()
const remoteStreamWatchers = new Map<string, () => void>()
const remoteTiles = ref<Array<{ userId: string; name: string; stream: MediaStream }>>([])

const isAuthenticated = computed(() => currentSession.value !== null)
const canSubmitAuth = computed(() => normalizeDisplayName(authForm.name).length > 0)
const canCreateRoom = computed(() => normalizeRoomCreateName(createRoomName.value).length > 0)
const canSendMessage = computed(() => resolveComposerPayload(messageInput.value) !== null)
const canJoinCallFromPreview = computed(() => canJoinFromPreview(mediaPreviewState.status))
const sessionView = computed<Session>(() => currentSession.value || {
  userId: '',
  name: '',
  color: DEFAULT_ACCENT_COLOR,
})

const activeRoom = computed(() => rooms.value.find((room) => room.id === activeRoomId.value) || null)
const activeMessages = computed(() => messagesByRoom[activeRoomId.value] || [])
const activeParticipants = computed(() => participantsByRoom[activeRoomId.value] || [])
const activeParticipantSnapshotAt = computed(() => participantSnapshotAtByRoom[activeRoomId.value] || 0)

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

function authenticatedSession(): Session | null {
  const session = currentSession.value
  return hasAuthenticatedSession(session) ? session : null
}

function restoreSession(): Session | null {
  if (typeof window === 'undefined') {
    return null
  }

  return restorePersistedSession(window.localStorage)
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

  persistSessionIdentity(window.localStorage, currentSession.value)
}

function normalizeRoomId(value: string): string {
  const normalized = value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return normalized || 'lobby'
}

function wsUrl(roomId: string): string {
  const protocol = window.location.protocol === 'https:' ? 'wss' : 'ws'
  const host = window.location.host || '127.0.0.1:3000'
  const session = authenticatedSession()
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
  if (!participantSnapshotAtByRoom[roomId]) {
    participantSnapshotAtByRoom[roomId] = 0
  }
  if (!typingStateByRoom[roomId]) {
    typingStateByRoom[roomId] = {}
  }
  if (!typingByRoom[roomId]) {
    typingByRoom[roomId] = []
  }
}

function refreshTypingUsersForRoom(roomId: string, now: number = Date.now()): void {
  ensureRoomState(roomId)
  typingByRoom[roomId] = activeTypingUsers(typingStateByRoom[roomId], TYPING_IDLE_TIMEOUT_MS, now)
}

function sweepTypingUsers(now: number = Date.now()): void {
  for (const roomId of Object.keys(typingStateByRoom)) {
    refreshTypingUsersForRoom(roomId, now)
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
  if (!authenticatedSession()) {
    return
  }

  try {
    const response = await fetch('/api/rooms')
    if (!response.ok) {
      return
    }

    const payload = await response.json()
    rooms.value = normalizeRoomDirectory(payload.rooms)

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

function upsertRoomDirectoryEntry(room: Room): void {
  const nextRooms = rooms.value.filter((entry) => entry.id !== room.id)
  nextRooms.push(room)
  rooms.value = normalizeRoomDirectory(nextRooms)
  for (const entry of rooms.value) {
    ensureRoomState(entry.id)
  }
}

function removeRoomDirectoryEntry(roomId: string): void {
  rooms.value = rooms.value.filter((entry) => entry.id !== roomId)
}

function connectSocket(): void {
  if (!authenticatedSession()) {
    return
  }

  reconnectSuppressed = false

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
    ws.value = null
    connectionState.value = 'offline'
    if (reconnectSuppressed) {
      return
    }
    scheduleReconnect()
  }

  socket.onerror = () => {
    connectionState.value = 'offline'
  }
}

function scheduleReconnect(): void {
  if (!currentSession.value || reconnectSuppressed) {
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

    rooms.value = normalizeRoomDirectory(message.rooms)

    for (const room of rooms.value) {
      ensureRoomState(room.id)
    }
    return
  }

  if (type === 'rooms/directory') {
    rooms.value = normalizeRoomDirectory(message.rooms)
    for (const room of rooms.value) {
      ensureRoomState(room.id)
    }
    return
  }

  if (type === 'room/switched') {
    const decision = decideRoomSwitch(
      activeRoomId.value,
      String(message.roomId || activeRoomId.value)
    )
    const nextRoomId = decision.nextRoomId
    ensureRoomState(nextRoomId)

    const myUserId = currentSession.value?.userId || ''
    if (decision.shouldSwitch && myUserId) {
      applyTypingSignal(
        typingStateByRoom[decision.previousRoomId],
        'typing/stop',
        myUserId,
        Date.now()
      )
      refreshTypingUsersForRoom(decision.previousRoomId)
    }

    if (typingDebounce) {
      clearTimeout(typingDebounce)
      typingDebounce = null
    }

    const reset = roomSwitchUiReset()
    activeTab.value = reset.activeTab
    messageInput.value = reset.messageInput
    lastInviteCode.value = reset.lastInviteCode
    createInviteState.error = ''
    createInviteState.submitting = false
    resetCopyInviteStatus()

    activeRoomId.value = nextRoomId
    callReset(false)
    syncLocationRoom(nextRoomId)
    return
  }

  if (type === 'room/snapshot') {
    const roomId = normalizeRoomId(String(message.roomId || 'lobby'))
    ensureRoomState(roomId)

    participantsByRoom[roomId] = normalizeParticipantRosterSnapshot(message.participants, roomId)
    participantSnapshotAtByRoom[roomId] = Number(message.serverTime || Date.now())
    const roomUsers = new Set(participantsByRoom[roomId].map((participant) => participant.userId))
    for (const userId of Object.keys(typingStateByRoom[roomId])) {
      if (!roomUsers.has(userId)) {
        delete typingStateByRoom[roomId][userId]
      }
    }
    refreshTypingUsersForRoom(roomId)

    updateRoomCounters(roomId)
    if (roomId === activeRoomId.value) {
      syncPeerTopology()
    }
    return
  }

  if (type === 'chat/message') {
    const normalized = normalizeFanoutChatMessage(message, activeRoomId.value)
    if (!normalized) {
      return
    }

    ensureRoomState(normalized.roomId)
    appendFanoutChatMessage(messagesByRoom[normalized.roomId], normalized)
    return
  }

  if (type === 'typing/start' || type === 'typing/stop') {
    const roomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
    const userId = String(message.user?.userId || '')
    if (!userId) {
      return
    }

    ensureRoomState(roomId)
    applyTypingSignal(
      typingStateByRoom[roomId],
      type,
      userId,
      Number(message.serverTime || Date.now())
    )
    refreshTypingUsersForRoom(roomId)
    return
  }

  if (type === 'call/joined' || type === 'call/left') {
    const roomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
    ensureRoomState(roomId)
    participantsByRoom[roomId] = applyCallPresenceSignal(
      participantsByRoom[roomId],
      type,
      message.user,
      roomId,
      Number(message.serverTime || Date.now())
    )
    updateRoomCounters(roomId)
    if (roomId === activeRoomId.value) {
      syncPeerTopology()
    }
    return
  }

  if (type.startsWith('call/')) {
    handleCallSignal(message)
  }
}

async function createRoom(): Promise<void> {
  if (!authenticatedSession()) {
    return
  }

  const name = normalizeRoomCreateName(createRoomName.value)
  createRoomName.value = name
  createRoomState.error = ''

  if (!name) {
    return
  }

  createRoomState.submitting = true
  const baseRoomId = normalizeRoomId(name)
  const pendingRoomId = optimisticRoomId(baseRoomId, Date.now())
  upsertRoomDirectoryEntry({
    id: pendingRoomId,
    name,
    inviteCode: pendingRoomId,
    memberCount: 0,
    createdAt: Date.now(),
  })

  try {
    let createdRoom: Room | null = null

    for (let attempt = 0; attempt < 5; attempt += 1) {
      const candidateId = roomIdCandidateForAttempt(baseRoomId, attempt)
      const response = await fetch('/api/rooms', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ name, id: candidateId }),
      })

      if (response.status === 409) {
        continue
      }

      if (!response.ok) {
        createRoomState.error = 'Could not create room right now.'
        break
      }

      const payload = await response.json()
      const mapped = normalizeRoomDirectory([payload.room])[0] ?? null
      if (!mapped) {
        createRoomState.error = 'Room creation returned an invalid payload.'
        break
      }

      createdRoom = {
        ...mapped,
        createdAt: mapped.createdAt > 0 ? mapped.createdAt : Date.now(),
      }
      break
    }

    if (!createdRoom) {
      if (createRoomState.error === '') {
        createRoomState.error = 'Room ID conflict could not be resolved automatically.'
      }
      return
    }

    createRoomName.value = ''
    createRoomState.error = ''
    upsertRoomDirectoryEntry(createdRoom)
    switchRoom(createdRoom.id)
    void refreshRooms()
  } catch {
    createRoomState.error = 'Could not create room right now.'
  } finally {
    removeRoomDirectoryEntry(pendingRoomId)
    createRoomState.submitting = false
  }
}

function switchRoom(roomId: string): void {
  if (!authenticatedSession()) {
    return
  }

  const decision = decideRoomSwitch(activeRoomId.value, roomId)
  if (!decision.shouldSwitch) {
    return
  }

  if (typingDebounce) {
    clearTimeout(typingDebounce)
    typingDebounce = null
  }

  if (decision.shouldEmitTypingStop) {
    emit('typing/stop', { roomId: decision.previousRoomId })
  }

  emit('room/switch', { roomId: decision.nextRoomId })
}

async function createInvite(): Promise<void> {
  if (!authenticatedSession()) {
    return
  }

  if (!activeRoom.value) {
    return
  }

  createInviteState.error = ''
  createInviteState.submitting = true

  try {
    const response = await fetch(`/api/rooms/${encodeURIComponent(activeRoom.value.id)}/invite`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({}),
    })

    if (!response.ok) {
      createInviteState.error = 'Invite code could not be created right now.'
      return
    }

    const payload = await response.json()
    lastInviteCode.value = resolveInviteCodeFromCreatePayload(payload)
  } catch {
    createInviteState.error = 'Invite code could not be created right now.'
  } finally {
    createInviteState.submitting = false
  }
}

async function redeemInvite(): Promise<void> {
  if (!authenticatedSession()) {
    return
  }

  const code = inviteCodeInput.value.trim()
  if (!code) {
    return
  }

  redeemInviteState.error = ''
  redeemInviteState.submitting = true

  try {
    const response = await fetch('/api/invite/redeem', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ code }),
    })

    if (!response.ok) {
      redeemInviteState.error = response.status === 404
        ? 'Invite code was not found.'
        : 'Invite could not be redeemed right now.'
      return
    }

    const payload = await response.json()
    const roomId = resolveRoomIdFromInviteRedeemPayload(payload)
    const room = normalizeRoomDirectory([payload.room])[0] ?? null
    if (room) {
      upsertRoomDirectoryEntry(room)
    }

    inviteCodeInput.value = ''
    redeemInviteState.error = ''
    await refreshRooms()
    switchRoom(roomId)
  } catch {
    redeemInviteState.error = 'Invite could not be redeemed right now.'
  } finally {
    redeemInviteState.submitting = false
  }
}

async function copyInviteCode(): Promise<void> {
  if (!authenticatedSession()) {
    return
  }

  if (!lastInviteCode.value) {
    return
  }

  const copied = await copyTextWithFallback(lastInviteCode.value)
  if (!copied) {
    resetCopyInviteStatus()
    return
  }

  copyInviteStatus.value = 'copied'

  if (copyInviteResetTimer) {
    clearTimeout(copyInviteResetTimer)
  }

  copyInviteResetTimer = setTimeout(() => {
    copyInviteStatus.value = 'idle'
    copyInviteResetTimer = null
  }, 1800)
}

function resetCopyInviteStatus(): void {
  if (copyInviteResetTimer) {
    clearTimeout(copyInviteResetTimer)
    copyInviteResetTimer = null
  }
  copyInviteStatus.value = 'idle'
}

function sendMessage(): void {
  if (!authenticatedSession()) {
    return
  }

  const payload = resolveComposerPayload(messageInput.value)
  if (payload === null) {
    return
  }

  emit('chat/send', {
    roomId: activeRoomId.value,
    text: payload,
  })

  messageInput.value = ''
  emit('typing/stop', { roomId: activeRoomId.value })
}

function onMessageInput(): void {
  if (!authenticatedSession()) {
    return
  }

  messageInput.value = clampComposerDraft(messageInput.value)
  if (resolveComposerPayload(messageInput.value) === null) {
    emit('typing/stop', { roomId: activeRoomId.value })
    if (typingDebounce) {
      clearTimeout(typingDebounce)
      typingDebounce = null
    }
    return
  }

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

function setLocalCallPresence(joined: boolean): void {
  const me = currentSession.value?.userId || ''
  if (me === '') {
    return
  }

  const roomId = activeRoomId.value
  ensureRoomState(roomId)
  participantsByRoom[roomId] = setParticipantCallJoined(participantsByRoom[roomId], me, joined)
}

async function prepareLocalStream(): Promise<void> {
  if (!authenticatedSession()) {
    return
  }

  if (localStream) {
    attachLocalPreview()
    return
  }

  localStream = await navigator.mediaDevices.getUserMedia({
    audio: true,
    video: true,
  })

  applyTrackState()
  attachLocalPreview()
}

async function requestCallPreview(): Promise<boolean> {
  if (!authenticatedSession()) {
    return false
  }

  mediaPreviewState.error = ''
  mediaPreviewState.status = 'requesting'

  try {
    await prepareLocalStream()
    mediaPreviewState.status = 'ready'
    mediaPreviewState.error = ''
    return true
  } catch (error) {
    mediaPreviewState.status = 'error'
    mediaPreviewState.error = mapPreviewAccessError(error)
    return false
  }
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
  applyMediaTrackPreferences(localStream, {
    audioEnabled: isMicEnabled.value,
    videoEnabled: isCameraEnabled.value,
  })
}

function bindRemoteVideo(userId: string, element: HTMLVideoElement | null): void {
  if (!element) {
    const previous = remoteVideoElements.get(userId)
    if (previous) {
      previous.srcObject = null
    }
    remoteVideoElements.delete(userId)
    return
  }

  remoteVideoElements.set(userId, element)
  const tile = remoteTiles.value.find((entry) => entry.userId === userId)
  if (tile) {
    element.srcObject = tile.stream
  }
}

function clearRemoteStreamWatcher(userId: string): void {
  const dispose = remoteStreamWatchers.get(userId)
  if (!dispose) {
    return
  }

  remoteStreamWatchers.delete(userId)
  dispose()
}

function watchRemoteStream(userId: string, stream: MediaStream): void {
  clearRemoteStreamWatcher(userId)

  const trackTeardowns: Array<() => void> = []
  const onStreamInactive = () => {
    removeRemoteStream(userId)
  }
  stream.addEventListener('inactive', onStreamInactive)

  for (const track of stream.getTracks()) {
    const onTrackEnded = () => {
      removeRemoteStream(userId)
    }
    track.addEventListener('ended', onTrackEnded)
    trackTeardowns.push(() => {
      track.removeEventListener('ended', onTrackEnded)
    })
  }

  remoteStreamWatchers.set(userId, () => {
    stream.removeEventListener('inactive', onStreamInactive)
    for (const teardown of trackTeardowns) {
      teardown()
    }
  })
}

function detachRemoteVideo(userId: string): void {
  const element = remoteVideoElements.get(userId)
  if (element) {
    element.srcObject = null
  }
  remoteVideoElements.delete(userId)
}

function setRemoteStream(userId: string, stream: MediaStream): void {
  remoteTiles.value = upsertRemoteTile(remoteTiles.value, {
    userId,
    name: participantName(userId),
    stream,
  })
  watchRemoteStream(userId, stream)

  const element = remoteVideoElements.get(userId)
  if (element) {
    element.srcObject = stream
  }
}

function removeRemoteStream(userId: string): void {
  clearRemoteStreamWatcher(userId)
  remoteTiles.value = remoteTiles.value.filter((entry) => entry.userId !== userId)
  detachRemoteVideo(userId)
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

  return connection
}

async function ensureOffer(peerUserId: string): Promise<void> {
  const participant = activeParticipants.value.find((entry) => entry.userId === peerUserId)
  if (!participant || !participant.callJoined) {
    return
  }

  const connection = peerConnections.getOrCreate(peerUserId, () => createPeerConnection(peerUserId))

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
  peerConnections.release(peerUserId)

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

  for (const existingUserId of peerConnections.keys()) {
    if (!targetUsers.includes(existingUserId)) {
      closePeer(existingUserId, false)
    }
  }

  const prunedTiles = pruneRemoteTiles(remoteTiles.value, targetUsers)
  for (const removedUserId of prunedTiles.removedUserIds) {
    clearRemoteStreamWatcher(removedUserId)
    detachRemoteVideo(removedUserId)
  }
  remoteTiles.value = prunedTiles.tiles
}

async function handleCallSignal(message: any): Promise<void> {
  const type = String(message.type || '')
  const roomId = normalizeRoomId(String(message.roomId || activeRoomId.value))
  const senderUserId = String(message.sender?.userId || '')
  const me = currentSession.value?.userId || ''
  const targetUserId = typeof message.targetUserId === 'string' ? message.targetUserId.trim() : ''

  if (!shouldAcceptInboundCallSignal({
    type,
    roomId,
    activeRoomId: activeRoomId.value,
    senderUserId,
    currentUserId: me,
    targetUserId,
  })) {
    return
  }

  if (!callJoined.value && ['call/offer', 'call/answer', 'call/ice'].includes(type)) {
    await joinCall(true)
  }

  const connection = peerConnections.getOrCreate(senderUserId, () => createPeerConnection(senderUserId))

  const payload = message.payload || null

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
    const candidate = normalizeInboundIceCandidate(payload)
    if (!candidate) {
      return
    }
    try {
      await connection.addIceCandidate(new RTCIceCandidate(candidate))
    } catch {
      // ignore malformed/out-of-order candidate application failures
    }
    return
  }

  if (type === 'call/hangup') {
    closePeer(senderUserId, false)
  }
}

async function joinCall(silent = false): Promise<void> {
  if (!authenticatedSession()) {
    return
  }

  if (callJoined.value) {
    return
  }

  try {
    const previewReady = await requestCallPreview()
    if (!previewReady) {
      callStatus.value = 'error'
      return
    }

    callStatus.value = 'preparing'
    callJoined.value = true
    callStatus.value = 'live'

    emit('call/join', {
      roomId: activeRoomId.value,
      silent,
    })

    setLocalCallPresence(true)
    syncPeerTopology()
  } catch {
    callStatus.value = 'error'
  }
}

function handleJoinCallClick(): void {
  void joinCall(false)
}

function callReset(notify = true): void {
  const wasJoined = callJoined.value

  for (const peerId of peerConnections.keys()) {
    closePeer(peerId, false)
  }

  const staleRemoteUserIds = remoteTiles.value.map((tile) => tile.userId)
  for (const staleUserId of staleRemoteUserIds) {
    removeRemoteStream(staleUserId)
  }

  if (notify && wasJoined) {
    emit('call/leave', { roomId: activeRoomId.value })
  }

  callJoined.value = false
  callStatus.value = 'idle'
  if (wasJoined) {
    setLocalCallPresence(false)
  }
  mediaPreviewState.status = localStream ? 'ready' : 'idle'
  mediaPreviewState.error = ''
  attachLocalPreview()
}

function leaveCall(): void {
  if (!authenticatedSession()) {
    return
  }

  callReset(true)
}

function toggleMic(): void {
  if (!authenticatedSession()) {
    return
  }

  isMicEnabled.value = !isMicEnabled.value
  setTrackKindEnabled(localStream, 'audio', isMicEnabled.value)
}

function toggleCamera(): void {
  if (!authenticatedSession()) {
    return
  }

  isCameraEnabled.value = !isCameraEnabled.value
  setTrackKindEnabled(localStream, 'video', isCameraEnabled.value)
}

function stopLocalStream(): void {
  if (!localStream) {
    mediaPreviewState.status = 'idle'
    mediaPreviewState.error = ''
    return
  }
  for (const track of localStream.getTracks()) {
    track.stop()
  }
  localStream = null
  mediaPreviewState.status = 'idle'
  mediaPreviewState.error = ''

  if (previewVideoRef.value) {
    previewVideoRef.value.srcObject = null
  }
  if (localVideoRef.value) {
    localVideoRef.value.srcObject = null
  }
}

function syncLocationRoom(roomId: string): void {
  const url = new URL(window.location.href)
  url.searchParams.set('room', roomId)
  window.history.replaceState({}, '', url)
}

async function signIn(): Promise<void> {
  const name = normalizeDisplayName(authForm.name)
  authForm.name = name
  authState.error = ''
  if (!name) {
    authState.error = 'Display name is required.'
    return
  }

  authState.submitting = true

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

    if (response.ok) {
      const payload = await response.json()
      currentSession.value = buildSessionFromLogin(payload, {
        userId: currentSession.value?.userId || null,
        name,
        color: authForm.color,
      })
    } else {
      currentSession.value = buildSessionFromLogin(null, {
        userId: currentSession.value?.userId || null,
        name,
        color: authForm.color,
      })
    }
  } catch {
    currentSession.value = buildSessionFromLogin(null, {
      userId: currentSession.value?.userId || null,
      name,
      color: authForm.color,
    })
  } finally {
    authState.submitting = false
  }

  if (!currentSession.value) {
    authState.error = 'Sign-in failed. Please retry.'
    return
  }

  authForm.name = currentSession.value.name
  authForm.color = currentSession.value.color
  persistSession()
}

function signOut(): void {
  authState.error = ''
  authState.submitting = false
  reconnectSuppressed = true

  if (typingDebounce) {
    clearTimeout(typingDebounce)
    typingDebounce = null
  }

  emit('typing/stop', { roomId: activeRoomId.value })
  callReset(true)
  stopLocalStream()

  if (ws.value) {
    const socket = ws.value
    ws.value = null
    socket.close()
  }

  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }

  reconnectAttempt = 0
  activeTab.value = 'chat'
  createRoomName.value = ''
  createRoomState.submitting = false
  createRoomState.error = ''
  createInviteState.submitting = false
  createInviteState.error = ''
  redeemInviteState.submitting = false
  redeemInviteState.error = ''
  resetCopyInviteStatus()
  inviteCodeInput.value = ''
  lastInviteCode.value = ''
  messageInput.value = ''
  isMicEnabled.value = true
  isCameraEnabled.value = true
  activeRoomId.value = 'lobby'
  rooms.value = []

  for (const roomId of Object.keys(messagesByRoom)) {
    delete messagesByRoom[roomId]
  }

  for (const roomId of Object.keys(participantsByRoom)) {
    delete participantsByRoom[roomId]
  }

  for (const roomId of Object.keys(participantSnapshotAtByRoom)) {
    delete participantSnapshotAtByRoom[roomId]
  }

  for (const roomId of Object.keys(typingByRoom)) {
    delete typingByRoom[roomId]
  }

  for (const roomId of Object.keys(typingStateByRoom)) {
    delete typingStateByRoom[roomId]
  }

  authForm.name = ''
  authForm.color = DEFAULT_ACCENT_COLOR
  syncLocationRoom('lobby')

  currentSession.value = null
  persistSession()
  connectionState.value = 'offline'
}

watch(isAuthenticated, (value) => {
  if (!value) {
    reconnectSuppressed = true
    return
  }

  reconnectSuppressed = false
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
  if (!typingSweepTimer) {
    typingSweepTimer = setInterval(() => {
      sweepTypingUsers()
    }, TYPING_SWEEP_INTERVAL_MS)
  }

  const session = authenticatedSession()
  if (session) {
    authForm.name = session.name
    authForm.color = session.color
    reconnectSuppressed = false
    connectSocket()
    await refreshRooms()
  }
})

onUnmounted(() => {
  reconnectSuppressed = true
  callReset(false)
  stopLocalStream()

  if (ws.value) {
    ws.value.close()
  }

  if (typingDebounce) {
    clearTimeout(typingDebounce)
    typingDebounce = null
  }

  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }

  if (copyInviteResetTimer) {
    clearTimeout(copyInviteResetTimer)
    copyInviteResetTimer = null
  }

  if (typingSweepTimer) {
    clearInterval(typingSweepTimer)
    typingSweepTimer = null
  }
})
</script>

<style scoped>
.app-root {
  min-height: 100vh;
  color: var(--king-text);
  font-size: var(--king-font-size-400);
  line-height: var(--king-line-height-body);
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
  font-size: var(--king-font-size-600);
  line-height: var(--king-line-height-tight);
  font-weight: var(--king-font-weight-semibold);
}

.auth-card p {
  margin: var(--king-space-dense) 0 var(--king-space-5);
  color: var(--king-muted);
  font-size: var(--king-font-size-250);
}

.auth-form {
  display: grid;
  gap: var(--king-space-panel);
}

.auth-error {
  margin: 0;
  color: var(--king-color-danger);
  font-size: var(--king-font-size-200);
}

.auth-form label {
  display: grid;
  gap: var(--king-space-sm);
  font-size: var(--king-font-size-300);
  font-weight: var(--king-font-weight-medium);
}

.auth-form input,
.inline-form input,
.composer input {
  border: var(--king-border-default);
  border-radius: var(--king-radius-1);
  padding: var(--king-space-input-y) var(--king-space-input-x);
  min-height: var(--king-control-height);
  background: var(--king-color-bg-surface);
  color: var(--king-text);
  font-size: var(--king-control-font-size);
  line-height: var(--king-line-height-tight);
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
  min-height: var(--king-control-height);
  cursor: pointer;
  font-size: var(--king-control-font-size);
  font-weight: var(--king-control-font-weight);
  line-height: var(--king-line-height-tight);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: transform var(--king-motion-fast) var(--king-motion-ease), border-color var(--king-motion-fast) var(--king-motion-ease), background-color var(--king-motion-fast) var(--king-motion-ease);
}

.auth-form button,
.inline-form button,
.composer button,
.prejoin-actions button {
  background: var(--king-accent);
  border-color: var(--king-accent);
  color: var(--king-color-on-accent);
  font-weight: var(--king-font-weight-semibold);
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
  font-size: var(--king-font-size-150);
}

.rail-section h2,
.context-section h3 {
  margin: 0;
  font-size: var(--king-font-size-400);
  line-height: var(--king-line-height-tight);
  font-weight: var(--king-font-weight-semibold);
}

.inline-form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--king-space-tight);
  margin-top: var(--king-space-2);
}

.inline-error {
  margin: var(--king-space-sm) 0 0;
  color: var(--king-color-danger);
  font-size: var(--king-font-size-150);
}

.inline-note {
  margin: var(--king-space-sm) 0 0;
  color: var(--king-muted);
  font-size: var(--king-font-size-150);
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
  font-size: var(--king-font-size-150);
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
  font-size: var(--king-font-size-500);
  line-height: var(--king-line-height-tight);
  font-weight: var(--king-font-weight-semibold);
}

.stage-header p {
  margin: var(--king-space-xs) 0 0;
  color: var(--king-muted);
  font-size: var(--king-font-size-250);
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
  font-size: var(--king-font-size-100);
  line-height: var(--king-line-height-tight);
  color: var(--king-muted);
}

.message p {
  margin: var(--king-space-1) 0 0;
  font-size: var(--king-font-size-200);
  line-height: var(--king-line-height-body);
}

.typing-line {
  color: var(--king-muted);
  font-size: var(--king-font-size-150);
}

.composer {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--king-space-2);
}

.prejoin {
  height: 100%;
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto auto;
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
  gap: var(--king-space-tight);
  flex-wrap: wrap;
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
  font-size: var(--king-font-size-150);
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
  font-size: var(--king-font-size-100);
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
  font-size: var(--king-font-size-200);
}

.context-section dt {
  color: var(--king-muted);
}

.slide-panel-enter-active,
.slide-panel-leave-active {
  transition:
    transform var(--king-motion-stage-duration) var(--king-motion-stage-ease),
    opacity var(--king-motion-stage-duration) var(--king-motion-stage-ease);
  will-change: transform, opacity;
}

.slide-panel-enter-from {
  opacity: var(--king-motion-stage-fade-start);
  transform: translateX(var(--king-motion-stage-shift));
}

.slide-panel-enter-to,
.slide-panel-leave-from {
  opacity: 1;
  transform: translateX(0);
}

.slide-panel-leave-to {
  opacity: var(--king-motion-stage-fade-start);
  transform: translateX(calc(var(--king-motion-stage-shift) * -1));
}

@media (prefers-reduced-motion: reduce) {
  .slide-panel-enter-active,
  .slide-panel-leave-active {
    transition: none !important;
  }

  .slide-panel-enter-from,
  .slide-panel-enter-to,
  .slide-panel-leave-from,
  .slide-panel-leave-to {
    opacity: 1 !important;
    transform: none !important;
  }

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
