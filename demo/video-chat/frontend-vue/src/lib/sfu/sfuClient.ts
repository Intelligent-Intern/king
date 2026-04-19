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
  publisherName: string
  tracks: SFUTrack[]
}

export interface SFUEncodedFrame {
  publisherId: string
  trackId: string
  timestamp: number
  data: ArrayBuffer
  type: 'keyframe' | 'delta'
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

    const failToNextCandidate = () => {
      if (generation !== this.connectGeneration) return
      if (opened) return
      if (this.ws === ws) this.ws = null
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
      this.send({ type: 'sfu/join', roomId, role: 'publisher' })
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
      try {
        this.ws.close()
      } catch {}
      this.ws = null
    }

    const query    = new URLSearchParams({
      room: roomId,
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
      this.send({ type: 'sfu/publish', trackId: t.id, kind: t.kind, label: t.label })
    }
  }

  subscribe(publisherId: string): void {
    this.send({ type: 'sfu/subscribe', publisherId })
  }

  unpublishTrack(trackId: string): void {
    this.send({ type: 'sfu/unpublish', trackId })
  }

  sendEncodedFrame(frame: SFUEncodedFrame): void {
    const payload = {
      type: 'sfu/frame',
      publisherId: frame.publisherId,
      trackId: frame.trackId,
      timestamp: frame.timestamp,
      data: Array.from(new Uint8Array(frame.data)),
      frameType: frame.type,
    }
    this.send(payload)
  }

  leave(): void {
    this.connectGeneration += 1
    this.disconnectNotified = false
    this.send({ type: 'sfu/leave' })
    this.ws?.close()
    this.ws = null
  }

  private send(msg: object): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(msg))
    }
  }

  private handleMessage(msg: any): void {
    switch (msg.type) {
      case 'sfu/joined':
        for (const publisherId of (msg.publishers ?? [])) {
          this.subscribe(publisherId)
        }
        break

      case 'sfu/tracks':
        this.cb.onTracks({
          roomId:        msg.roomId,
          publisherId:   msg.publisherId,
          publisherName: msg.publisherName,
          tracks:        msg.tracks ?? [],
        })
        break

      case 'sfu/unpublished':
        this.cb.onUnpublished(msg.publisherId, msg.trackId)
        break

      case 'sfu/publisher_left':
        this.cb.onPublisherLeft(msg.publisherId)
        break

      case 'sfu/frame':
        if (this.cb.onEncodedFrame) {
          this.cb.onEncodedFrame({
            publisherId: msg.publisherId,
            trackId: msg.trackId,
            timestamp: msg.timestamp,
            data: new Uint8Array(msg.data).buffer,
            type: msg.frameType,
          })
        }
        break
    }
  }
}
