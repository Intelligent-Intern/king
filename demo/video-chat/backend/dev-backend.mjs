import crypto from 'node:crypto'
import fs from 'node:fs'
import http from 'node:http'
import path from 'node:path'
import Database from 'better-sqlite3'
import cors from 'cors'
import express from 'express'
import { WebSocketServer } from 'ws'
import { IIBINDecoder, IIBINEncoder, MessageType } from '@intelligentintern/iibin'

const host = process.env.KING_DEMO_HOST || '127.0.0.1'
const port = Number(process.env.KING_DEMO_PORT || 8080)
const dbPath = process.env.KING_DEMO_DB_PATH || path.join(process.cwd(), '.data', 'video-chat.sqlite')
const sessionTokenTtlMs = Math.max(60_000, Number(process.env.KING_DEMO_SESSION_TOKEN_TTL_MS || 86_400_000))
const sessionTokenSecret = process.env.KING_DEMO_SESSION_SECRET || crypto.randomBytes(32).toString('hex')

const app = express()
app.use(cors())
app.use(express.json({ limit: '1mb' }))

const server = http.createServer(app)
const wss = new WebSocketServer({ noServer: true })
const wireEncoder = new IIBINEncoder()

fs.mkdirSync(path.dirname(dbPath), { recursive: true })
const db = new Database(dbPath)
db.pragma('journal_mode = WAL')
db.exec(`
  CREATE TABLE IF NOT EXISTS users (
    user_id TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    display_color TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    last_login_at INTEGER NOT NULL,
    login_count INTEGER NOT NULL DEFAULT 1
  )
`)

const upsertUserStatement = db.prepare(`
  INSERT INTO users (
    user_id,
    display_name,
    display_color,
    created_at,
    last_login_at,
    login_count
  ) VALUES (
    @user_id,
    @display_name,
    @display_color,
    @created_at,
    @last_login_at,
    1
  )
  ON CONFLICT(user_id) DO UPDATE SET
    display_name = excluded.display_name,
    display_color = excluded.display_color,
    last_login_at = excluded.last_login_at,
    login_count = users.login_count + 1
`)
const findUserByIdStatement = db.prepare(`
  SELECT
    user_id AS userId,
    display_name AS name,
    display_color AS color,
    created_at AS createdAt,
    last_login_at AS lastLoginAt,
    login_count AS loginCount
  FROM users
  WHERE user_id = ?
`)
const listUsersStatement = db.prepare(`
  SELECT
    user_id AS userId,
    display_name AS name,
    display_color AS color,
    created_at AS createdAt,
    last_login_at AS lastLoginAt,
    login_count AS loginCount
  FROM users
  ORDER BY last_login_at DESC
  LIMIT 200
`)

/** @type {Map<string, {id: string, name: string, inviteCode: string, createdAt: number, members: Set<WebSocket>}>} */
const rooms = new Map()
/** @type {Map<WebSocket, {userId: string, name: string, color: string, roomId: string, callJoined: boolean, connectedAt: number}>} */
const clients = new Map()
/** @type {Map<string, WebSocket>} */
const socketsByUser = new Map()

function nowIso() {
  return new Date().toISOString()
}

function log(message) {
  process.stdout.write(`[${nowIso()}] ${message}\n`)
}

function normalizeRoomId(value) {
  const input = typeof value === 'string' ? value.trim().toLowerCase() : ''
  if (!input) {
    return 'lobby'
  }
  const safe = input.replace(/[^a-z0-9-_]/g, '-')
  return safe.slice(0, 48) || 'lobby'
}

function normalizeName(value, fallback) {
  const input = typeof value === 'string' ? value.trim() : ''
  if (!input) {
    return fallback
  }
  return input.slice(0, 48)
}

function normalizeColor(value) {
  const input = typeof value === 'string' ? value.trim().toLowerCase() : ''
  if (/^#[0-9a-f]{6}$/.test(input)) {
    return input
  }
  return '#0f62fe'
}

function normalizeUserId(value) {
  const safe = sanitizeUserId(value)
  if (!safe) {
    return `u-${crypto.randomUUID().slice(0, 8)}`
  }

  return safe
}

function sanitizeUserId(value) {
  const input = typeof value === 'string' ? value.trim().toLowerCase() : ''
  if (!input) {
    return ''
  }
  const safe = input.replace(/[^a-z0-9-_]/g, '-').slice(0, 48)
  return safe || ''
}

