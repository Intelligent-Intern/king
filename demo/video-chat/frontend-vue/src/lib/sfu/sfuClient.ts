/**
 * SFU signalling client.
 *
 * Connects to /sfu, announces the local publisher, and surfaces track events
 * from remote publishers.  WebRTC offer/answer/ICE still flows through the
 * existing /ws signalling channel — this client is solely responsible for
 * track discovery and subscription bookkeeping.
 *
 * Binary protocol using IIBIN-like format:
 *   [1 byte] message type
 *   [payload...]
 */

import {
  buildWebSocketUrl,
  resolveBackendSfuOriginCandidates,
  setBackendSfuOrigin,
} from '../../support/backendOrigin'

enum SFUMessageType {
  JOIN = 0x01,
  JOINED = 0x02,
  PUBLISH = 0x03,
  PUBLISHED = 0x04,
  UNPUBLISH = 0x05,
  UNPUBLISHED = 0x06,
  SUBSCRIBE = 0x07,
  SUBSCRIBED = 0x08,
  TRACKS = 0x09,
  FRAME = 0x0A,
  PUBLISHER_LEFT = 0x0B,
  LEAVE = 0x0C,
  WELCOME = 0x0D,
  ERROR = 0xFF,
}

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
  data: ArrayBuffer
  type: 'keyframe' | 'delta'
}

export interface SFUClientCallbacks {
  onTracks:        (e: SFUTracksEvent) => void
  onUnpublished:   (publisherId: string, trackId: string) => void
  onPublisherLeft: (publisherId: string) => void
  onConnected?:    () => void
  onDisconnect:  () => void
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

  private encodeVarint(value: number): Uint8Array {
    const result: number[] = []
    while (value > 0x7F) {
      result.push((value & 0x7F) | 0x80)
      value >>>= 7
    }
    result.push(value & 0x7F)
    return new Uint8Array(result)
  }

  private decodeVarint(data: Uint8Array, pos: number): { value: number; newPos: number } {
    let value = 0
    let shift = 0
    while (pos < data.length) {
      const b = data[pos++]
      value |= (b & 0x7F) << shift
      if ((b & 0x80) === 0) break
      shift += 7
    }
    return { value, newPos: pos }
  }

  private encodeString(str: string): Uint8Array {
    const encoder = new TextEncoder()
    const encoded = encoder.encode(str)
    const len = this.encodeVarint(encoded.length)
    const result = new Uint8Array(len.length + encoded.length)
    result.set(len)
    result.set(encoded, len.length)
    return result
  }

  private decodeString(data: Uint8Array, pos: number): { value: string; newPos: number } {
    const lenInfo = this.decodeVarint(data, pos)
    pos = lenInfo.newPos
    const bytes = data.slice(pos, pos + lenInfo.value)
    const decoder = new TextDecoder()
    return { value: decoder.decode(bytes), newPos: pos + lenInfo.value }
  }

  private encodeSFUJoin(roomId: string, role: string): Uint8Array {
    const parts: Uint8Array[] = [new Uint8Array([SFUMessageType.JOIN])]
    parts.push(this.encodeString(roomId))
    parts.push(this.encodeString(role))
    return this.concatUint8Arrays(parts)
  }

  private encodeSFUPublish(trackId: string, kind: string, label: string): Uint8Array {
    const parts: Uint8Array[] = [new Uint8Array([SFUMessageType.PUBLISH])]
    parts.push(this.encodeString(trackId))
    parts.push(this.encodeString(kind))
    parts.push(this.encodeString(label))
    return this.concatUint8Arrays(parts)
  }

  private encodeSFUSubscribe(publisherId: string): Uint8Array {
    return new Uint8Array([SFUMessageType.SUBSCRIBE, ...this.encodeString(publisherId).slice(0)])
  }

  private encodeSFUUnpublish(trackId: string): Uint8Array {
    return this.concatUint8Arrays([
      new Uint8Array([SFUMessageType.UNPUBLISH]),
      this.encodeString(trackId),
    ])
  }

