import { Router } from 'express'
import { z } from 'zod'
import { v4 as uuidv4 } from 'uuid'
import { getDb } from '../../db/client.js'
import { authenticate, type AuthenticatedRequest } from '../../auth/middleware.js'

export const roomsRouter = Router()

const createRoomSchema = z.object({
  name: z.string().min(1).max(48),
  description: z.string().max(500).optional(),
  settings: z.object({
    maxParticipants: z.number().min(2).max(100).optional(),
    isPublic: z.boolean().optional(),
    password: z.string().max(128).optional(),
  }).optional(),
})

const joinRoomSchema = z.object({
  password: z.string().max(128).optional(),
})

roomsRouter.get('/', (req, res) => {
  const db = getDb()
  const rooms = db.prepare(`
    SELECT r.id, r.name, r.description, r.created_at, r.created_by,
           COUNT(rm.user_id) as member_count,
           u.display_name as created_by_name
    FROM rooms r
    LEFT JOIN room_members rm ON r.id = rm.room_id
    LEFT JOIN users u ON r.created_by = u.id
    GROUP BY r.id
    ORDER BY r.created_at DESC
    LIMIT 100
  `).all()

  res.json({ rooms })
})

roomsRouter.get('/:roomId', (req, res) => {
  const { roomId } = req.params
  const db = getDb()

  const room = db.prepare(`
    SELECT r.*, u.display_name as created_by_name,
           COUNT(rm.user_id) as member_count
    FROM rooms r
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN room_members rm ON r.id = rm.room_id
    WHERE r.id = ?
    GROUP BY r.id
  `).get(roomId)

  if (!room) {
    res.status(404).json({ error: 'room_not_found' })
    return
  }

  const members = db.prepare(`
    SELECT u.id, u.display_name, u.display_color, rm.joined_at, rm.role
    FROM room_members rm
    JOIN users u ON rm.user_id = u.id
    WHERE rm.room_id = ?
    ORDER BY rm.joined_at
  `).all(roomId)

  res.json({ room, members })
})

roomsRouter.post('/', authenticate, (req: AuthenticatedRequest, res) => {
  try {
    const { name, description, settings } = createRoomSchema.parse(req.body)
    const db = getDb()
    const roomId = uuidv4().slice(0, 8)

    db.prepare(`
      INSERT INTO rooms (id, name, description, created_by, settings)
      VALUES (?, ?, ?, ?, ?)
    `).run(roomId, name, description || null, req.user!.userId, JSON.stringify(settings || {}))

    db.prepare(`
      INSERT INTO room_members (room_id, user_id, role)
      VALUES (?, ?, 'owner')
    `).run(roomId, req.user!.userId)

    const room = db.prepare('SELECT * FROM rooms WHERE id = ?').get(roomId)

    res.status(201).json({ room })
  } catch (error) {
    if (error instanceof z.ZodError) {
      res.status(400).json({ error: 'validation_error', details: error.errors })
      return
    }
    throw error
  }
})

roomsRouter.post('/:roomId/invite', authenticate, (req: AuthenticatedRequest, res) => {
  const { roomId } = req.params
  const db = getDb()

  const room = db.prepare('SELECT * FROM rooms WHERE id = ?').get(roomId) as { id: string } | undefined
  if (!room) {
    res.status(404).json({ error: 'room_not_found' })
    return
  }

  const code = uuidv4().slice(0, 8).toUpperCase()
  
  db.prepare(`
    INSERT INTO invite_codes (id, code, room_id, created_by, uses_remaining)
    VALUES (?, ?, ?, ?, 100)
  `).run(uuidv4(), code, roomId, req.user!.userId)

  res.json({ inviteCode: code })
})

