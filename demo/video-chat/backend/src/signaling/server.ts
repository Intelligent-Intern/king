import type { WebSocket, WebSocketServer } from 'ws'
import type { IncomingMessage } from 'http'
import { URL } from 'url'
import { v4 as uuidv4 } from 'uuid'
import { verifyToken } from '../auth/jwt.js'
import { getDb } from '../db/client.js'
import { IIBINEncoder, IIBINDecoder, MessageType } from '@intelligentintern/iibin'

interface ClientState {
  userId: string
  email: string
  name: string
  color: string
  roomId: string
  callJoined: boolean
  connectedAt: number
  socket: WebSocket
  messagesSent: number
  messagesReceived: number
  bytesSent: number
  bytesReceived: number
}

interface RoomState {
  id: string
  name: string
  inviteCode: string
  createdAt: number
  members: Set<WebSocket>
  settings: Record<string, unknown>
  callJoinCount: number
  messageCount: number
}

interface Metrics {
  totalConnections: number
  totalMessages: number
  totalBytes: number
  totalCallJoins: number
  roomsCreated: number
  peakRooms: number
  peakClientsInRoom: number
  uptime: number
}

const clients = new Map<WebSocket, ClientState>()
const rooms = new Map<string, RoomState>()
const socketsByUser = new Map<string, WebSocket>()
const wireEncoder = new IIBINEncoder()
const serverStartTime = Date.now()

const metrics = {
  totalConnections: 0,
  totalMessages: 0,
  totalBytes: 0,
  totalCallJoins: 0,
  peakRooms: 0,
  peakClientsInRoom: 0,
}

function now(): number {
  return Date.now()
}

function sanitizeRoomId(input: string): string {
  const cleaned = input.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return cleaned.slice(0, 48) || 'lobby'
}

function sanitizeUserId(input: string): string {
  const cleaned = input.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return cleaned.slice(0, 48)
}

function getRoom(roomId: string): RoomState | undefined {
  return rooms.get(roomId)
}

function ensureRoom(roomId: string, name?: string): RoomState {
  const id = sanitizeRoomId(roomId)
  
  if (!rooms.has(id)) {
    rooms.set(id, {
      id,
      name: (name || id).slice(0, 48),
      inviteCode: uuidv4().slice(0, 8).toUpperCase(),
      createdAt: now(),
      members: new Set(),
      settings: {},
      callJoinCount: 0,
      messageCount: 0,
    })
  }
  
  return rooms.get(id)!
}

function send(ws: WebSocket, message: object): boolean {
  if (ws.readyState !== ws.OPEN) return false
  
  try {
    const wirePayload = wireEncoder.encode({
      type: MessageType.TEXT_MESSAGE,
      data: message,
      timestamp: now(),
    })
    const buffer = Buffer.from(new Uint8Array(wirePayload))
    ws.send(buffer)
    
    const state = clients.get(ws)
    if (state) {
      state.messagesSent++
      state.bytesSent += buffer.length
    }
    metrics.totalBytes += buffer.length
    
    return true
  } catch {
    return false
  }
}

function broadcast(roomId: string, message: object, excludeUserId?: string): void {
  const room = getRoom(roomId)
  if (!room) return

  for (const socket of room.members) {
    const state = clients.get(socket)
    if (excludeUserId && state?.userId === excludeUserId) continue
    send(socket, message)
  }
}

function pushRoomSnapshot(roomId: string): void {
  const room = getRoom(roomId)
  if (!room) return

  const participants = [...room.members].map(socket => {
    const state = clients.get(socket)
    return state ? {
      userId: state.userId,
      name: state.name,
      roomId: state.roomId,
      callJoined: state.callJoined,
      connectedAt: state.connectedAt,
    } : null
  }).filter(Boolean)

  for (const socket of room.members) {
    send(socket, {
      type: 'room/snapshot',
      roomId,
      participants,
      serverTime: now(),
    })
  }
}

function getParticipants(roomId: string) {
  const room = getRoom(roomId)
  if (!room) return []
  
  return [...room.members].map(socket => {
    const state = clients.get(socket)
    return state ? { userId: state.userId, name: state.name } : null
  }).filter(Boolean)
}

function handleJoinRoom(ws: WebSocket, nextRoomId: string): void {
  const state = clients.get(ws)
  if (!state) return

  const currentRoom = getRoom(state.roomId)
  if (currentRoom) {
    currentRoom.members.delete(ws)
    broadcast(currentRoom.id, {
      type: 'presence/leave',
      roomId: currentRoom.id,
      user: { userId: state.userId, name: state.name },
      serverTime: now(),
    }, state.userId)
    pushRoomSnapshot(currentRoom.id)
  }

  const targetRoom = ensureRoom(nextRoomId)
  targetRoom.members.add(ws)
  state.roomId = targetRoom.id
  state.callJoined = false

  send(ws, {
    type: 'room/switched',
    roomId: targetRoom.id,
    roomName: targetRoom.name,
    inviteCode: targetRoom.inviteCode,
    serverTime: now(),
  })

  broadcast(targetRoom.id, {
    type: 'presence/join',
    roomId: targetRoom.id,
    user: { userId: state.userId, name: state.name },
    serverTime: now(),
  }, state.userId)

  pushRoomSnapshot(targetRoom.id)
}

