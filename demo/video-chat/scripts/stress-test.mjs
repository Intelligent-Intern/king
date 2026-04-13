import WebSocket from 'ws'
import { v4 as uuidv4 } from 'uuid'
import jwt from 'jsonwebtoken'
import { getDb, initializeDatabase } from '../backend/dist/db/client.js'

const NUM_PUBLISHERS = parseInt(process.env.NUM_PUBLISHERS || '20')
const NUM_SUBSCRIBERS = parseInt(process.env.NUM_SUBSCRIBERS || '30')
const ROOM_ID = process.env.ROOM_ID || 'stress-test'
const BACKEND_URL = process.env.BACKEND_URL || 'ws://localhost:8080/ws'
const JWT_SECRET = 'dev-secret-change-in-production'

console.log(`[Stress Test] ${NUM_PUBLISHERS} publishers, ${NUM_SUBSCRIBERS} subscribers`)

initializeDatabase()
const db = getDb()

const users = []

const adjectives = ['Swift', 'Bright', 'Calm', 'Bold', 'Cool', 'Eager', 'Fierce', 'Gentle', 'Quick', 'Silent']
const nouns = ['Fox', 'Owl', 'Bear', 'Wolf', 'Hawk', 'Deer', 'Lion', 'Tiger', 'Eagle', 'Falcon']

function randomName(index) {
  return `${adjectives[index % adjectives.length]}${nouns[index % nouns.length]}${index}`
}

function createRealToken(userId, email) {
  return jwt.sign({ userId, email }, JWT_SECRET, { expiresIn: '7d' })
}

async function createAndConnect(index, role) {
  const userId = `stress-${role[0]}${index}-${uuidv4().slice(0, 8)}`
  const name = randomName(index)
  
  db.prepare(`
    INSERT OR IGNORE INTO users (id, email, password_hash, display_name, display_color)
    VALUES (?, ?, ?, ?, ?)
  `).run(userId, `${userId}@stresstest.local`, '$2b$10$placeholder', name, '#ff0000')
  
  const token = createRealToken(userId, `${userId}@stresstest.local`)
  
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`${BACKEND_URL}?token=${token}&userId=${userId}&name=${encodeURIComponent(name)}&room=${ROOM_ID}`, {
      handshakeTimeout: 5000,
    })
    
    const timeout = setTimeout(() => {
      console.log(`[${role}] ${name} connection timeout`)
      ws.close()
      reject(new Error('timeout'))
    }, 8000)
    
    ws.on('open', () => {
      clearTimeout(timeout)
      console.log(`[${role.toUpperCase()}] ${name} connected!`)
      ws.send(JSON.stringify({ type: 'call/join', roomId: ROOM_ID }))
      resolve({ userId, token, name, ws, role })
    })
    
    ws.on('message', (data) => {
      try {
        const msg = JSON.parse(data.toString())
        if (msg.type === 'call/joined') {
          console.log(`[${role.toUpperCase()}] ${name} joined call`)
        }
      } catch {}
    })
    
    ws.on('close', (code, reason) => {
      console.log(`[${role.toUpperCase()}] ${name} closed (code=${code})`)
    })
    
    ws.on('error', (err) => {
      clearTimeout(timeout)
      console.log(`[${role.toUpperCase()}] ${name} error: ${err.message}`)
      reject(err)
    })
  })
}

async function runTest() {
  console.log('[Stress Test] Connecting...')
  
  for (let i = 0; i < NUM_PUBLISHERS; i++) {
    try {
      const u = await createAndConnect(i, 'publisher')
      users.push(u)
    } catch (e) {
      console.log(`Publisher ${i} failed: ${e.message}`)
    }
  }
  
  for (let i = 0; i < NUM_SUBSCRIBERS; i++) {
    try {
      const u = await createAndConnect(NUM_PUBLISHERS + i, 'subscriber')
      users.push(u)
    } catch (e) {
      console.log(`Subscriber ${i} failed: ${e.message}`)
    }
  }
  
  const connected = users.filter(u => u.ws.readyState === WebSocket.OPEN).length
  console.log(`\n[Stress Test] Connected: ${connected}/${users.length}`)
  
  setTimeout(() => {
    const final = users.filter(u => u.ws.readyState === WebSocket.OPEN).length
    console.log(`Final: ${final}/${users.length} connected`)
    for (const u of users) { u.ws.close() }
    process.exit(0)
  }, 10000)
}

runTest().catch(e => {
  console.error('Failed:', e)
  process.exit(1)
})