import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { once } from 'node:events'
import fs from 'node:fs'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { WebSocket } from 'ws'
import { IIBINDecoder, IIBINEncoder, MessageType } from '@intelligentintern/iibin'

const backendDir = path.dirname(fileURLToPath(import.meta.url))
const host = '127.0.0.1'

let port = 0
let baseUrl = ''
let wsBaseUrl = ''
let dbRoot = ''
let backendProcess = null
let backendLogs = ''

class SignalClient {
  constructor(socket) {
    this.socket = socket
    this.messages = []
    this.encoder = new IIBINEncoder()

    socket.on('message', (raw, isBinary) => {
      const decoded = decodeFrame(raw, isBinary)
      if (decoded && typeof decoded === 'object') {
        this.messages.push(decoded)
      }
    })
  }

  static async connect(url) {
    const socket = new WebSocket(url)
    await new Promise((resolve, reject) => {
      const onOpen = () => {
        socket.off('error', onError)
        resolve()
      }
      const onError = (error) => {
        socket.off('open', onOpen)
        reject(error)
      }

      socket.once('open', onOpen)
      socket.once('error', onError)
    })

    return new SignalClient(socket)
  }

  sendJson(type, data = {}) {
    this.socket.send(JSON.stringify({
      type,
      ...data,
    }))
  }

  sendIibin(type, data = {}) {
    const payload = this.encoder.encode({
      type: MessageType.TEXT_MESSAGE,
      data: {
        type,
        ...data,
      },
      timestamp: Date.now(),
    })
    this.socket.send(Buffer.from(new Uint8Array(payload)))
  }

  async waitFor(predicate, timeoutMs = 8000) {
    const startedAt = Date.now()

    while ((Date.now() - startedAt) <= timeoutMs) {
      const index = this.messages.findIndex(predicate)
      if (index >= 0) {
        return this.messages.splice(index, 1)[0]
      }

      await delay(25)
    }

    throw new Error(`Timed out waiting for websocket frame. Recent frames: ${JSON.stringify(this.messages.slice(-6))}`)
  }

  async close() {
    if (this.socket.readyState === WebSocket.CLOSED || this.socket.readyState === WebSocket.CLOSING) {
      return
    }

    this.socket.close()
    await Promise.race([
      once(this.socket, 'close'),
      delay(1000),
    ])
  }
}

function decodeFrame(raw, isBinary) {
  if (!isBinary) {
    try {
      return JSON.parse(String(raw))
    } catch {
      return null
    }
  }

  const bytes = toBuffer(raw)
  if (!bytes) {
    return null
  }

  const buffer = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength)
  try {
    const decoded = new IIBINDecoder(buffer).decode()
    if (decoded.type === MessageType.TEXT_MESSAGE && decoded.data && typeof decoded.data === 'object') {
      return decoded.data
    }
  } catch {
    return null
  }

  return null
}

function toBuffer(value) {
  if (Buffer.isBuffer(value)) {
    return value
  }
  if (value instanceof ArrayBuffer) {
    return Buffer.from(value)
  }
  if (ArrayBuffer.isView(value)) {
    return Buffer.from(value.buffer, value.byteOffset, value.byteLength)
  }
  return null
}

async function reservePort() {
  return await new Promise((resolve, reject) => {
    const server = net.createServer()
    server.once('error', reject)
    server.listen(0, host, () => {
      const address = server.address()
      const resolvedPort = typeof address === 'object' && address ? address.port : 0
      server.close((error) => {
        if (error) {
          reject(error)
          return
        }
        resolve(resolvedPort)
      })
    })
  })
}

async function waitForHealthyProcess(timeoutMs = 12000) {
  const startedAt = Date.now()
  while ((Date.now() - startedAt) <= timeoutMs) {
    if (backendProcess?.exitCode !== null && backendProcess?.exitCode !== undefined) {
      throw new Error(`Backend exited during startup. Logs:\n${backendLogs}`)
    }

    try {
      const response = await fetch(`${baseUrl}/health`)
      if (response.ok) {
        return
      }
    } catch {
      // Retry until timeout.
    }

    await delay(100)
  }

  throw new Error(`Backend did not become healthy in time. Logs:\n${backendLogs}`)
}

