import { Router } from 'express'
import { z } from 'zod'
import { v4 as uuidv4 } from 'uuid'
import { getDb } from '../../db/client.js'
import { 
  generateAccessToken, 
  generateRefreshToken, 
  verifyRefreshToken,
  storeSession,
  revokeSession 
} from '../../auth/jwt.js'
import { hashPassword, verifyPassword, isPasswordStrong } from '../../auth/password.js'
import { authenticate, type AuthenticatedRequest } from '../../auth/middleware.js'
import { randomBytes, createHash } from 'crypto'

export const authRouter = Router()

const COLORS = [
  '#0f62fe', '#8a3ffc', '#6914e0', '#1192e8', '#005d5d',
  '#00539a', '#6929c4', '#1192e8', '#0e6027', '#00524a',
]

function randomColor(): string {
  return COLORS[Math.floor(Math.random() * COLORS.length)]
}

const registerSchema = z.object({
  email: z.string().email().max(255),
  password: z.string().min(8).max(128),
  displayName: z.string().min(1).max(48).optional(),
})

const loginSchema = z.object({
  email: z.string().email().nullish(),
  password: z.string().nullish(),
  token: z.string().nullish(),
  name: z.string().nullish(),
  color: z.string().nullish(),
  userId: z.string().nullish(),
})

authRouter.post('/register', async (req, res) => {
  try {
    const { email, password, displayName } = registerSchema.parse(req.body)
    
    const passwordCheck = isPasswordStrong(password)
    if (!passwordCheck.valid) {
      res.status(400).json({ error: 'weak_password', message: passwordCheck.message })
      return
    }

    const db = getDb()
    const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email)
    if (existing) {
      res.status(409).json({ error: 'email_exists', message: 'Email already registered' })
      return
    }

    const passwordHash = await hashPassword(password)
    const userId = uuidv4()
    const name = displayName || `user-${userId.slice(0, 8)}`
    const color = randomColor()

    db.prepare(`
      INSERT INTO users (id, email, password_hash, display_name, display_color)
      VALUES (?, ?, ?, ?, ?)
    `).run(userId, email, passwordHash, name, color)

    const accessToken = generateAccessToken(userId, email)
    const refreshToken = generateRefreshToken(userId)
    const expiresAt = Date.now() + 7 * 24 * 60 * 60 * 1000

    const tokenHash = createHash('sha256').update(accessToken).digest('hex')
    const refreshHash = createHash('sha256').update(refreshToken).digest('hex')
    storeSession(userId, tokenHash, refreshHash, expiresAt, req.ip, req.get('user-agent'))

    db.prepare('UPDATE users SET login_count = login_count + 1, last_login_at = ? WHERE id = ?')
      .run(Date.now(), userId)

    res.status(201).json({
      user: { id: userId, email, displayName: name, color },
      accessToken,
      refreshToken,
      expiresAt,
    })
  } catch (error) {
    if (error instanceof z.ZodError) {
      res.status(400).json({ error: 'validation_error', details: error.errors })
      return
    }
    throw error
  }
})

