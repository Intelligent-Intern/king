/**
 * SFU signalling client.
 *
 * Connects to /sfu, announces the local publisher, and surfaces track events
 * from remote publishers.  WebRTC offer/answer/ICE still flows through the
 * existing /ws signalling channel — this client is solely responsible for
 * track discovery and subscription bookkeeping.
 */

import {
  buildWebSocketUrl,
  resolveBackendSfuOriginCandidates,
  setBackendSfuOrigin,
} from '../../support/backendOrigin'

export interface SFUTrack {
  id: string
  kind: 'audio' | 'video'
  label: string
}

export interface SFUTracksEvent {
  roomId: string
  publisherId: string
  publisherUserId: string
  publisherName: string
  tracks: SFUTrack[]
}

export interface SFUEncodedFrame {
  publisherId: string
  publisherUserId?: string
  trackId: string
  timestamp: number
  data?: ArrayBuffer
  type: 'keyframe' | 'delta'
  protected?: Record<string, unknown> | null
  protectedFrame?: string | null
  protectionMode?: 'transport_only' | 'protected' | 'required'
}

export interface SFUClientCallbacks {
  onTracks:        (e: SFUTracksEvent) => void
  onUnpublished:   (publisherId: string, trackId: string) => void
  onPublisherLeft: (publisherId: string) => void
  onConnected?:    () => void
  onDisconnect:    () => void
  onEncodedFrame?: (frame: SFUEncodedFrame) => void
}

export class SFUClient {
  private ws: WebSocket | null = null
  private cb: SFUClientCallbacks
  private connectGeneration = 0
  private disconnectNotified = false

  constructor(cb: SFUClientCallbacks) {
    this.cb = cb
  }

  private socketUrlForOrigin(origin: string, query: URLSearchParams): string | null {
    return buildWebSocketUrl(origin, '/sfu', query)
  }

  private notifyDisconnectOnce(): void {
    if (this.disconnectNotified) return
    this.disconnectNotified = true
    this.cb.onDisconnect()
  }

  private retireSocket(ws: WebSocket, closeConnecting = false): void {
    if (this.ws === ws) {
      this.ws = null
    }

    if (ws.readyState === WebSocket.CONNECTING && !closeConnecting) {
      return
    }

    try {
      ws.close()
    } catch {}
  }

  private connectWithCandidates(
    candidates: string[],
    index: number,
    query: URLSearchParams,
    roomId: string,
    generation: number,
  ): void {
    if (generation !== this.connectGeneration) return
    if (index >= candidates.length) {
      this.notifyDisconnectOnce()
      return
    }

    const wsUrl = this.socketUrlForOrigin(candidates[index], query)
    if (!wsUrl) {
      this.connectWithCandidates(candidates, index + 1, query, roomId, generation)
      return
    }

    const ws = new WebSocket(wsUrl)
    this.ws = ws
    let opened = false
    let failedOver = false

    const failToNextCandidate = () => {
      if (generation !== this.connectGeneration) return
      if (opened) return
      if (failedOver) return
      failedOver = true
      if (this.ws === ws) {
        this.ws = null
      }
      this.connectWithCandidates(candidates, index + 1, query, roomId, generation)
    }

    ws.onopen = () => {
      if (generation !== this.connectGeneration) {
        try { ws.close() } catch {}
        return
      }
      opened = true
      this.disconnectNotified = false
      setBackendSfuOrigin(candidates[index] || '')
      this.send({ type: 'sfu/join', room_id: roomId, role: 'publisher' })
      if (this.cb.onConnected) {
        this.cb.onConnected()
      }
    }

    ws.onmessage = (ev) => {
      let msg: any
      try { msg = JSON.parse(ev.data) } catch { return }
      this.handleMessage(msg)
    }

    ws.onclose = () => {
      if (generation !== this.connectGeneration) return
      if (!opened) {
        failToNextCandidate()
        return
      }
      if (this.ws === ws) {
        this.ws = null
      }
      this.notifyDisconnectOnce()
    }

    ws.onerror = () => {
      if (generation !== this.connectGeneration) return
      if (!opened) {
        failToNextCandidate()
        return
      }
      try {
        ws.close()
      } catch {}
    }
  }