function normalizeSessionToken(value) {
  if (typeof value !== 'string') {
    return ''
  }
  return value.trim()
}

function encodeTokenPayload(payload) {
  return Buffer.from(payload, 'utf8').toString('base64url')
}

function decodeTokenPayload(encodedPayload) {
  if (typeof encodedPayload !== 'string' || encodedPayload === '') {
    return null
  }

  try {
    return Buffer.from(encodedPayload, 'base64url').toString('utf8')
  } catch {
    return null
  }
}

function signSessionTokenPayload(encodedPayload) {
  return crypto
    .createHmac('sha256', sessionTokenSecret)
    .update(encodedPayload)
    .digest('base64url')
}

function issueSessionToken(userId, nowMs = Date.now()) {
  const expiresAt = nowMs + sessionTokenTtlMs
  const payload = JSON.stringify({
    userId,
    issuedAt: nowMs,
    expiresAt,
  })
  const encodedPayload = encodeTokenPayload(payload)
  const signature = signSessionTokenPayload(encodedPayload)

  return {
    token: `${encodedPayload}.${signature}`,
    expiresAt,
  }
}

function verifySessionToken(token, expectedUserId = null, nowMs = Date.now()) {
  const normalizedToken = normalizeSessionToken(token)
  if (normalizedToken === '') {
    return { valid: false, reason: 'missing_token' }
  }

  const parts = normalizedToken.split('.')
  if (parts.length !== 2) {
    return { valid: false, reason: 'malformed_token' }
  }

  const [encodedPayload, encodedSignature] = parts
  if (encodedPayload === '' || encodedSignature === '') {
    return { valid: false, reason: 'malformed_token' }
  }

  const expectedSignature = signSessionTokenPayload(encodedPayload)
  const expectedBuffer = Buffer.from(expectedSignature)
  const providedBuffer = Buffer.from(encodedSignature)
  if (
    expectedBuffer.length !== providedBuffer.length
    || !crypto.timingSafeEqual(expectedBuffer, providedBuffer)
  ) {
    return { valid: false, reason: 'invalid_signature' }
  }

  const decodedPayload = decodeTokenPayload(encodedPayload)
  if (!decodedPayload) {
    return { valid: false, reason: 'invalid_payload_encoding' }
  }

  let parsedPayload = null
  try {
    parsedPayload = JSON.parse(decodedPayload)
  } catch {
    return { valid: false, reason: 'invalid_payload_json' }
  }

  const tokenUserId = sanitizeUserId(parsedPayload?.userId)
  const expiresAt = Number(parsedPayload?.expiresAt || 0)
  if (tokenUserId === '' || !Number.isFinite(expiresAt) || expiresAt <= nowMs) {
    return { valid: false, reason: 'expired_or_invalid' }
  }

  if (expectedUserId && tokenUserId !== expectedUserId) {
    return { valid: false, reason: 'user_mismatch' }
  }

  return {
    valid: true,
    userId: tokenUserId,
    expiresAt,
  }
}

function upsertUser(userId, name, color) {
  const timestamp = Date.now()
  upsertUserStatement.run({
    user_id: userId,
    display_name: name,
    display_color: color,
    created_at: timestamp,
    last_login_at: timestamp,
  })
  return findUserByIdStatement.get(userId)
}

function ensureRoom(roomId, roomName = null) {
  const id = normalizeRoomId(roomId)
  if (!rooms.has(id)) {
    const base = (roomName || id).trim()
    const name = base ? base.slice(0, 48) : id
    rooms.set(id, {
      id,
      name,
      inviteCode: id,
      createdAt: Date.now(),
      members: new Set(),
    })
  }
  return rooms.get(id)
}

function listRooms() {
  return [...rooms.values()]
    .map((room) => ({
      id: room.id,
      name: room.name,
      inviteCode: room.inviteCode,
      memberCount: room.members.size,
      createdAt: room.createdAt,
    }))
    .sort(compareRoomDirectoryEntry)
}

function compareRoomDirectoryEntry(a, b) {
  if (a.id === 'lobby' && b.id !== 'lobby') {
    return -1
  }
  if (b.id === 'lobby' && a.id !== 'lobby') {
    return 1
  }

  const nameOrder = a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true })
  if (nameOrder !== 0) {
    return nameOrder
  }

  if (a.createdAt !== b.createdAt) {
    return a.createdAt - b.createdAt
  }

  return a.id.localeCompare(b.id, undefined, { sensitivity: 'base', numeric: true })
}

