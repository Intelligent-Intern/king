import type { Request, Response, NextFunction } from 'express'
import { verifyToken } from './jwt.js'

export interface AuthenticatedRequest extends Request {
  user?: {
    userId: string
    email: string
  }
}

export function authenticate(req: Request, res: Response, next: NextFunction): void {
  const authHeader = req.headers.authorization
  let token: string | null = null

  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.slice(7)
  } else if (req.query.token && typeof req.query.token === 'string') {
    token = req.query.token
  }

  if (!token) {
    res.status(401).json({ error: 'unauthorized', message: 'Missing authorization token' })
    return
  }

  const payload = verifyToken(token)

  if (!payload) {
    res.status(401).json({ error: 'unauthorized', message: 'Invalid or expired token' })
    return
  }

  ;(req as AuthenticatedRequest).user = {
    userId: payload.userId,
    email: payload.email,
  }

  next()
}

export function optionalAuth(req: Request, _res: Response, next: NextFunction): void {
  const authHeader = req.headers.authorization
  let token: string | null = null

  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.slice(7)
  } else if (req.query.token && typeof req.query.token === 'string') {
    token = req.query.token
  }

  if (token) {
    const payload = verifyToken(token)
    if (payload) {
      ;(req as AuthenticatedRequest).user = {
        userId: payload.userId,
        email: payload.email,
      }
    }
  }

  next()
}