  connect(session: { userId: string; token: string; name: string }, roomId: string, callId = ''): void {
    this.connectGeneration += 1
    this.disconnectNotified = false
    const generation = this.connectGeneration

    if (this.ws) {
      this.retireSocket(this.ws)
      this.ws = null
    }

    const query    = new URLSearchParams({
      room: roomId,
      room_id: roomId,
      userId: session.userId,
      token:  session.token,
      name:   session.name,
    })
    const normalizedCallId = String(callId || '').trim()
    if (/^[A-Za-z0-9._-]{1,200}$/.test(normalizedCallId)) {
      query.set('call_id', normalizedCallId)
    }

    const candidates = resolveBackendSfuOriginCandidates()
    this.connectWithCandidates(candidates, 0, query, roomId, generation)
  }

  publishTracks(tracks: SFUTrack[]): void {
    for (const t of tracks) {
      this.send({ type: 'sfu/publish', track_id: t.id, kind: t.kind, label: t.label })
    }
  }

  subscribe(publisherId: string): void {
    this.send({ type: 'sfu/subscribe', publisher_id: publisherId })
  }

  unpublishTrack(trackId: string): void {
    this.send({ type: 'sfu/unpublish', track_id: trackId })
  }

  sendEncodedFrame(frame: SFUEncodedFrame): void {
    const payload: Record<string, unknown> = {
      type: 'sfu/frame',
      publisher_id: frame.publisherId,
      publisher_user_id: frame.publisherUserId || '',
      track_id: frame.trackId,
      timestamp: frame.timestamp,
      frame_type: frame.type,
    }
    if (frame.protectedFrame) {
      payload.protected_frame = frame.protectedFrame
      payload.protection_mode = frame.protectionMode || 'protected'
    } else {
      payload.data = Array.from(new Uint8Array(frame.data || new ArrayBuffer(0)))
      payload.protection_mode = frame.protectionMode || 'transport_only'
    }
    this.send(payload)
  }

  leave(): void {
    this.connectGeneration += 1
    this.disconnectNotified = false
    this.send({ type: 'sfu/leave' })
    if (this.ws) {
      this.retireSocket(this.ws)
    }
    this.ws = null
  }

  private send(msg: object): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(msg))
    }
  }

  private handleMessage(msg: any): void {
    const stringField = (...values: any[]): string => {
      for (const value of values) {
        const normalized = String(value ?? '').trim()
        if (normalized !== '') return normalized
      }
      return ''
    }

    switch (msg.type) {
      case 'sfu/joined':
        for (const publisherId of (msg.publishers ?? [])) {
          const normalizedPublisherId = stringField(publisherId)
          if (normalizedPublisherId !== '') {
            this.subscribe(normalizedPublisherId)
          }
        }
        break

      case 'sfu/tracks':
        this.cb.onTracks({
          roomId:          stringField(msg.roomId, msg.room_id),
          publisherId:     stringField(msg.publisherId, msg.publisher_id),
          publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id),
          publisherName:   stringField(msg.publisherName, msg.publisher_name),
          tracks:        msg.tracks ?? [],
        })
        break

      case 'sfu/unpublished':
        this.cb.onUnpublished(
          stringField(msg.publisherId, msg.publisher_id),
          stringField(msg.trackId, msg.track_id),
        )
        break

      case 'sfu/publisher_left':
        this.cb.onPublisherLeft(stringField(msg.publisherId, msg.publisher_id))
        break

      case 'sfu/frame':
        if (this.cb.onEncodedFrame) {
          const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)
          this.cb.onEncodedFrame({
            publisherId: stringField(msg.publisherId, msg.publisher_id),
            publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id),
            trackId: stringField(msg.trackId, msg.track_id),
            timestamp: msg.timestamp,
            data: Array.isArray(msg.data) ? new Uint8Array(msg.data).buffer : new ArrayBuffer(0),
            type: stringField(msg.frameType, msg.frame_type) === 'keyframe' ? 'keyframe' : 'delta',
            protected: msg.protected && typeof msg.protected === 'object' ? msg.protected : null,
            protectedFrame: protectedFrame || null,
            protectionMode: stringField(msg.protectionMode, msg.protection_mode) === 'required'
              ? 'required'
              : (protectedFrame !== '' ? 'protected' : 'transport_only'),
          })
        }
        break
    }
  }
}
