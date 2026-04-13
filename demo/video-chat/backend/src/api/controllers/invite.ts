import { Router } from 'express'
import { z } from 'zod'
import { v4 as uuidv4 } from 'uuid'
import { getDb } from '../../db/client.js'
import { authenticate, type AuthenticatedRequest } from '../../auth/middleware.js'

export const inviteRouter = Router()

const redeemSchema = z.object({
  code: z.string().min(1).max(32),
})

inviteRouter.post('/redeem', authenticate, async (req: AuthenticatedRequest, res) => {
  try {
    const { code } = redeemSchema.parse(req.body)
    const db = getDb()

    const invite = db.prepare(`
      SELECT ir.*, r.name as room_name, r.id as room_id
      FROM invite_codes ir
      JOIN rooms r ON ir.room_id = r.id
      WHERE ir.code = ? AND (ir.expires_at IS NULL OR ir.expires_at > ?)
    `).get(code, Date.now()) as { room_id: string; room_name: string; uses_remaining: number } | undefined

    if (!invite) {
      res.status(404).json({ error: 'invite_not_found' })
      return
    }

    if (invite.uses_remaining !== null && invite.uses_remaining <= 0) {
      res.status(410).json({ error: 'invite_expired' })
      return
    }

    const existing = db.prepare('SELECT * FROM room_members WHERE room_id = ? AND user_id = ?')
      .get(invite.room_id, req.user!.userId)

    if (!existing) {
      db.prepare(`
        INSERT INTO room_members (room_id, user_id, role)
        VALUES (?, ?, 'member')
      `).run(invite.room_id, req.user!.userId)
    }

    if (invite.uses_remaining !== null) {
      db.prepare('UPDATE invite_codes SET uses_remaining = uses_remaining - 1 WHERE code = ?').run(code)
    }

    const room = db.prepare(`
      SELECT r.id, r.name, r.description, r.created_at, r.created_by,
             u.display_name as created_by_name
      FROM rooms r
      LEFT JOIN users u ON r.created_by = u.id
      WHERE r.id = ?
    `).get(invite.room_id)

    res.json({ room })
  } catch (error) {
    if (error instanceof z.ZodError) {
      res.status(400).json({ error: 'validation_error', details: error.errors })
      return
    }
    throw error
  }
})