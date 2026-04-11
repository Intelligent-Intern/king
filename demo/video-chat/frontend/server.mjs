import path from 'node:path'
import { fileURLToPath } from 'node:url'
import express from 'express'
import { createProxyMiddleware } from 'http-proxy-middleware'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const port = Number.parseInt(process.env.FRONTEND_PORT || '5173', 10)
const backendOrigin = process.env.VIDEOCHAT_BACKEND_ORIGIN || 'http://videochat-backend:8080'
const backendWsOrigin = backendOrigin.replace(/^http/i, 'ws')
const distDir = path.join(__dirname, 'dist')

const app = express()

const wsProxy = createProxyMiddleware({
  target: backendWsOrigin,
  changeOrigin: true,
  ws: true,
  pathRewrite: (requestPath) => `/ws${requestPath}`,
})

app.use('/api', createProxyMiddleware({
  target: backendOrigin,
  changeOrigin: true,
  pathRewrite: (requestPath) => `/api${requestPath}`,
}))
app.use('/ws', wsProxy)

app.use(express.static(distDir))
app.use((req, res, next) => {
  if (req.path.startsWith('/api') || req.path === '/ws' || req.path.startsWith('/ws/')) {
    next()
    return
  }
  res.sendFile(path.join(distDir, 'index.html'))
})

const server = app.listen(port, '0.0.0.0', () => {
  process.stdout.write(`video-chat frontend listening on 0.0.0.0:${port}\n`)
})

server.on('upgrade', wsProxy.upgrade)
