/**
 * SFU signalling client.
 *
 * Connects to /sfu, announces the local publisher, and surfaces track events
 * from remote publishers.  WebRTC offer/answer/ICE still flows through the
 * existing /ws signalling channel — this client is solely responsible for
 * track discovery and subscription bookkeeping.
 */

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
  onDisconnect:    () => void
  onEncodedFrame?: (frame: SFUEncodedFrame) => void
}

export class SFUClient {
  private ws: WebSocket | null = null
  private cb: SFUClientCallbacks

  constructor(cb: SFUClientCallbacks) {
    this.cb = cb
  }

  connect(session: { userId: string; token: string; name: string }, roomId: string): void {
    const protocol = window.location.protocol === 'https:' ? 'wss' : 'ws'
    const host     = window.location.host || '127.0.0.1:3000'
    const query    = new URLSearchParams({
      userId: session.userId,
      token:  session.token,
      name:   session.name,
    })

    this.ws = new WebSocket(`${protocol}://${host}/sfu?${query}`)

    this.ws.onopen = () => {
      this.send({ type: 'sfu/join', roomId, role: 'publisher' })
    }

    this.ws.onmessage = (ev) => {
      let msg: any
      try { msg = JSON.parse(ev.data) } catch { return }
      this.handleMessage(msg)
    }

    this.ws.onclose  = () => this.cb.onDisconnect()
    this.ws.onerror  = () => this.cb.onDisconnect()
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