  private encodeSFULeave(): Uint8Array {
    return new Uint8Array([SFUMessageType.LEAVE])
  }

  private encodeSFUFrame(frame: SFUEncodedFrame): Uint8Array {
    const frameData = new Uint8Array(frame.data)
    const frameType = frame.type === 'keyframe' ? 1 : 0
    const magic = 0x574C5643
    const trackIdBytes = new TextEncoder().encode(frame.trackId.slice(0, 8).padEnd(8, '\0'))
    const payload = new ArrayBuffer(24 + frameData.length)
    const view = new DataView(payload)
    view.setUint32(0, magic, false)
    view.setUint8(4, frameType)
    view.setUint32(8, frame.timestamp, false)
    view.setUint32(12, frameData.length, false)
    new Uint8Array(payload, 16, 8).set(trackIdBytes)
    new Uint8Array(payload, 24).set(frameData)
    return new Uint8Array(payload)
  }

  private concatUint8Arrays(arrays: (Uint8Array | number[])[]): Uint8Array {
    let totalLen = 0
    for (const a of arrays) totalLen += a.length
    const result = new Uint8Array(totalLen)
    let pos = 0
    for (const a of arrays) {
      result.set(a, pos)
      pos += a.length
    }
    return result
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
      this.sendBinary(this.encodeSFUJoin(roomId, 'publisher'))
      if (this.cb.onConnected) {
        this.cb.onConnected()
      }
    }

    ws.binaryType = 'arraybuffer'