function participantsForRoom(roomId) {
  const room = rooms.get(roomId)
  if (!room) {
    return []
  }

  const participants = []
  for (const socket of room.members) {
    const state = clients.get(socket)
    if (!state) {
      continue
    }
    participants.push({
      userId: state.userId,
      name: state.name,
      roomId: state.roomId,
      callJoined: state.callJoined,
      connectedAt: state.connectedAt,
    })
  }

  participants.sort((a, b) => a.name.localeCompare(b.name))
  return participants
}

function send(socket, message) {
  if (socket.readyState !== socket.OPEN) {
    return false
  }

  try {
    const wirePayload = wireEncoder.encode({
      type: MessageType.TEXT_MESSAGE,
      data: message,
      timestamp: Date.now(),
    })
    socket.send(Buffer.from(new Uint8Array(wirePayload)))
    return true
  } catch {
    return false
  }
}

function decodeSocketPayload(raw, isBinary) {
  if (!isBinary) {
    try {
      return JSON.parse(String(raw))
    } catch {
      return null
    }
  }

  let binaryPayload = raw
  if (Array.isArray(binaryPayload)) {
    binaryPayload = Buffer.concat(binaryPayload)
  }

  if (!(binaryPayload instanceof ArrayBuffer) && !Buffer.isBuffer(binaryPayload)) {
    return null
  }

  const buffer = binaryPayload instanceof ArrayBuffer
    ? binaryPayload
    : binaryPayload.buffer.slice(
      binaryPayload.byteOffset,
      binaryPayload.byteOffset + binaryPayload.byteLength
    )

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

function broadcastRoom(roomId, message, excludeUserId = null) {
  const room = rooms.get(roomId)
  if (!room) {
    return
  }

  for (const socket of room.members) {
    const state = clients.get(socket)
    if (!state) {
      continue
    }
    if (excludeUserId !== null && state.userId === excludeUserId) {
      continue
    }
    send(socket, message)
  }
}

function pushRoomSnapshot(roomId) {
  const room = rooms.get(roomId)
  if (!room) {
    return
  }

  const snapshot = {
    type: 'room/snapshot',
    roomId,
    participants: participantsForRoom(roomId),
    serverTime: Date.now(),
  }

  for (const socket of room.members) {
    send(socket, snapshot)
  }
}

function pushRoomDirectory() {
  const directory = {
    type: 'rooms/directory',
    rooms: listRooms(),
    serverTime: Date.now(),
  }

  for (const socket of clients.keys()) {
    send(socket, directory)
  }
}

function joinRoom(socket, nextRoomId) {
  const state = clients.get(socket)
  if (!state) {
    return
  }

  const currentRoom = rooms.get(state.roomId)
  if (currentRoom) {
    currentRoom.members.delete(socket)
    broadcastRoom(currentRoom.id, {
      type: 'presence/leave',
      roomId: currentRoom.id,
      user: { userId: state.userId, name: state.name },
      serverTime: Date.now(),
    }, state.userId)
    pushRoomSnapshot(currentRoom.id)
  }

  const targetRoom = ensureRoom(nextRoomId)
  targetRoom.members.add(socket)
  state.roomId = targetRoom.id
  state.callJoined = false

  send(socket, {
    type: 'room/switched',
    roomId: targetRoom.id,
    roomName: targetRoom.name,
    inviteCode: targetRoom.inviteCode,
    serverTime: Date.now(),
  })

  broadcastRoom(targetRoom.id, {
    type: 'presence/join',
    roomId: targetRoom.id,
    user: { userId: state.userId, name: state.name },
    serverTime: Date.now(),
  }, state.userId)

  pushRoomSnapshot(targetRoom.id)
  pushRoomDirectory()
}

function closePeer(socket) {
  const state = clients.get(socket)
  if (!state) {
    return
  }

  clients.delete(socket)
  const mappedSocket = socketsByUser.get(state.userId)
  if (mappedSocket === socket) {
    socketsByUser.delete(state.userId)
  }

  const room = rooms.get(state.roomId)
  if (room) {
    room.members.delete(socket)
    broadcastRoom(room.id, {
      type: 'presence/leave',
      roomId: room.id,
      user: { userId: state.userId, name: state.name },
      serverTime: Date.now(),
    }, state.userId)
    pushRoomSnapshot(room.id)
    pushRoomDirectory()
  }
}

function handleClientMessage(socket, message) {
  const state = clients.get(socket)
  if (!state) {
    return
  }

  const type = typeof message.type === 'string' ? message.type : ''
  if (!type) {
    send(socket, { type: 'error', code: 'missing_type', message: 'Message is missing type.' })
    return
  }

  if (type === 'room/switch') {
    joinRoom(socket, normalizeRoomId(message.roomId))
    return
  }

  if (type === 'chat/send') {
    const text = typeof message.text === 'string' ? message.text.trim() : ''
    if (!text) {
      return
    }

    broadcastRoom(state.roomId, {
      type: 'chat/message',
      id: crypto.randomUUID(),
      roomId: state.roomId,
      sender: { userId: state.userId, name: state.name },
      text: text.slice(0, 4000),
      serverTime: Date.now(),
    })
    return
  }

  if (type === 'typing/start' || type === 'typing/stop') {
    broadcastRoom(state.roomId, {
      type,
      roomId: state.roomId,
      user: { userId: state.userId, name: state.name },
      serverTime: Date.now(),
    }, state.userId)
    return
  }

  if (type === 'call/join') {
    state.callJoined = true
    pushRoomSnapshot(state.roomId)
    broadcastRoom(state.roomId, {
      type: 'call/joined',
      roomId: state.roomId,
      user: { userId: state.userId, name: state.name },
      serverTime: Date.now(),
    }, state.userId)
    return
  }

  if (type === 'call/leave') {
    state.callJoined = false
    pushRoomSnapshot(state.roomId)
    broadcastRoom(state.roomId, {
      type: 'call/left',
      roomId: state.roomId,
      user: { userId: state.userId, name: state.name },
      serverTime: Date.now(),
    }, state.userId)
    return
  }

  if (type === 'call/offer' || type === 'call/answer' || type === 'call/ice' || type === 'call/hangup') {
    const targetUserId = typeof message.targetUserId === 'string' ? message.targetUserId.trim() : ''
    const requiresTarget = type === 'call/offer' || type === 'call/answer' || type === 'call/ice'

    if (requiresTarget && !targetUserId) {
      send(socket, {
        type: 'error',
        code: 'target_required',
        message: `${type} requires a targetUserId.`,
      })
      return
    }

    const signalEvent = {
      type,
      roomId: state.roomId,
      sender: { userId: state.userId, name: state.name },
      targetUserId: targetUserId || null,
      payload: message.payload ?? null,
      serverTime: Date.now(),
    }

    if (targetUserId) {
      const target = socketsByUser.get(targetUserId)
      const targetState = target ? clients.get(target) : null
      if (target && targetState && targetState.roomId === state.roomId) {
        send(target, signalEvent)
      } else {
        send(socket, {
          type: 'error',
          code: 'target_unavailable',
          message: `Target peer '${targetUserId}' is not available in this room.`,
        })
      }
      return
    }

    broadcastRoom(state.roomId, signalEvent, state.userId)
    return
  }

  send(socket, {
    type: 'error',
    code: 'unsupported_type',
    message: `Unsupported message type: ${type}`,
  })
}

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'king-video-chat-backend',
    rooms: rooms.size,
    peers: clients.size,
    users: Number(db.prepare('SELECT COUNT(*) AS count FROM users').get().count || 0),
    time: nowIso(),
  })
})

