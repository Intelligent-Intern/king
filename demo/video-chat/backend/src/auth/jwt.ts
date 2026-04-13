import jwt from 'jsonwebtoken'
import { randomBytes } from 'crypto'
import { getDb } from '../db/client.js'

const JWT_SECRET = process.env.JWT_SECRET || 'development-secret-change-in-production'
const JWT_EXPIRES_IN = process.env.JWT_EXPIRES_IN || '7d'
const JWT_REFRESH_EXPIRES_IN = process.env.JWT_REFRESH_EXPIRES_IN || '30d'

interface TokenPayloadBase {
  userId: string
  email?: string
  type?: string
  iat?: number
  exp?: number
}

export function generateAccessToken(userId: string, email: string): string {
  return jwt.sign({ userId, email }, JWT_SECRET, { expiresIn: JWT_EXPIRES_IN as jwt.SignOptions['expiresIn'] })
}

export function generateRefreshToken(userId: string): string {
  return jwt.sign({ userId, type: 'refresh' }, JWT_SECRET, { expiresIn: JWT_REFRESH_EXPIRES_IN as jwt.SignOptions['expiresIn'] })
}

export function verifyToken(token: string): { userId: string; email: string } | null {
  try {
    const decoded = jwt.verify(token, JWT_SECRET) as TokenPayloadBase
    if (decoded.type === 'refresh' || !decoded.email) return null
    return { userId: decoded.userId, email: decoded.email }
  } catch {
    return null
  }
}

export function verifyRefreshToken(token: string): { userId: string } | null {
  try {
    const decoded = jwt.verify(token, JWT_SECRET) as TokenPayloadBase
    if (decoded.type !== 'refresh') return null
    return { userId: decoded.userId }
  } catch {
    return null
  }
}

export function generateSessionToken(): { token: string; hash: string } {
  const token = randomBytes(64).toString('hex')
  const hash = randomBytes(32).toString('hex')
  return { token, hash }
}

export function storeSession(
  userId: string,
  tokenHash: string,
  refreshTokenHash: string | null,
  expiresAt: number,
  ipAddress?: string,
  userAgent?: string
): string {
  const db = getDb()
  const sessionId = randomBytes(16).toString('hex')
  
  db.prepare(`
    INSERT INTO sessions (id, user_id, token_hash, refresh_token_hash, expires_at, ip_address, user_agent)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(sessionId, userId, tokenHash, refreshTokenHash, expiresAt, ipAddress || null, userAgent || null)
  
  return sessionId
}

export function validateSession(tokenHash: string): { userId: string; sessionId: string } | null {
  const db = getDb()
  const session = db.prepare(`
    SELECT id, user_id, expires_at FROM sessions
    WHERE token_hash = ? AND expires_at > ?
  `).get(tokenHash, Date.now()) as { id: string; user_id: string; expires_at: number } | undefined

  if (!session) return null
  return { userId: session.user_id, sessionId: session.id }
}

export function revokeSession(sessionId: string): void {
  const db = getDb()
  db.prepare('DELETE FROM sessions WHERE id = ?').run(sessionId)
}

export function revokeAllUserSessions(userId: string): void {
  const db = getDb()
  db.prepare('DELETE FROM sessions WHERE user_id = ?').run(userId)
}

export function cleanupExpiredSessions(): number {
  const db = getDb()
  const result = db.prepare('DELETE FROM sessions WHERE expires_at < ?').run(Date.now())
  return result.changes
}
