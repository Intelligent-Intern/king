export interface ManagedPeerConnection {
  onicecandidate: unknown
  ontrack: unknown
  onconnectionstatechange: unknown
  close: () => void
}

export interface PeerConnectionManager<TConnection extends ManagedPeerConnection> {
  has: (remoteUserId: string) => boolean
  get: (remoteUserId: string) => TConnection | null
  getOrCreate: (remoteUserId: string, create: () => TConnection) => TConnection
  release: (remoteUserId: string) => boolean
  releaseAll: () => string[]
  keys: () => string[]
  size: () => number
}

export function createPeerConnectionManager<TConnection extends ManagedPeerConnection>(): PeerConnectionManager<TConnection> {
  const connectionsByUserId = new Map<string, TConnection>()

  return {
    has(remoteUserId: string): boolean {
      const key = normalizeRemoteUserId(remoteUserId)
      return key !== '' && connectionsByUserId.has(key)
    },

    get(remoteUserId: string): TConnection | null {
      const key = normalizeRemoteUserId(remoteUserId)
      if (key === '') {
        return null
      }

      return connectionsByUserId.get(key) ?? null
    },

    getOrCreate(remoteUserId: string, create: () => TConnection): TConnection {
      const key = normalizeRemoteUserId(remoteUserId)
      if (key === '') {
        throw new Error('peer connection manager requires a non-empty remote user id.')
      }

      const existing = connectionsByUserId.get(key)
      if (existing) {
        return existing
      }

      const next = create()
      connectionsByUserId.set(key, next)
      return next
    },

    release(remoteUserId: string): boolean {
      const key = normalizeRemoteUserId(remoteUserId)
      if (key === '') {
        return false
      }

      const connection = connectionsByUserId.get(key)
      if (!connection) {
        return false
      }

      connectionsByUserId.delete(key)
      cleanupConnection(connection)
      connection.close()
      return true
    },

    releaseAll(): string[] {
      const releasedUserIds: string[] = []
      for (const [userId, connection] of connectionsByUserId.entries()) {
        connectionsByUserId.delete(userId)
        cleanupConnection(connection)
        connection.close()
        releasedUserIds.push(userId)
      }

      return releasedUserIds
    },

    keys(): string[] {
      return [...connectionsByUserId.keys()]
    },

    size(): number {
      return connectionsByUserId.size
    },
  }
}

function cleanupConnection(connection: ManagedPeerConnection): void {
  connection.onicecandidate = null
  connection.ontrack = null
  connection.onconnectionstatechange = null
}

function normalizeRemoteUserId(value: string): string {
  return value.trim()
}