function handleMessage(ws: WebSocket, message: Record<string, unknown>): void {
  const state = clients.get(ws)
  if (!state) return

  const type = String(message.type || '')

  switch (type) {
    case 'room/switch':
      handleJoinRoom(ws, sanitizeRoomId(String(message.roomId || 'lobby')))
      break

    case 'chat/send': {
      const text = String(message.text || '').trim().slice(0, 4000)
      if (!text) break
      broadcast(state.roomId, {
        type: 'chat/message',
        id: uuidv4(),
        roomId: state.roomId,
        sender: { userId: state.userId, name: state.name },
        text,
        serverTime: now(),
      })
      break
    }

    case 'typing/start':
    case 'typing/stop':
      broadcast(state.roomId, {
        type,
        roomId: state.roomId,
        user: { userId: state.userId, name: state.name },
        serverTime: now(),
      }, state.userId)
      break

    case 'call/join':
      state.callJoined = true
      metrics.totalCallJoins++
      const room = getRoom(state.roomId)
      if (room) {
        room.callJoinCount++
      }
      pushRoomSnapshot(state.roomId)
      broadcast(state.roomId, {
        type: 'call/joined',
        roomId: state.roomId,
        user: { userId: state.userId, name: state.name },
        serverTime: now(),
      }, state.userId)
      break

    case 'call/leave':
      state.callJoined = false
      pushRoomSnapshot(state.roomId)
      broadcast(state.roomId, {
        type: 'call/left',
        roomId: state.roomId,
        user: { userId: state.userId, name: state.name },
        serverTime: now(),
      }, state.userId)
      break

    case 'call/offer':
    case 'call/answer':
    case 'call/ice':
    case 'call/hangup': {
      const targetUserId = sanitizeUserId(String(message.targetUserId || ''))
      const requiresTarget = type !== 'call/hangup'

      if (requiresTarget && !targetUserId) {
        send(ws, { type: 'error', code: 'target_required', message: `${type} requires a targetUserId.` })
        break
      }

      const signalEvent = {
        type,
        roomId: state.roomId,
        sender: { userId: state.userId, name: state.name },
        targetUserId: targetUserId || null,
        payload: message.payload ?? null,
        serverTime: now(),
      }

      if (targetUserId) {
        const target = socketsByUser.get(targetUserId)
        const targetState = target ? clients.get(target) : null
        if (target && targetState && targetState.roomId === state.roomId) {
          send(target, signalEvent)
        } else {
          send(ws, { type: 'error', code: 'target_unavailable', message: `Target peer '${targetUserId}' is not available.` })
        }
      } else {
        broadcast(state.roomId, signalEvent, state.userId)
      }
      break
    }

    default:
      send(ws, { type: 'error', code: 'unsupported_type', message: `Unsupported message type: ${type}` })
  }
}

function closePeer(ws: WebSocket): void {
  const state = clients.get(ws)
  if (!state) return

  clients.delete(ws)
  const mappedSocket = socketsByUser.get(state.userId)
  if (mappedSocket === ws) {
    socketsByUser.delete(state.userId)
  }

  const room = getRoom(state.roomId)
  if (room) {
    room.members.delete(ws)
    broadcast(room.id, {
      type: 'presence/leave',
      roomId: room.id,
      user: { userId: state.userId, name: state.name },
      serverTime: now(),
    }, state.userId)
    pushRoomSnapshot(room.id)
  }
}

