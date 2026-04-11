import { describe, expect, it } from 'vitest'
import { appendFanoutChatMessage, normalizeFanoutChatMessage, type FanoutChatMessage } from './chatFanout'

describe('chatFanout helpers', () => {
  it('normalizes valid server fanout chat payloads', () => {
    const message = normalizeFanoutChatMessage({
      id: 'msg-1',
      roomId: ' Team / Room ',
      sender: {
        userId: 'u-1',
        name: ' Ada ',
      },
      text: ' hello world ',
      serverTime: '12',
    }, 'lobby')

    expect(message).toEqual({
      id: 'msg-1',
      roomId: 'team---room',
      sender: {
        userId: 'u-1',
        name: 'Ada',
      },
      text: 'hello world',
      serverTime: 12,
    })
  })

  it('rejects malformed messages without sender, id, or text', () => {
    expect(normalizeFanoutChatMessage({
      id: 'msg-2',
      sender: { userId: '', name: 'Test' },
      text: 'ok',
    }, 'lobby')).toBeNull()

    expect(normalizeFanoutChatMessage({
      id: '',
      sender: { userId: 'u-1' },
      text: 'ok',
    }, 'lobby')).toBeNull()

    expect(normalizeFanoutChatMessage({
      id: 'msg-3',
      sender: { userId: 'u-1' },
      text: '   ',
    }, 'lobby')).toBeNull()
  })

  it('deduplicates fanout messages by message id in one room list', () => {
    const list: FanoutChatMessage[] = []
    const message = normalizeFanoutChatMessage({
      id: 'msg-4',
      roomId: 'lobby',
      sender: { userId: 'u-1', name: 'Ada' },
      text: 'hello',
      serverTime: 2,
    }, 'lobby')

    if (!message) {
      throw new Error('expected normalized message')
    }

    expect(appendFanoutChatMessage(list, message)).toBe(true)
    expect(appendFanoutChatMessage(list, message)).toBe(false)
    expect(list).toHaveLength(1)
  })
})