app.post('/api/auth/login', (req, res) => {
  const name = normalizeName(req.body?.name, '')
  if (!name) {
    res.status(400).json({ error: 'name_required' })
    return
  }

  const color = normalizeColor(req.body?.color)
  const requestedUserId = sanitizeUserId(req.body?.userId)
  const providedToken = normalizeSessionToken(req.body?.token)
  const verifiedToken = verifySessionToken(providedToken, requestedUserId || null)
  const userId = verifiedToken.valid && verifiedToken.userId
    ? verifiedToken.userId
    : normalizeUserId('')
  const record = upsertUser(userId, name, color)
  const issuedSession = issueSessionToken(record.userId)

  res.json({
    session: {
      userId: record.userId,
      name: record.name,
      color: record.color,
      token: issuedSession.token,
      tokenExpiresAt: issuedSession.expiresAt,
      lastLoginAt: Number(record.lastLoginAt || Date.now()),
      loginCount: Number(record.loginCount || 1),
    },
  })
})

app.get('/api/users', (_req, res) => {
  res.json({ users: listUsersStatement.all() })
})

app.get('/api/rooms', (_req, res) => {
  res.json({ rooms: listRooms() })
})

app.post('/api/rooms', (req, res) => {
  const name = normalizeName(req.body?.name, 'Room')
  const roomId = normalizeRoomId(req.body?.id || `room-${crypto.randomUUID().slice(0, 8)}`)

  if (rooms.has(roomId)) {
    res.status(409).json({ error: 'room_exists', roomId })
    return
  }

  const room = ensureRoom(roomId, name)
  pushRoomDirectory()
  res.status(201).json({ room: { id: room.id, name: room.name, inviteCode: room.inviteCode } })
})

