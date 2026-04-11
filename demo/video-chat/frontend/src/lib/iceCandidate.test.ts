import { describe, expect, it } from 'vitest'
import { normalizeInboundIceCandidate } from './iceCandidate'

describe('iceCandidate helpers', () => {
  it('normalizes valid RTC candidate payloads', () => {
    expect(normalizeInboundIceCandidate({
      candidate: ' candidate:1 1 udp 2122260223 192.0.2.1 12345 typ host ',
      sdpMid: ' 0 ',
      sdpMLineIndex: 0,
      usernameFragment: ' abc ',
    })).toEqual({
      candidate: 'candidate:1 1 udp 2122260223 192.0.2.1 12345 typ host',
      sdpMid: '0',
      sdpMLineIndex: 0,
      usernameFragment: 'abc',
    })
  })

  it('returns null for invalid or empty candidates', () => {
    expect(normalizeInboundIceCandidate(null)).toBeNull()
    expect(normalizeInboundIceCandidate({})).toBeNull()
    expect(normalizeInboundIceCandidate({ candidate: '   ' })).toBeNull()
  })

  it('sanitizes optional fields into null when malformed', () => {
    expect(normalizeInboundIceCandidate({
      candidate: 'candidate:2',
      sdpMid: 3,
      sdpMLineIndex: -1,
      usernameFragment: '',
    })).toEqual({
      candidate: 'candidate:2',
      sdpMid: null,
      sdpMLineIndex: null,
      usernameFragment: null,
    })
  })
})