async function requestJson(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: {
      'content-type': 'application/json',
      ...(options.headers || {}),
    },
  })

  let payload = null
  try {
    payload = await response.json()
  } catch {
    payload = null
  }

  return { response, payload }
}

async function ensureRoom(roomId, name) {
  const { response } = await requestJson(`${baseUrl}/api/rooms`, {
    method: 'POST',
    body: JSON.stringify({ id: roomId, name }),
  })
  assert.ok([201, 409].includes(response.status))
}

before(async () => {
  port = await reservePort()
  baseUrl = `http://${host}:${port}`
  wsBaseUrl = `ws://${host}:${port}/ws`
  dbRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'king-video-chat-contract-'))

  backendProcess = spawn(process.execPath, ['dev-backend.mjs'], {
    cwd: backendDir,
    env: {
      ...process.env,
      KING_DEMO_HOST: host,
      KING_DEMO_PORT: String(port),
      KING_DEMO_DB_PATH: path.join(dbRoot, 'video-chat.sqlite'),
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  })

  backendProcess.stdout.on('data', (chunk) => {
    backendLogs += chunk.toString()
  })
  backendProcess.stderr.on('data', (chunk) => {
    backendLogs += chunk.toString()
  })

  await waitForHealthyProcess()
})

after(async () => {
  if (backendProcess && backendProcess.exitCode === null) {
    backendProcess.kill('SIGTERM')
    await Promise.race([
      once(backendProcess, 'exit'),
      delay(3000),
    ])
    if (backendProcess.exitCode === null) {
      backendProcess.kill('SIGKILL')
    }
  }

  if (dbRoot) {
    fs.rmSync(dbRoot, { recursive: true, force: true })
  }
})

