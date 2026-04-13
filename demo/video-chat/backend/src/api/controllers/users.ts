import { Router } from 'express'
import { getDb } from '../../db/client.js'
import { authenticate, type AuthenticatedRequest } from '../../auth/middleware.js'
import { z } from 'zod'

export const usersRouter = Router()

usersRouter.get('/', authenticate, (req: AuthenticatedRequest, res) => {
  const db = getDb()
  const limit = Math.min(Number(req.query.limit) || 50, 100)
  const search = String(req.query.search || '')

  let query = `
    SELECT id, email, display_name, display_color, created_at, last_login_at, login_count
    FROM users
  `
  const params: unknown[] = []

  if (search) {
    query += ` WHERE display_name LIKE ? OR email LIKE ?`
    params.push(`%${search}%`, `%${search}%`)
  }

  query += ` ORDER BY last_login_at DESC LIMIT ?`
  params.push(limit)

  const users = db.prepare(query).all(...params)
  res.json({ users })
})

usersRouter.get('/:userId', authenticate, (req, res) => {
  const { userId } = req.params
  const db = getDb()

  const user = db.prepare(`
    SELECT id, email, display_name, display_color, created_at, last_login_at, login_count
    FROM users WHERE id = ?
  `).get(userId)

  if (!user) {
    res.status(404).json({ error: 'user_not_found' })
    return
  }

  res.json({ user })
})

const updateProfileSchema = z.object({
  displayName: z.string().min(1).max(48).optional(),
  displayColor: z.string().regex(/^#[0-9a-f]{6}$/).optional(),
})

usersRouter.patch('/me', authenticate, (req: AuthenticatedRequest, res) => {
  try {
    const updates = updateProfileSchema.parse(req.body)
    const db = getDb()

    const setClauses: string[] = []
    const params: unknown[] = []

    if (updates.displayName) {
      setClauses.push('display_name = ?')
      params.push(updates.displayName)
    }
    if (updates.displayColor) {
      setClauses.push('display_color = ?')
      params.push(updates.displayColor)
    }

    if (setClauses.length === 0) {
      res.status(400).json({ error: 'no_updates', message: 'No valid updates provided' })
      return
    }

    setClauses.push('updated_at = ?')
    params.push(Date.now())
    params.push(req.user!.userId)

    db.prepare(`
      UPDATE users SET ${setClauses.join(', ')} WHERE id = ?
    `).run(...params)

    const user = db.prepare(`
      SELECT id, email, display_name, display_color, created_at, last_login_at
      FROM users WHERE id = ?
    `).get(req.user!.userId)

    res.json({ user })
  } catch (error) {
    if (error instanceof z.ZodError) {
      res.status(400).json({ error: 'validation_error', details: error.errors })
      return
    }
    throw error
  }
})