roomsRouter.post('/:roomId/join', authenticate, (req: AuthenticatedRequest, res) => {
  try {
    const { roomId } = req.params
    const { password } = joinRoomSchema.parse(req.body)
    const db = getDb()

    const room = db.prepare('SELECT * FROM rooms WHERE id = ?').get(roomId) as { id: string; settings: string } | undefined
    if (!room) {
      res.status(404).json({ error: 'room_not_found' })
      return
    }

    const settings = JSON.parse(room.settings || '{}')
    if (settings.password && settings.password !== password) {
      res.status(403).json({ error: 'invalid_password' })
      return
    }

    const existing = db.prepare('SELECT * FROM room_members WHERE room_id = ? AND user_id = ?')
      .get(roomId, req.user!.userId)

    if (!existing) {
      db.prepare(`
        INSERT INTO room_members (room_id, user_id, role)
        VALUES (?, ?, 'member')
      `).run(roomId, req.user!.userId)
    }

    const inviteCode = uuidv4().slice(0, 8).toUpperCase()

    res.json({ 
      success: true, 
      roomId,
      inviteCode,
    })
  } catch (error) {
    if (error instanceof z.ZodError) {
      res.status(400).json({ error: 'validation_error', details: error.errors })
      return
    }
    throw error
  }
})

roomsRouter.post('/:roomId/leave', authenticate, (req: AuthenticatedRequest, res) => {
  const { roomId } = req.params
  const db = getDb()

  const member = db.prepare('SELECT * FROM room_members WHERE room_id = ? AND user_id = ?')
    .get(roomId, req.user!.userId)

  if (!member) {
    res.status(404).json({ error: 'not_a_member' })
    return
  }

  db.prepare('DELETE FROM room_members WHERE room_id = ? AND user_id = ?')
    .run(roomId, req.user!.userId)

  res.json({ success: true })
})

roomsRouter.delete('/:roomId', authenticate, (req: AuthenticatedRequest, res) => {
  const { roomId } = req.params
  const db = getDb()

  const room = db.prepare('SELECT * FROM rooms WHERE id = ?').get(roomId) as { created_by: string } | undefined
  if (!room) {
    res.status(404).json({ error: 'room_not_found' })
    return
  }

  if (room.created_by !== req.user!.userId) {
    res.status(403).json({ error: 'forbidden', message: 'Only room owner can delete room' })
    return
  }

  db.prepare('DELETE FROM rooms WHERE id = ?').run(roomId)

  res.json({ success: true })
})

roomsRouter.get('/:roomId/messages', authenticate, (req, res) => {
  const { roomId } = req.params
  const limit = Math.min(Number(req.query.limit) || 50, 100)
  const before = Number(req.query.before) || Date.now()

  const db = getDb()
  const messages = db.prepare(`
    SELECT m.*, u.display_name, u.display_color
    FROM messages m
    JOIN users u ON m.user_id = u.id
    WHERE m.room_id = ? AND m.created_at < ?
    ORDER BY m.created_at DESC
    LIMIT ?
  `).all(roomId, before, limit)

  res.json({ messages: messages.reverse() })
})

roomsRouter.post('/:roomId/messages', authenticate, (req: AuthenticatedRequest, res) => {
  const { roomId } = req.params
  const { content, type = 'text' } = req.body
  const db = getDb()

  const member = db.prepare('SELECT * FROM room_members WHERE room_id = ? AND user_id = ?')
    .get(roomId, req.user!.userId)

  if (!member) {
    res.status(403).json({ error: 'not_a_member' })
    return
  }

  const messageId = uuidv4()
  db.prepare(`
    INSERT INTO messages (id, room_id, user_id, content, message_type)
    VALUES (?, ?, ?, ?, ?)
  `).run(messageId, roomId, req.user!.userId, content.slice(0, 4000), type)

  const message = db.prepare(`
    SELECT m.*, u.display_name, u.display_color
    FROM messages m
    JOIN users u ON m.user_id = u.id
    WHERE m.id = ?
  `).get(messageId)

  res.status(201).json({ message })
})
