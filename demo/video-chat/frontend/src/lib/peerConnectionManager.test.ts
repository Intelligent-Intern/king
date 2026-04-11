import { describe, expect, it } from 'vitest'
import { createPeerConnectionManager, type ManagedPeerConnection } from './peerConnectionManager'

interface FakePeerConnection extends ManagedPeerConnection {
  closeCalls: number
}

function makeFakeConnection(): FakePeerConnection {
  return {
    onicecandidate: () => {},
    ontrack: () => {},
    onconnectionstatechange: () => {},
    closeCalls: 0,
    close() {
      this.closeCalls += 1
    },
  }
}

describe('peerConnectionManager', () => {
  it('keeps isolated peer connections keyed by remote user id', () => {
    const manager = createPeerConnectionManager<FakePeerConnection>()
    const alice = manager.getOrCreate('alice', () => makeFakeConnection())
    const bob = manager.getOrCreate('bob', () => makeFakeConnection())
    const aliceAgain = manager.getOrCreate('alice', () => makeFakeConnection())

    expect(aliceAgain).toBe(alice)
    expect(bob).not.toBe(alice)
    expect(manager.size()).toBe(2)
    expect(manager.keys().sort()).toEqual(['alice', 'bob'])
  })

  it('releases one connection with handler cleanup and close', () => {
    const manager = createPeerConnectionManager<FakePeerConnection>()
    const connection = manager.getOrCreate('alice', () => makeFakeConnection())

    expect(manager.release('alice')).toBe(true)
    expect(connection.onicecandidate).toBeNull()
    expect(connection.ontrack).toBeNull()
    expect(connection.onconnectionstatechange).toBeNull()
    expect(connection.closeCalls).toBe(1)
    expect(manager.has('alice')).toBe(false)
  })

  it('releases all tracked connections deterministically', () => {
    const manager = createPeerConnectionManager<FakePeerConnection>()
    const alice = manager.getOrCreate('alice', () => makeFakeConnection())
    const bob = manager.getOrCreate('bob', () => makeFakeConnection())

    const released = manager.releaseAll().sort()
    expect(released).toEqual(['alice', 'bob'])
    expect(alice.closeCalls).toBe(1)
    expect(bob.closeCalls).toBe(1)
    expect(manager.size()).toBe(0)
  })

  it('handles invalid or missing user ids safely', () => {
    const manager = createPeerConnectionManager<FakePeerConnection>()
    expect(manager.get('   ')).toBeNull()
    expect(manager.has('')).toBe(false)
    expect(manager.release('')).toBe(false)
  })
})