    ws.onmessage = (ev) => {
      if (ev.data instanceof ArrayBuffer) {
        this.handleBinaryMessage(new Uint8Array(ev.data))
        return
      }
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
      this.sendBinary(this.encodeSFUPublish(t.id, t.kind, t.label))
    }
  }

  subscribe(publisherId: string): void {
    this.sendBinary(this.encodeSFUSubscribe(publisherId))
  }

  unpublishTrack(trackId: string): void {
    this.sendBinary(this.encodeSFUUnpublish(trackId))
  }

  sendEncodedFrame(frame: SFUEncodedFrame): void {
    this.sendBinary(this.encodeSFUFrame(frame))
  }

  private sendBinary(data: Uint8Array): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(data.buffer)
    }
  }

  leave(): void {
    this.connectGeneration += 1
    this.disconnectNotified = false
    this.sendBinary(this.encodeSFULeave())
    if (this.ws) {
      this.retireSocket(this.ws)
    }
    this.ws = null
  }

  private handleBinaryMessage(data: Uint8Array): void {
    if (data.length < 1) return
    const msgType = data[0]
    let pos = 1

    switch (msgType) {
      case SFUMessageType.WELCOME:
      case SFUMessageType.JOINED: {
        const roomInfo = this.decodeString(data, pos)
        pos = roomInfo.newPos
        const publishers: string[] = []
        while (pos < data.length) {
          const pubInfo = this.decodeString(data, pos)
          publishers.push(pubInfo.value)
          pos = pubInfo.newPos
        }
        if (msgType === SFUMessageType.JOINED) {
          for (const publisherId of publishers) {
            this.subscribe(publisherId)
          }
        }
        break
      }

      case SFUMessageType.TRACKS: {
        const roomInfo = this.decodeString(data, pos)
        pos = roomInfo.newPos
        const publisherId = this.decodeString(data, pos)
        pos = publisherId.newPos
        const publisherUserId = this.decodeString(data, pos)
        pos = publisherUserId.newPos
        const publisherName = this.decodeString(data, pos)
        pos = publisherName.newPos
        const tracks: SFUTrack[] = []
        while (pos < data.length) {
          const trackId = this.decodeString(data, pos)
          pos = trackId.newPos
          const kind = this.decodeString(data, pos)
          pos = kind.newPos
          const label = this.decodeString(data, pos)
          pos = label.newPos
          tracks.push({ id: trackId.value, kind: kind.value as 'audio' | 'video', label: label.value })
        }
        this.cb.onTracks({
          roomId: roomInfo.value,
          publisherId: publisherId.value,
          publisherUserId: publisherUserId.value,
          publisherName: publisherName.value,
          tracks,
        })
        break
      }

      case SFUMessageType.UNPUBLISHED: {
        const publisherId = this.decodeString(data, pos)
        pos = publisherId.newPos
        const trackIdInfo = this.decodeString(data, pos)
        this.cb.onUnpublished(publisherId.value, trackIdInfo.value)
        break
      }

      case SFUMessageType.PUBLISHER_LEFT: {
        const publisherId = this.decodeString(data, pos)
        this.cb.onPublisherLeft(publisherId.value)
        break
      }

      case SFUMessageType.FRAME: {
        if (!this.cb.onEncodedFrame) break
        if (data.length < 24) break
        const view = new DataView(data.buffer)
        const magic = view.getUint32(0, false)
        if (magic !== 0x574C5643) break
        const frameType = view.getUint8(4) === 1 ? 'keyframe' : 'delta'
        const timestamp = view.getUint32(8, false)
        const dataLength = view.getUint32(12, false)
        if (data.length !== 24 + dataLength) break
        const trackIdBytes = data.slice(16, 24)
        const trackId = new TextDecoder().decode(trackIdBytes).replace(/\0+$/, '') || ''
        const frameData = data.slice(24)
        this.cb.onEncodedFrame({
          publisherId: '',
          trackId,
          timestamp,
          data: frameData.buffer,
          type: frameType,
        })
        break
      }

      case SFUMessageType.ERROR: {
        const errorInfo = this.decodeString(data, pos)
        console.error('SFU error:', errorInfo.value)
        break
      }
    }
  }
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

    ws.binaryType = 'arraybuffer'

    ws.onmessage = (ev) => {
      if (ev.data instanceof ArrayBuffer) {
        this.handleBinaryFrame(ev.data)
        return
      }
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
    const frameData = new Uint8Array(frame.data)
    const frameType = frame.type === 'keyframe' ? 1 : 0
    const magic = 0x574C5643
    const trackIdBytes = new TextEncoder().encode(frame.trackId.slice(0, 8).padEnd(8, '\0'))
    const payload = new ArrayBuffer(24 + frameData.length)
    const view = new DataView(payload)
    view.setUint32(0, magic, false)
    view.setUint8(4, frameType)
    view.setUint8(5, 0)
    view.setUint8(6, 0)
    view.setUint8(7, 0)
    view.setUint32(8, frame.timestamp, false)
    view.setUint32(12, frameData.length, false)
    new Uint8Array(payload, 16, 8).set(trackIdBytes)
    new Uint8Array(payload, 24).set(frameData)
    this.sendBinary(payload)
  }

  private sendBinary(data: ArrayBuffer): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(data)
    }
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

  private handleBinaryFrame(data: ArrayBuffer): void {
    if (!this.cb.onEncodedFrame) return
    if (data.byteLength < 24) return
    const view = new DataView(data)
    const magic = view.getUint32(0, false)
    if (magic !== 0x574C5643) return
    const frameType = view.getUint8(4) === 1 ? 'keyframe' : 'delta'
    const timestamp = view.getUint32(8, false)
    const dataLength = view.getUint32(12, false)
    if (data.byteLength !== 24 + dataLength) return
    const trackIdBytes = new Uint8Array(data, 16, 8)
    const trackId = new TextDecoder().decode(trackIdBytes).replace(/\0+$/, '') || ''
    const frameData = data.slice(24)
    this.cb.onEncodedFrame({
      publisherId: '',
      trackId,
      timestamp,
      data: frameData,
      type: frameType,
    })
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
          this.cb.onEncodedFrame({
            publisherId: stringField(msg.publisherId, msg.publisher_id),
            publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id),
            trackId: stringField(msg.trackId, msg.track_id),
            timestamp: msg.timestamp,
            data: new Uint8Array(msg.data).buffer,
            type: stringField(msg.frameType, msg.frame_type) === 'keyframe' ? 'keyframe' : 'delta',
          })
        }
        break
    }
  }
}
