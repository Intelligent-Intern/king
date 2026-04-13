import type { Request, Response, NextFunction } from 'express'
import { ZodError } from 'zod'

export function notFoundHandler(req: Request, res: Response): void {
  res.status(404).json({
    error: 'not_found',
    message: `Cannot ${req.method} ${req.path}`,
    path: req.path,
  })
}

export function errorHandler(err: Error, _req: Request, res: Response, _next: NextFunction): void {
  console.error(`[${new Date().toISOString()}] Error:`, err.message)

  if (err instanceof ZodError) {
    res.status(400).json({
      error: 'validation_error',
      message: 'Invalid request data',
      details: err.errors.map(e => ({
        path: e.path.join('.'),
        message: e.message,
      })),
    })
    return
  }

  if (err.name === 'JsonWebTokenError') {
    res.status(401).json({
      error: 'unauthorized',
      message: 'Invalid token',
    })
    return
  }

  if (err.name === 'TokenExpiredError') {
    res.status(401).json({
      error: 'unauthorized',
      message: 'Token has expired',
    })
    return
  }

  res.status(500).json({
    error: 'internal_error',
    message: process.env.NODE_ENV === 'production' 
      ? 'An unexpected error occurred' 
      : err.message,
  })
}

export function sanitizeString(input: string, maxLength = 1000): string {
  return input
    .replace(/[<>]/g, '')
    .trim()
    .slice(0, maxLength)
}

export function generateInviteCode(): string {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  let code = ''
  for (let i = 0; i < 8; i++) {
    code += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  return code
}
