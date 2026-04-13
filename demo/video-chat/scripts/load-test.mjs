import WebSocket from 'ws'
import { v4 as uuidv4 } from 'uuid'
import { generateAccessToken, generateSessionToken } from '../backend/src/auth/jwt.js'
import { getDb, initializeDatabase } from '../backend/src/db/client.js'

const NUM_USERS = parseInt(process.env.NUM_USERS || '50')
const USE_WAVELET = process.env.USE_WAVELET === 'true'
const ROOM_ID = process.env.ROOM_ID || 'lobby'
const BACKEND_URL = process.env.BACKEND_URL || 'ws://localhost:8080/ws'

console.log(`[Load Test] Starting ${NUM_USERS} users, wavelet=${USE_WAVELET}, room=${ROOM_ID}`)

initializeDatabase()
const db = getDb()

const users: { userId: string; token: string; ws: WebSocket }[] = []

function randomName(): string {
  const adjectives = ['Swift', 'Bright', 'Calm', 'Bold', 'Cool', 'Eager', 'Fierce', 'Gentle']
  const nouns = ['Fox', 'Owl', 'Bear', 'Wolf', 'Hawk', 'Deer', 'Lion', 'Tiger']
  return `${adjectives[Math.floor(Math.random() * adjectives.length)]}${nouns[Math.floor(Math.random() * nouns.length)]}${Math.floor(Math.random() * 100)}`
}

async function createUser(index: number) {
  const userId = `load-test-${index}-${uuidv4().slice(0, 8)}`
  const name = randomName()
  const color = `#${Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0')}`
  
  const existing = db.prepare('SELECT id FROM users WHERE id = ?').get(userId)
  if (!existing) {
    db.prepare(`
      INSERT INTO users (id, email, password_hash, display_name, display_color)
      VALUES (?, ?, ?, ?, ?)
    `).run(userId, `${userId}@loadtest.local`, '$2b$10$placeholder', name, color)
  }
  
  const { token } = generateAccessToken(userId, `${userId}@loadtest.local`)
  return { userId, token, name }
}

async function connectUser(user: { userId: string; token: string; name: string }, index: number): Promise<WebSocket> {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`${BACKEND_URL}?token=${user.token}&userId=${user.userId}&name=${encodeURIComponent(user.name)}`)
    
    ws.on('open', () => {
      console.log(`[User ${index}] Connected`)
      
      ws.send(JSON.stringify({
        type: 'room/switch',
        roomId: ROOM_ID,
      }))
      
      setTimeout(() => {
        ws.send(JSON.stringify({
          type: 'call/join',
          roomId: ROOM_ID,
        }))
      }, 500 + Math.random() * 1000)
      
      resolve(ws)
    })
    
    ws.on('message', (data) => {
      try {
        const msg = JSON.parse(data.toString())
        if (msg.type === 'room/snapshot') {
          console.log(`[User ${index}] Joined room, participants: ${msg.participants?.length || 0}`)
        }
      } catch {}
    })
    
    ws.on('error', (err) => {
      console.error(`[User ${index}] Error:`, err.message)
      reject(err)
    })
    
    ws.on('close', () => {
      console.log(`[User ${index}] Disconnected`)
    })
  })
}

async function main() {
  console.log('[Load Test] Creating users...')
  
  for (let i = 0; i < NUM_USERS; i++) {
    const user = await createUser(i)
    const ws = await connectUser(user, i)
    users.push({ ...user, ws })
    
    if ((i + 1) % 10 === 0) {
      console.log(`[Load Test] Connected ${i + 1}/${NUM_USERS}`)
    }
    
    await new Promise(r => setTimeout(r, 100))
  }
  
  console.log(`[Load Test] All ${NUM_USERS} users connected`)
  
  let joinedCount = 0
  for (const user of users) {
    await new Promise(r => setTimeout(r, 200))
    user.ws.send(JSON.stringify({ type: 'call/join', roomId: ROOM_ID }))
    joinedCount++
    if (joinedCount % 10 === 0) {
      console.log(`[Load Test] ${joinedCount} joined call`)
    }
  }
  
  console.log(`[Load Test] Load test running... press Ctrl+C to stop`)
  
  setInterval(() => {
    const stats = {
      connected: users.filter(u => u.ws.readyState === WebSocket.OPEN).length,
    }
    process.stdout.write(`\r[Load Test] Connected: ${stats.connected}/${NUM_USERS}  `)
  }, 2000)
}

main().catch(console.error)

process.on('SIGINT', () => {
  console.log('\n[Load Test] Disconnecting...')
  for (const user of users) {
    user.ws.close()
  }
  process.exit(0)
})