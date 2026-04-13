import WebSocket from 'ws'
import { v4 as uuidv4 } from 'uuid'
import { generateAccessToken } from '../backend/src/auth/jwt.js'
import { getDb, initializeDatabase } from '../backend/src/db/client.js'

const NUM_PUBLISHERS = parseInt(process.env.NUM_PUBLISHERS || '20')
const NUM_SUBSCRIBERS = parseInt(process.env.NUM_SUBSCRIBERS || '50')
const ROOM_ID = process.env.ROOM_ID || 'stress-test'
const BACKEND_URL = process.env.BACKEND_URL || 'ws://localhost:8080/ws'
const DELAY_MS = parseInt(process.env.DELAY_MS || '50')

console.log(`[Stress Test] Publishers: ${NUM_PUBLISHERS}, Subscribers: ${NUM_SUBSCRIBERS}`)

initializeDatabase()
const db = getDb()

const users = []

const adjectives = ['Swift', 'Bright', 'Calm', 'Bold', 'Cool', 'Eager', 'Fierce', 'Gentle', 'Quick', 'Silent']
const nouns = ['Fox', 'Owl', 'Bear', 'Wolf', 'Hawk', 'Deer', 'Lion', 'Tiger', 'Eagle', 'Falcon']

function randomName(index) {
  return `${adjectives[index % adjectives.length]}${nouns[index % nouns.length]}${index}`
}

async function createTestUser(index, role) {
  const userId = `stress-${role[0]}${index}-${uuidv4().slice(0, 8)}`
  const name = randomName(index)
  const color = `#${Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0')}`
  
  const existing = db.prepare('SELECT id FROM users WHERE id = ?').get(userId)
  if (!existing) {
    db.prepare(`
      INSERT INTO users (id, email, password_hash, display_name, display_color)
      VALUES (?, ?, ?, ?, ?)
    `).run(userId, `${userId}@stresstest.local`, '$2b$10$placeholder', name, color)
  }
  
  const { token } = generateAccessToken(userId, `${userId}@stresstest.local`)
  return { userId, token, name }
}

function connectClient(user, role) {
  return new Promise((resolve, reject) => {
    const startTime = Date.now()
    const ws = new WebSocket(`${BACKEND_URL}?token=${user.token}&userId=${user.userId}&name=${encodeURIComponent(user.name)}`)
    
    const timeout = setTimeout(() => {
      ws.close()
      reject(new Error('Connection timeout'))
    }, 10000)
    
    ws.on('open', () => {
      clearTimeout(timeout)
      ws.send(JSON.stringify({ type: 'room/switch', roomId: ROOM_ID }))
    })
    
    ws.on('message', (data) => {
      try {
        const msg = JSON.parse(data.toString())
        if (msg.type === 'room/switched') {
          setTimeout(() => {
            ws.send(JSON.stringify({ type: 'call/join', roomId: ROOM_ID }))
          }, 100 + Math.random() * 200)
        }
        if (msg.type === 'call/joined') {
          console.log(`[${role.toUpperCase()}] ${user.name} joined call (${Date.now() - startTime}ms)`)
        }
      } catch {}
    })
    
    ws.on('error', (err) => {
      clearTimeout(timeout)
      reject(err)
    })
    
    ws.on('close', () => {
      console.log(`[${role.toUpperCase()}] ${user.name} disconnected`)
    })
    
    resolve(ws)
  })
}

async function runTest() {
  console.log('[Stress Test] Creating publisher users...')
  
  for (let i = 0; i < NUM_PUBLISHERS; i++) {
    const user = await createTestUser(i, 'publisher')
    try {
      const ws = await connectClient(user, 'publisher')
      users.push({ ...user, ws, role: 'publisher' })
      console.log(`[Stress Test] Publisher ${i + 1}/${NUM_PUBLISHERS} connected`)
    } catch (e) {
      console.error(`[Stress Test] Publisher ${i} failed:`, e)
    }
    await new Promise(r => setTimeout(r, DELAY_MS))
  }
  
  console.log('[Stress Test] Creating subscriber users...')
  
  for (let i = 0; i < NUM_SUBSCRIBERS; i++) {
    const user = await createTestUser(NUM_PUBLISHERS + i, 'subscriber')
    try {
      const ws = await connectClient(user, 'subscriber')
      users.push({ ...user, ws, role: 'subscriber' })
      if ((i + 1) % 10 === 0) {
        console.log(`[Stress Test] Subscriber ${i + 1}/${NUM_SUBSCRIBERS} connected`)
      }
    } catch (e) {
      console.error(`[Stress Test] Subscriber ${i} failed:`, e)
    }
    await new Promise(r => setTimeout(r, DELAY_MS))
  }
  
  const publishers = users.filter(u => u.role === 'publisher').length
  const subscribers = users.filter(u => u.role === 'subscriber').length
  
  console.log(`\n[Stress Test] Running: ${publishers} publishers, ${subscribers} subscribers`)
  console.log('[Stress Test] Monitoring...')
  
  let iteration = 0
  const interval = setInterval(() => {
    iteration++
    const connected = users.filter(u => u.ws.readyState === WebSocket.OPEN).length
    process.stdout.write(`\r[${new Date().toISOString()}] Connected: ${connected}/${users.length}     `)
    
    if (iteration % 30 === 0) {
      console.log('\n[Stress Test] Stats:')
      console.log(`  - Total connections: ${users.length}`)
      console.log(`  - Active: ${connected}`)
    }
  }, 2000)
  
  setTimeout(() => {
    clearInterval(interval)
    console.log('\n[Stress Test] Test duration complete (2min), disconnecting...')
    for (const user of users) {
      user.ws.close()
    }
    process.exit(0)
  }, 120000)
}

runTest().catch(e => {
  console.error('[Stress Test] Failed:', e)
  process.exit(1)
})

process.on('SIGINT', () => {
  console.log('\n[Stress Test] Interrupted, disconnecting...')
  for (const user of users) {
    user.ws.close()
  }
  process.exit(0)
})