export const SFU_CONTROL_TRANSPORT_WEBSOCKET = 'websocket_sfu_control'
export const SFU_MEDIA_TRANSPORT_WEBSOCKET_FALLBACK = 'websocket_binary_media_fallback'

export interface SfuMediaTransportSendResult {
  ok: boolean
  transportPath: string
  bytes: number
  sendMs: number
  bufferedAmount: number
  errorCode: string
}

interface SfuWebSocketFallbackMediaTransportOptions {
  getSocket: () => WebSocket | null
  getBufferedAmount: () => number
}

function nowMs(): number {
  if (typeof performance !== 'undefined' && typeof performance.now === 'function') {
    return performance.now()
  }
  return Date.now()
}

export class SfuWebSocketFallbackMediaTransport {
  private getSocket: () => WebSocket | null
  private getBufferedAmount: () => number

  constructor(options: SfuWebSocketFallbackMediaTransportOptions) {
    this.getSocket = options.getSocket
    this.getBufferedAmount = options.getBufferedAmount
  }

  isOpen(): boolean {
    return this.getSocket()?.readyState === WebSocket.OPEN
  }

  sendBinaryFrame(payload: ArrayBuffer): SfuMediaTransportSendResult {
    const bytes = Math.max(0, Number(payload.byteLength || 0))
    const socket = this.getSocket()
    if (!socket || socket.readyState !== WebSocket.OPEN) {
      return this.result(false, bytes, 0, 'media_transport_not_open')
    }

    const startedAtMs = nowMs()
    try {
      socket.send(payload)
      return this.result(true, bytes, nowMs() - startedAtMs, '')
    } catch {
      return this.result(false, bytes, nowMs() - startedAtMs, 'media_transport_send_throw')
    }
  }

  private result(ok: boolean, bytes: number, sendMs: number, errorCode: string): SfuMediaTransportSendResult {
    return {
      ok,
      transportPath: SFU_MEDIA_TRANSPORT_WEBSOCKET_FALLBACK,
      bytes,
      sendMs: Math.max(0, Number(sendMs || 0)),
      bufferedAmount: this.getBufferedAmount(),
      errorCode,
    }
  }
}