authRouter.post('/login', async (req, res) => {
  try {
    const { email, password, token, name, color, userId } = loginSchema.parse(req.body)

    const db = getDb()

    if (token) {
      const payload = verifyRefreshToken(token)
      if (!payload) {
        res.status(401).json({ error: 'invalid_token', message: 'Invalid refresh token' })
        return
      }

      const user = db.prepare(`
        SELECT id, email, display_name, display_color 
        FROM users WHERE id = ?
      `).get(payload.userId) as { id: string; email: string; display_name: string; display_color: string } | undefined

      if (!user) {
        res.status(401).json({ error: 'user_not_found', message: 'User not found' })
        return
      }

      const accessToken = generateAccessToken(user.id, user.email)
      const newRefreshToken = generateRefreshToken(user.id)
      const expiresAt = Date.now() + 7 * 24 * 60 * 60 * 1000

      const tokenHash = createHash('sha256').update(accessToken).digest('hex')
      const refreshHash = createHash('sha256').update(newRefreshToken).digest('hex')
      storeSession(user.id, tokenHash, refreshHash, expiresAt, req.ip, req.get('user-agent'))

      res.json({
        session: { userId: user.id, name: user.display_name, color: user.display_color, token: accessToken, tokenExpiresAt: expiresAt },
        accessToken,
        refreshToken: newRefreshToken,
        expiresAt,
      })
      return
    }

    if (email && password) {
      const user = db.prepare(`
        SELECT id, email, password_hash, display_name, display_color 
        FROM users WHERE email = ?
      `).get(email) as { id: string; email: string; password_hash: string; display_name: string; display_color: string } | undefined

      if (!user) {
        res.status(401).json({ error: 'invalid_credentials', message: 'Invalid email or password' })
        return
      }

      const validPassword = await verifyPassword(password, user.password_hash)
      if (!validPassword) {
        res.status(401).json({ error: 'invalid_credentials', message: 'Invalid email or password' })
        return
      }

      const accessToken = generateAccessToken(user.id, user.email)
      const refreshToken = generateRefreshToken(user.id)
      const expiresAt = Date.now() + 7 * 24 * 60 * 60 * 1000

      const tokenHash = createHash('sha256').update(accessToken).digest('hex')
      const refreshHash = createHash('sha256').update(refreshToken).digest('hex')
      storeSession(user.id, tokenHash, refreshHash, expiresAt, req.ip, req.get('user-agent'))

      db.prepare('UPDATE users SET login_count = login_count + 1, last_login_at = ? WHERE id = ?')
        .run(Date.now(), user.id)

      res.json({
        session: { userId: user.id, name: user.display_name, color: user.display_color, token: accessToken, tokenExpiresAt: expiresAt },
        accessToken,
        refreshToken,
        expiresAt,
      })
      return
    }

    if (name) {
      const finalUserId = userId || `u-${uuidv4().slice(0, 8)}`
      const finalName = name.slice(0, 48) || `user-${finalUserId.slice(0, 8)}`
      const finalColor = /^#[0-9a-f]{6}$/i.test(color || '') ? color : randomColor()

      const existingUser = db.prepare('SELECT id, display_name, display_color FROM users WHERE id = ?').get(finalUserId) as { id: string; display_name: string; display_color: string } | undefined

      let finalDisplayName = finalName
      let finalDisplayColor = finalColor

      if (existingUser) {
        db.prepare('UPDATE users SET display_name = ?, display_color = ?, last_login_at = ?, login_count = login_count + 1 WHERE id = ?')
          .run(finalName, finalColor, Date.now(), finalUserId)
        finalDisplayName = finalName
        finalDisplayColor = finalColor
      } else {
        db.prepare(`
          INSERT INTO users (id, email, password_hash, display_name, display_color, last_login_at, login_count)
          VALUES (?, ?, ?, ?, ?, ?, 1)
        `).run(finalUserId, `${finalUserId}@local`, '', finalName, finalColor, Date.now())
      }

      const accessToken = generateAccessToken(finalUserId, `${finalUserId}@local`)
      const expiresAt = Date.now() + 7 * 24 * 60 * 60 * 1000

      const tokenHash = createHash('sha256').update(accessToken).digest('hex')
      storeSession(finalUserId, tokenHash, null, expiresAt, req.ip, req.get('user-agent'))

      res.json({
        session: { userId: finalUserId, name: finalDisplayName, color: finalDisplayColor, token: accessToken, tokenExpiresAt: expiresAt },
        accessToken,
        expiresAt,
      })
      return
    }

    res.status(400).json({ error: 'missing_credentials', message: 'Name or email/password required' })
    return
  } catch (error) {
    if (error instanceof z.ZodError) {
      res.status(400).json({ error: 'validation_error', details: error.errors })
      return
    }
    throw error
  }
})

authRouter.post('/refresh', (req, res) => {
  const { refreshToken } = req.body
  
  if (!refreshToken || typeof refreshToken !== 'string') {
    res.status(400).json({ error: 'missing_token', message: 'Refresh token required' })
    return
  }

  const payload = verifyRefreshToken(refreshToken)
  if (!payload) {
    res.status(401).json({ error: 'invalid_token', message: 'Invalid or expired refresh token' })
    return
  }

  const db = getDb()
  const user = db.prepare(`
    SELECT id, email, display_name, display_color 
    FROM users WHERE id = ?
  `).get(payload.userId) as { id: string; email: string; display_name: string; display_color: string } | undefined

  if (!user) {
    res.status(401).json({ error: 'user_not_found', message: 'User not found' })
    return
  }

  const accessToken = generateAccessToken(user.id, user.email)
  const newRefreshToken = generateRefreshToken(user.id)
  const expiresAt = Date.now() + 7 * 24 * 60 * 60 * 1000

  const tokenHash = createHash('sha256').update(accessToken).digest('hex')
  const refreshHash = createHash('sha256').update(newRefreshToken).digest('hex')
  storeSession(user.id, tokenHash, refreshHash, expiresAt, req.ip, req.get('user-agent'))

  res.json({
    accessToken,
    refreshToken: newRefreshToken,
    expiresAt,
  })
})

authRouter.post('/logout', authenticate, (req: AuthenticatedRequest, res) => {
  const authHeader = req.headers.authorization
  if (authHeader) {
    const token = authHeader.slice(7)
    const tokenHash = createHash('sha256').update(token).digest('hex')
    const session = getDb().prepare('SELECT id FROM sessions WHERE token_hash = ?').get(tokenHash) as { id: string } | undefined
    if (session) {
      revokeSession(session.id)
    }
  }
  res.json({ success: true })
})

authRouter.get('/me', authenticate, (req: AuthenticatedRequest, res) => {
  const db = getDb()
  const user = db.prepare(`
    SELECT id, email, display_name, display_color, created_at, last_login_at, login_count
    FROM users WHERE id = ?
  `).get(req.user!.userId)

  if (!user) {
    res.status(404).json({ error: 'user_not_found' })
    return
  }

  res.json({ user })
})