export function setupWebSocket(wss: WebSocketServer): void {
  ensureRoom('lobby', 'Lobby')

  wss.on('connection', (ws: WebSocket, req: IncomingMessage, requestUrl: URL) => {
    const token = requestUrl.searchParams.get('token')
    const requestedUserId = requestUrl.searchParams.get('userId')

    if (!token) {
      ws.close(1008, 'missing_token')
      return
    }

    const payload = verifyToken(token)
    if (!payload || payload.userId !== requestedUserId) {
      ws.close(1008, 'unauthorized')
      return
    }

    const db = getDb()
    const user = db.prepare(`
      SELECT id, email, display_name, display_color 
      FROM users WHERE id = ?
    `).get(payload.userId) as { id: string; email: string; display_name: string; display_color: string } | undefined

    if (!user) {
      ws.close(1008, 'unknown_user')
      return
    }

    const roomId = sanitizeRoomId(requestUrl.searchParams.get('room') || 'lobby')
    const room = ensureRoom(roomId)

    const existingSocket = socketsByUser.get(user.id)
    if (existingSocket && existingSocket !== ws) {
      send(existingSocket, {
        type: 'session/replaced',
        user: { userId: user.id, name: user.display_name },
        serverTime: now(),
      })
      existingSocket.close(4001, 'session_replaced')
    }

    const state: ClientState = {
      userId: user.id,
      email: user.email,
      name: user.display_name,
      color: user.display_color,
      roomId: room.id,
      callJoined: false,
      connectedAt: now(),
      socket: ws,
      messagesSent: 0,
      messagesReceived: 0,
      bytesSent: 0,
      bytesReceived: 0,
    }

    clients.set(ws, state)
    socketsByUser.set(user.id, ws)
    room.members.add(ws)
    metrics.totalConnections++
    if (room.members.size > metrics.peakClientsInRoom) {
      metrics.peakClientsInRoom = room.members.size
    }

    send(ws, {
      type: 'session/ready',
      me: { userId: user.id, name: user.display_name, color: user.display_color },
      roomId: room.id,
      rooms: [...rooms.values()].map(r => ({
        id: r.id,
        name: r.name,
        inviteCode: r.inviteCode,
        memberCount: r.members.size,
        createdAt: r.createdAt,
      })),
      serverTime: now(),
    })

    broadcast(room.id, {
      type: 'presence/join',
      roomId: room.id,
      user: { userId: user.id, name: user.display_name },
      serverTime: now(),
    }, user.id)

    pushRoomSnapshot(room.id)

    ws.on('message', (raw, isBinary) => {
      if (!isBinary) {
        try {
          const message = JSON.parse(String(raw))
          handleMessage(ws, message)
        } catch {
          send(ws, { type: 'error', code: 'invalid_payload', message: 'Invalid JSON payload' })
        }
        return
      }

      let buffer = raw
      if (Array.isArray(buffer)) buffer = Buffer.concat(buffer)
      if (!(buffer instanceof ArrayBuffer) && !Buffer.isBuffer(buffer)) return

      let arrayBuffer: ArrayBuffer
      if (buffer instanceof ArrayBuffer) {
        arrayBuffer = buffer
      } else {
        const byteOffset = buffer.byteOffset
        const byteLength = buffer.byteLength
        arrayBuffer = buffer.buffer.slice(byteOffset, byteOffset + byteLength) as ArrayBuffer
      }

      try {
        const decoded = new IIBINDecoder(arrayBuffer).decode()
        if (decoded.type === MessageType.TEXT_MESSAGE && decoded.data && typeof decoded.data === 'object') {
          handleMessage(ws, decoded.data as Record<string, unknown>)
        }
      } catch {
        send(ws, { type: 'error', code: 'invalid_payload', message: 'Invalid IIBIN payload' })
      }
    })

    ws.on('close', () => closePeer(ws))
    ws.on('error', () => closePeer(ws))
  })
}

export function getStats() {
  const roomDetails = [...rooms.values()].map(r => {
    const callers = [...r.members].filter(m => {
      const s = clients.get(m)
      return s?.callJoined
    }).length
    
    return {
      id: r.id,
      name: r.name,
      members: r.members.size,
      callers,
      callJoins: r.callJoinCount,
      messageCount: r.messageCount,
    }
  })

  const totalMessagesSent = [...clients.values()].reduce((sum, c) => sum + c.messagesSent, 0)
  const totalMessagesReceived = [...clients.values()].reduce((sum, c) => sum + c.messagesReceived, 0)
  const totalBytesSent = [...clients.values()].reduce((sum, c) => sum + c.bytesSent, 0)
  const totalBytesReceived = [...clients.values()].reduce((sum, c) => sum + c.bytesReceived, 0)

  return {
    server: {
      uptime: Date.now() - serverStartTime,
      uptimeFormatted: formatUptime(Date.now() - serverStartTime),
      startTime: new Date(serverStartTime).toISOString(),
    },
    metrics: {
      totalConnections: metrics.totalConnections,
      totalMessages: totalMessagesSent,
      totalBytes: metrics.totalBytes,
      totalBytesFormatted: formatBytes(metrics.totalBytes),
      totalCallJoins: metrics.totalCallJoins,
      peakRooms: Math.max(metrics.peakRooms, rooms.size),
      peakClientsInRoom: metrics.peakClientsInRoom,
    },
    current: {
      rooms: rooms.size,
      clients: clients.size,
      activeCalls: roomDetails.reduce((sum, r) => sum + (r.callers > 0 ? 1 : 0), 0),
    },
    rooms: roomDetails,
    network: {
      bytesSent: totalBytesSent,
      bytesSentFormatted: formatBytes(totalBytesSent),
      bytesReceived: totalBytesReceived,
      bytesReceivedFormatted: formatBytes(totalBytesReceived),
      messagesSent: totalMessagesSent,
      messagesReceived: totalMessagesReceived,
    },
  }
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`
}

function formatUptime(ms: number): string {
  const seconds = Math.floor(ms / 1000)
  const minutes = Math.floor(seconds / 60)
  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)
  
  if (days > 0) return `${days}d ${hours % 24}h`
  if (hours > 0) return `${hours}h ${minutes % 60}m`
  if (minutes > 0) return `${minutes}m ${seconds % 60}s`
  return `${seconds}s`
}
