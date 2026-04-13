import 'dotenv/config'
import { createServer } from 'http'
import { readFileSync } from 'fs'
import { join, dirname } from 'path'
import { fileURLToPath } from 'url'
import express from 'express'
import cors from 'cors'
import helmet from 'helmet'
import rateLimit from 'express-rate-limit'
import { WebSocketServer } from 'ws'
import { initializeDatabase } from './db/client.js'
import { authRouter } from './api/controllers/auth.js'
import { roomsRouter } from './api/controllers/rooms.js'
import { usersRouter } from './api/controllers/users.js'
import { inviteRouter } from './api/controllers/invite.js'
import { setupWebSocket, getStats } from './signaling/server.js'
import { setupSFU, getSFUStats } from './sfu/server.js'
import { errorHandler, notFoundHandler } from './security/middleware.js'
import type { Server } from 'http'

const __dirname = dirname(fileURLToPath(import.meta.url))
const PORT = Number(process.env.PORT || 3000)
const SSL_ENABLED = process.env.SSL_ENABLED === 'true'
const corsOrigin = process.env.CORS_ORIGIN || 'http://localhost:5173'

const app = express()

app.use(helmet({
  contentSecurityPolicy: {
    directives: {
      defaultSrc: ["'self'"],
      connectSrc: ["'self'", 'wss:', 'ws:', corsOrigin],
      mediaSrc: ["'self'", 'blob:'],
      imgSrc: ["'self'", 'data:', 'blob:'],
    },
  },
  hsts: {
    maxAge: 31536000,
    includeSubDomains: true,
    preload: true,
  },
}))

app.use(cors({
  origin: corsOrigin,
  credentials: true,
  methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization'],
}))

app.use(express.json({ limit: '1mb' }))

const limiter = rateLimit({
  windowMs: Number(process.env.RATE_LIMIT_WINDOW_MS || 900000),
  max: Number(process.env.RATE_LIMIT_MAX_REQUESTS || 1000),
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'too_many_requests', message: 'Too many requests, please try again later.' },
})

app.use('/api/', limiter)

initializeDatabase()

app.use('/api/auth', authRouter)
app.use('/api/rooms', roomsRouter)
app.use('/api/users', usersRouter)
app.use('/api/invite', inviteRouter)

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'king-video-chat-backend',
    version: '1.0.0',
    timestamp: new Date().toISOString(),
  })
})

app.get('/api/stats', (_req, res) => {
  res.json(getStats())
})

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'king-video-chat-backend',
    version: '1.0.0',
    timestamp: new Date().toISOString(),
  })
})

app.use(notFoundHandler)
app.use(errorHandler)

let server: Server

if (SSL_ENABLED) {
  const https = await import('https')
  const sslOptions = {
    cert: readFileSync(join(__dirname, '..', process.env.SSL_CERT_PATH || 'certs/server.crt')),
    key: readFileSync(join(__dirname, '..', process.env.SSL_KEY_PATH || 'certs/server.key')),
  }
  server = https.createServer(sslOptions, app)
  console.log(`[${new Date().toISOString()}] HTTPS server initialized`)
} else {
  server = createServer(app)
  console.log(`[${new Date().toISOString()}] HTTP server initialized`)
}

const wss    = new WebSocketServer({ noServer: true })
const sfuWss = new WebSocketServer({ noServer: true })
setupWebSocket(wss)
setupSFU(sfuWss)

server.on('upgrade', (request, socket, head) => {
  const hostHeader = request.headers.host || `localhost:${PORT}`
  const url = new URL(request.url || '/', `http${SSL_ENABLED ? 's' : ''}://${hostHeader}`)
  const pathname = url.pathname.replace(/\/+$/, '') || '/'

  if (pathname === '/ws') {
    wss.handleUpgrade(request, socket, head, (ws) => {
      wss.emit('connection', ws, request, url)
    })
  } else if (pathname === '/sfu') {
    sfuWss.handleUpgrade(request, socket, head, (ws) => {
      sfuWss.emit('connection', ws, request, url)
    })
  } else {
    socket.destroy()
  }
})

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[${new Date().toISOString()}] Video chat backend listening on port ${PORT}`)
  console.log(`[${new Date().toISOString()}] WebSocket endpoint: ws${SSL_ENABLED ? 's' : ''}://localhost:${PORT}/ws`)
})

process.on('SIGTERM', () => {
  console.log('SIGTERM received, shutting down gracefully...')
  server.close(() => {
    console.log('Server closed')
    process.exit(0)
  })
})
