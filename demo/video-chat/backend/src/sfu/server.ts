import { WebSocket, WebSocketServer } from 'ws'
import type { IncomingMessage } from 'http'
import { URL } from 'url'
import { v4 as uuidv4 } from 'uuid'
import { verifyToken } from '../auth/jwt.js'
import { getDb } from '../db/client.js'

interface SFUClient {
  ws: WebSocket
  userId: string
  name: string
  roomId: string
  isPublisher: boolean
  tracks: Map<string, MediaTrack>
}

interface MediaTrack {
  id: string
  kind: 'audio' | 'video'
  label: string
}

interface SFURoom {
  id: string
  publishers: Set<string>
  subscribers: Map<string, Set<string>>
}

const sfuClients = new Map<WebSocket, SFUClient>()
const sfuRooms = new Map<string, SFURoom>()

function now(): number {
  return Date.now()
}

function send(ws: WebSocket, data: unknown): void {
  if (ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify(data))
  }
}

function getOrCreateRoom(roomId: string): SFURoom {
  if (!sfuRooms.has(roomId)) {
    sfuRooms.set(roomId, {
      id: roomId,
      publishers: new Set(),
      subscribers: new Map(),
    })
  }
  return sfuRooms.get(roomId)!
}

function broadcastPublishers(roomId: string, excludeWs: WebSocket | null, message: unknown): void {
  for (const [ws, client] of sfuClients.entries()) {
    if (ws !== excludeWs && client.roomId === roomId && client.isPublisher) {
      send(ws, message)
    }
  }
}

function handleSFUMessage(ws: WebSocket, message: Record<string, unknown>): void {
  const client = sfuClients.get(ws)
  if (!client) return

  const type = String(message.type || '')

  switch (type) {
    case 'sfu/join': {
      const roomId = String(message.roomId || 'lobby').toLowerCase().replace(/[^a-z0-9-_]/g, '-') || 'lobby'
      const role = String(message.role || 'subscriber')
      const isPublisher = role === 'publisher'

      client.roomId = roomId
      client.isPublisher = isPublisher

      const room = getOrCreateRoom(roomId)
      
      if (isPublisher) {
        room.publishers.add(client.userId)
      } else {
        if (!room.subscribers.has(client.userId)) {
          room.subscribers.set(client.userId, new Set())
        }
      }

      send(ws, {
        type: 'sfu/joined',
        roomId,
        role: isPublisher ? 'publisher' : 'subscriber',
        publishers: Array.from(room.publishers),
        serverTime: now(),
      })
      break
    }

    case 'sfu/publish': {
      if (!client.isPublisher) {
        send(ws, { type: 'error', code: 'not_publisher', message: 'Only publishers can publish tracks.' })
        return
      }

      const trackId = String(message.trackId || uuidv4())
      const kind = String(message.kind || 'video') as 'audio' | 'video'
      const label = String(message.label || '')

      client.tracks.set(trackId, { id: trackId, kind, label })

      const room = getOrCreateRoom(client.roomId)
      
      for (const [ws, subClient] of sfuClients.entries()) {
        if (subClient.roomId === client.roomId && !subClient.isPublisher) {
          send(ws, {
            type: 'sfu/tracks',
            roomId: client.roomId,
            publisherId: client.userId,
            publisherName: client.name,
            tracks: Array.from(client.tracks.values()),
            serverTime: now(),
          })
        }
      }

      send(ws, { type: 'sfu/published', trackId, serverTime: now() })
      break
    }

    case 'sfu/subscribe': {
      const publisherId = String(message.publisherId || '')
      if (!publisherId) {
        send(ws, { type: 'error', code: 'publisher_required', message: 'publisherId is required.' })
        return
      }

      const publisher = Array.from(sfuClients.values()).find(
        c => c.userId === publisherId && c.roomId === client.roomId && c.isPublisher
      )

      if (!publisher) {
        send(ws, { type: 'error', code: 'publisher_not_found', message: 'Publisher not found.' })
        return
      }

      const room = getOrCreateRoom(client.roomId)
      const subTracks = room.subscribers.get(client.userId) || new Set()
      subTracks.add(publisherId)
      room.subscribers.set(client.userId, subTracks)

      send(ws, {
        type: 'sfu/tracks',
        roomId: client.roomId,
        publisherId: publisher.userId,
        publisherName: publisher.name,
        tracks: Array.from(publisher.tracks.values()),
        serverTime: now(),
      })
      break
    }

    case 'sfu/unpublish': {
      const trackId = String(message.trackId || '')
      if (trackId) {
        client.tracks.delete(trackId)
      }

      const room = getOrCreateRoom(client.roomId)
      for (const [ws, subClient] of sfuClients.entries()) {
        if (subClient.roomId === client.roomId && !subClient.isPublisher) {
          const subTracks = room.subscribers.get(subClient.userId)
          if (subTracks?.has(client.userId)) {
            send(ws, {
              type: 'sfu/unpublished',
              roomId: client.roomId,
              publisherId: client.userId,
              trackId,
              serverTime: now(),
            })
          }
        }
      }
      break
    }

    case 'sfu/leave': {
      handleSFULeave(ws)
      break
    }
  }
}

function handleSFULeave(ws: WebSocket): void {
  const client = sfuClients.get(ws)
  if (!client) return

  const room = sfuRooms.get(client.roomId)
  if (room) {
    if (client.isPublisher) {
      room.publishers.delete(client.userId)
      for (const [ws, subClient] of sfuClients.entries()) {
        if (subClient.roomId === client.roomId && !subClient.isPublisher) {
          send(ws, {
            type: 'sfu/publisher_left',
            roomId: client.roomId,
            publisherId: client.userId,
            serverTime: now(),
          })
        }
      }
    } else {
      room.subscribers.delete(client.userId)
    }
  }

  client.tracks.clear()
  client.isPublisher = false
}

export function setupSFU(wss: WebSocketServer): void {
  wss.on('connection', (ws: WebSocket, req: IncomingMessage, requestUrl: URL) => {
    const token = requestUrl.searchParams.get('token')
    const requestedUserId = requestUrl.searchParams.get('userId')
    const userName = requestUrl.searchParams.get('name') || 'Anonymous'

    if (!token) {
      ws.close(1008, 'missing_token')
      return
    }

    const payload = verifyToken(token)
    if (!payload || payload.userId !== requestedUserId) {
      ws.close(1008, 'invalid_token')
      return
    }

    const userId = requestedUserId || payload.userId
    const client: SFUClient = {
      ws,
      userId,
      name: userName,
      roomId: 'lobby',
      isPublisher: false,
      tracks: new Map(),
    }

    sfuClients.set(ws, client)

    send(ws, {
      type: 'sfu/welcome',
      userId,
      name: userName,
      serverTime: now(),
    })

    ws.on('message', (data: Buffer) => {
      try {
        const message = JSON.parse(data.toString())
        handleSFUMessage(ws, message)
      } catch {
        send(ws, { type: 'error', code: 'invalid_message', message: 'Failed to parse message.' })
      }
    })

    ws.on('close', () => {
      handleSFULeave(ws)
      sfuClients.delete(ws)
    })

    ws.on('error', (err) => {
      console.error('[SFU] WebSocket error:', err)
    })
  })
}

export function getSFUStats() {
  let publishers = 0
  let subscribers = 0
  for (const client of sfuClients.values()) {
    if (client.isPublisher) publishers++
    else subscribers++
  }
  return {
    totalConnections: sfuClients.size,
    publishers,
    subscribers,
    rooms: sfuRooms.size,
  }
}