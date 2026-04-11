import crypto from 'node:crypto'
import http from 'node:http'
import cors from 'cors'
import express from 'express'
import { WebSocketServer } from 'ws'

const host = process.env.KING_DEMO_HOST || '127.0.0.1'
const port = Number(process.env.KING_DEMO_PORT || 8080)

const app = express()
app.use(cors())
app.use(express.json({ limit: '1mb' }))

const server = http.createServer(app)
const wss = new WebSocketServer({ noServer: true })

/** @type {Map<string, {id: string, name: string, inviteCode: string, createdAt: number, members: Set<WebSocket>}>} */
const rooms = new Map()
/** @type {Map<WebSocket, {userId: string, name: string, roomId: string, callJoined: boolean, connectedAt: number}>} */
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
    .sort((a, b) => a.name.localeCompare(b.name))
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
    socket.send(JSON.stringify(message))
    return true
  } catch {
    return false
  }
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
}

function closePeer(socket) {
  const state = clients.get(socket)
  if (!state) {
    return
  }

  clients.delete(socket)
  socketsByUser.delete(state.userId)

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
  }
}

function handleClientMessage(socket, payload) {
  const state = clients.get(socket)
  if (!state) {
    return
  }

  let message
  try {
    message = JSON.parse(payload)
  } catch {
    send(socket, { type: 'error', code: 'invalid_json', message: 'Message must be valid JSON.' })
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
      if (target) {
        send(target, signalEvent)
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
    time: nowIso(),
  })
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

  if (requestUrl.pathname !== '/ws') {
    socket.destroy()
    return
  }

  wss.handleUpgrade(request, socket, head, (ws) => {
    wss.emit('connection', ws, request, requestUrl)
  })
})

wss.on('connection', (socket, _request, requestUrl) => {
  const userId = normalizeName(requestUrl.searchParams.get('userId'), `u-${crypto.randomUUID().slice(0, 8)}`)
  const userName = normalizeName(requestUrl.searchParams.get('name'), userId)
  const roomId = normalizeRoomId(requestUrl.searchParams.get('room') || 'lobby')

  const state = {
    userId,
    name: userName,
    roomId,
    callJoined: false,
    connectedAt: Date.now(),
  }

  clients.set(socket, state)
  socketsByUser.set(userId, socket)
  ensureRoom(roomId).members.add(socket)

  send(socket, {
    type: 'session/ready',
    me: { userId, name: userName },
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

  socket.on('message', (raw, isBinary) => {
    if (isBinary) {
      return
    }
    handleClientMessage(socket, String(raw))
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