describe('video-chat backend contract', () => {
  it('serves health/auth/room/invite API contract', async () => {
    const health = await requestJson(`${baseUrl}/health`, { method: 'GET', headers: {} })
    assert.equal(health.response.status, 200)
    assert.equal(health.payload?.ok, true)
    assert.equal(health.payload?.service, 'king-video-chat-backend')

    const loginA = await requestJson(`${baseUrl}/api/auth/login`, {
      method: 'POST',
      body: JSON.stringify({
        userId: 'contract-a',
        name: 'Contract A',
        color: '#112233',
      }),
    })
    assert.equal(loginA.response.status, 200)
    assert.equal(loginA.payload?.session?.userId, 'contract-a')
    assert.equal(loginA.payload?.session?.name, 'Contract A')

    const loginB = await requestJson(`${baseUrl}/api/auth/login`, {
      method: 'POST',
      body: JSON.stringify({
        userId: 'contract-b',
        name: 'Contract B',
        color: '#223344',
      }),
    })
    assert.equal(loginB.response.status, 200)
    assert.equal(loginB.payload?.session?.userId, 'contract-b')

    const users = await requestJson(`${baseUrl}/api/users`, { method: 'GET', headers: {} })
    assert.equal(users.response.status, 200)
    assert.ok(Array.isArray(users.payload?.users))
    assert.ok(users.payload.users.some((entry) => entry.userId === 'contract-a'))
    assert.ok(users.payload.users.some((entry) => entry.userId === 'contract-b'))

    const rooms = await requestJson(`${baseUrl}/api/rooms`, { method: 'GET', headers: {} })
    assert.equal(rooms.response.status, 200)
    assert.ok(Array.isArray(rooms.payload?.rooms))
    assert.ok(rooms.payload.rooms.some((entry) => entry.id === 'lobby'))

    const createRoom = await requestJson(`${baseUrl}/api/rooms`, {
      method: 'POST',
      body: JSON.stringify({ id: 'contract-room', name: 'Contract Room' }),
    })
    assert.equal(createRoom.response.status, 201)
    assert.equal(createRoom.payload?.room?.id, 'contract-room')

    const conflictRoom = await requestJson(`${baseUrl}/api/rooms`, {
      method: 'POST',
      body: JSON.stringify({ id: 'contract-room', name: 'Contract Room' }),
    })
    assert.equal(conflictRoom.response.status, 409)

    const invite = await requestJson(`${baseUrl}/api/rooms/contract-room/invite`, {
      method: 'POST',
      body: JSON.stringify({}),
    })
    assert.equal(invite.response.status, 200)
    assert.equal(invite.payload?.inviteCode, 'contract-room')

    const redeem = await requestJson(`${baseUrl}/api/invite/redeem`, {
      method: 'POST',
      body: JSON.stringify({ code: 'contract-room' }),
    })
    assert.equal(redeem.response.status, 200)
    assert.equal(redeem.payload?.room?.id, 'contract-room')
  })

  it('serves websocket contract for room/presence/chat/call signaling', async () => {
    await ensureRoom('contract-room-ws', 'Contract Room WS')

    const alice = await SignalClient.connect(
      `${wsBaseUrl}?userId=alice-contract&name=Alice&color=%23112233&room=lobby`
    )
    const bob = await SignalClient.connect(
      `${wsBaseUrl}?userId=bob-contract&name=Bob&color=%23223344&room=lobby`
    )

    try {
      const aliceReady = await alice.waitFor((message) => message.type === 'session/ready')
      const bobReady = await bob.waitFor((message) => message.type === 'session/ready')
      assert.equal(aliceReady.me?.userId, 'alice-contract')
      assert.equal(bobReady.me?.userId, 'bob-contract')

      alice.sendJson('typing/start')
      const typingStart = await bob.waitFor((message) => {
        return message.type === 'typing/start' && message.user?.userId === 'alice-contract'
      })
      assert.equal(typingStart.roomId, 'lobby')

      alice.sendIibin('chat/send', { text: 'hello via iibin contract' })
      const chatMessage = await bob.waitFor((message) => {
        return message.type === 'chat/message' && message.sender?.userId === 'alice-contract'
      })
      assert.equal(chatMessage.text, 'hello via iibin contract')

      alice.sendJson('call/join')
      const callJoined = await bob.waitFor((message) => {
        return message.type === 'call/joined' && message.user?.userId === 'alice-contract'
      })
      assert.equal(callJoined.roomId, 'lobby')

      alice.sendJson('call/offer', {
        targetUserId: 'bob-contract',
        payload: {
          type: 'offer',
          sdp: 'v=0\\r\\ncontract-offer',
        },
      })
      const offer = await bob.waitFor((message) => {
        return message.type === 'call/offer' && message.sender?.userId === 'alice-contract'
      })
      assert.equal(offer.targetUserId, 'bob-contract')
      assert.equal(offer.payload?.type, 'offer')

      alice.sendJson('call/offer', {
        payload: {
          type: 'offer',
          sdp: 'v=0\\r\\nmissing-target',
        },
      })
      const targetError = await alice.waitFor((message) => {
        return message.type === 'error' && message.code === 'target_required'
      })
      assert.equal(targetError.code, 'target_required')

      alice.sendJson('call/leave')
      const callLeft = await bob.waitFor((message) => {
        return message.type === 'call/left' && message.user?.userId === 'alice-contract'
      })
      assert.equal(callLeft.roomId, 'lobby')

      bob.sendJson('room/switch', { roomId: 'contract-room-ws' })
      const switched = await bob.waitFor((message) => message.type === 'room/switched')
      assert.equal(switched.roomId, 'contract-room-ws')
      const roomSnapshot = await bob.waitFor((message) => {
        return message.type === 'room/snapshot' && message.roomId === 'contract-room-ws'
      })
      assert.equal(roomSnapshot.roomId, 'contract-room-ws')
      assert.ok(Array.isArray(roomSnapshot.participants))
    } finally {
      await alice.close()
      await bob.close()
    }
  })
})