app.post('/api/rooms/:roomId/invite', (req, res) => {
  const roomId = normalizeRoomId(req.params.roomId)
  const room = rooms.get(roomId)

  if (!room) {
    res.status(404).json({ error: 'room_not_found' })
    return
  }

  res.json({
    room: { id: room.id, name: room.name },
    inviteCode: room.inviteCode,
    inviteUrl: `${req.protocol}://${req.get('host')}/?room=${encodeURIComponent(room.inviteCode)}`,
  })
})

app.post('/api/invite/redeem', (req, res) => {
  const inviteCode = normalizeRoomId(req.body?.code)
  const room = rooms.get(inviteCode)

  if (!room) {
    res.status(404).json({ error: 'invite_not_found' })
    return
  }

  res.json({ room: { id: room.id, name: room.name, inviteCode: room.inviteCode } })
})

server.on('upgrade', (request, socket, head) => {
  const hostHeader = request.headers.host || `${host}:${port}`
  const requestUrl = new URL(request.url || '/', `http://${hostHeader}`)
  const normalizedPath = requestUrl.pathname.replace(/\/+$/, '') || '/'

  if (normalizedPath !== '/ws') {
    socket.destroy()
    return
  }

  wss.handleUpgrade(request, socket, head, (ws) => {
    wss.emit('connection', ws, request, requestUrl)
  })
})

wss.on('connection', (socket, _request, requestUrl) => {
  const requestedUserId = sanitizeUserId(requestUrl.searchParams.get('userId'))
  const verifiedToken = verifySessionToken(requestUrl.searchParams.get('token'), requestedUserId || null)
  if (!verifiedToken.valid || !verifiedToken.userId) {
    socket.close(1008, 'unauthorized')
    return
  }

  const userId = verifiedToken.userId
  const fallbackName = normalizeName(requestUrl.searchParams.get('name'), `user-${userId.slice(0, 8)}`)
  const fallbackColor = normalizeColor(requestUrl.searchParams.get('color'))
  const knownUser = findUserByIdStatement.get(userId)
  if (!knownUser) {
    socket.close(1008, 'unknown_user')
    return
  }

  const userName = knownUser ? String(knownUser.name || fallbackName) : fallbackName
  const userColor = knownUser ? String(knownUser.color || fallbackColor) : fallbackColor
  const roomId = normalizeRoomId(requestUrl.searchParams.get('room') || 'lobby')

  const existingSocket = socketsByUser.get(userId)
  if (existingSocket && existingSocket !== socket) {
    send(existingSocket, {
      type: 'session/replaced',
      user: { userId, name: userName },
      serverTime: Date.now(),
    })
    existingSocket.close(4001, 'session_replaced')
  }

  const state = {
    userId,
    name: userName,
    color: userColor,
    roomId,
    callJoined: false,
    connectedAt: Date.now(),
  }

  clients.set(socket, state)
  socketsByUser.set(userId, socket)
  ensureRoom(roomId).members.add(socket)

  send(socket, {
    type: 'session/ready',
    me: { userId, name: userName, color: userColor },
    roomId,
    rooms: listRooms(),
    serverTime: Date.now(),
  })

  broadcastRoom(roomId, {
    type: 'presence/join',
    roomId,
    user: { userId, name: userName },
    serverTime: Date.now(),
  }, userId)

  pushRoomSnapshot(roomId)
  pushRoomDirectory()

  socket.on('message', (raw, isBinary) => {
    const message = decodeSocketPayload(raw, isBinary)
    if (!message || typeof message !== 'object') {
      send(socket, {
        type: 'error',
        code: 'invalid_payload',
        message: 'Message must be valid JSON or IIBIN payload.',
      })
      return
    }
    handleClientMessage(socket, message)
  })

  socket.on('close', () => {
    closePeer(socket)
  })

  socket.on('error', () => {
    closePeer(socket)
  })
})

ensureRoom('lobby', 'Lobby')

server.listen(port, host, () => {
  log(`Video-chat backend listening on http://${host}:${port}`)
})
