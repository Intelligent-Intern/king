/**
 * IIBIN (Intelligent Indexed Binary) JavaScript Library
 * =====================================================
 * 
 * Ultra-efficient binary protocol for real-time communication
 * Optimized for WebSocket messaging and media streaming
 */

export interface IIBINMessage {
  type: MessageType
  id?: string
  timestamp?: number
  data?: any
  metadata?: Record<string, any>
}

export enum MessageType {
  // Chat Messages
  TEXT_MESSAGE = 0x01,
  VOICE_MESSAGE = 0x02,
  FILE_MESSAGE = 0x03,
  IMAGE_MESSAGE = 0x04,
  VIDEO_MESSAGE = 0x05,
  
  // System Messages
  USER_JOIN = 0x10,
  USER_LEAVE = 0x11,
  USER_TYPING = 0x12,
  USER_PRESENCE = 0x13,
  MESSAGE_READ = 0x14,
  MESSAGE_DELIVERED = 0x15,
  
  // Call Messages
  CALL_OFFER = 0x20,
  CALL_ANSWER = 0x21,
  CALL_ICE_CANDIDATE = 0x22,
  CALL_HANGUP = 0x23,
  CALL_MUTE = 0x24,
  CALL_UNMUTE = 0x25,
  
  // Group Messages
  GROUP_CREATE = 0x30,
  GROUP_JOIN = 0x31,
  GROUP_LEAVE = 0x32,
  GROUP_UPDATE = 0x33,
  
  // Performance & Monitoring
  PING = 0xF0,
  PONG = 0xF1,
  METRICS = 0xF2,
  ERROR = 0xFF
}

export enum DataType {
  NULL = 0x00,
  BOOLEAN = 0x01,
  INT8 = 0x02,
  INT16 = 0x03,
  INT32 = 0x04,
  INT64 = 0x05,
  FLOAT32 = 0x06,
  FLOAT64 = 0x07,
  STRING = 0x08,
  BINARY = 0x09,
  ARRAY = 0x0A,
  OBJECT = 0x0B,
  TIMESTAMP = 0x0C
}

export class IIBINEncoder {
  private buffer: ArrayBuffer
  private view: DataView
  private position: number
  private textEncoder: TextEncoder

  constructor(initialSize: number = 1024) {
    this.buffer = new ArrayBuffer(initialSize)
    this.view = new DataView(this.buffer)
    this.position = 0
    this.textEncoder = new TextEncoder()
  }

  private ensureCapacity(additionalBytes: number): void {
    if (this.position + additionalBytes > this.buffer.byteLength) {
      const newSize = Math.max(this.buffer.byteLength * 2, this.position + additionalBytes)
      const newBuffer = new ArrayBuffer(newSize)
      new Uint8Array(newBuffer).set(new Uint8Array(this.buffer))
      this.buffer = newBuffer
      this.view = new DataView(this.buffer)
    }
  }

  private writeUInt8(value: number): void {
    this.ensureCapacity(1)
    this.view.setUint8(this.position, value)
    this.position += 1
  }

  private writeUInt16(value: number): void {
    this.ensureCapacity(2)
    this.view.setUint16(this.position, value, true) // little-endian
    this.position += 2
  }

  private writeUInt32(value: number): void {
    this.ensureCapacity(4)
    this.view.setUint32(this.position, value, true) // little-endian
    this.position += 4
  }

  private writeFloat64(value: number): void {
    this.ensureCapacity(8)
    this.view.setFloat64(this.position, value, true) // little-endian
    this.position += 8
  }

  private writeString(value: string): void {
    const encoded = this.textEncoder.encode(value)
    this.writeUInt32(encoded.length)
    this.ensureCapacity(encoded.length)
    new Uint8Array(this.buffer, this.position).set(encoded)
    this.position += encoded.length
  }

  private writeBinary(value: Uint8Array): void {
    this.writeUInt32(value.length)
    this.ensureCapacity(value.length)
    new Uint8Array(this.buffer, this.position).set(value)
    this.position += value.length
  }

  private writeValue(value: any): void {
    if (value === null || value === undefined) {
      this.writeUInt8(DataType.NULL)
    } else if (typeof value === 'boolean') {
      this.writeUInt8(DataType.BOOLEAN)
      this.writeUInt8(value ? 1 : 0)
    } else if (typeof value === 'number') {
      if (Number.isInteger(value)) {
        if (value >= -128 && value <= 127) {
          this.writeUInt8(DataType.INT8)
          this.writeUInt8(value & 0xFF)
        } else if (value >= -32768 && value <= 32767) {
          this.writeUInt8(DataType.INT16)
          this.writeUInt16(value & 0xFFFF)
        } else if (value >= -2147483648 && value <= 2147483647) {
          this.writeUInt8(DataType.INT32)
          this.writeUInt32(value >>> 0)
        } else {
          this.writeUInt8(DataType.INT64)
          this.writeFloat64(value) // Use float64 for large integers
        }
      } else {
        this.writeUInt8(DataType.FLOAT64)
        this.writeFloat64(value)
      }
    } else if (typeof value === 'string') {
      this.writeUInt8(DataType.STRING)
      this.writeString(value)
    } else if (value instanceof Uint8Array) {
      this.writeUInt8(DataType.BINARY)
      this.writeBinary(value)
    } else if (value instanceof Date) {
      this.writeUInt8(DataType.TIMESTAMP)
      this.writeFloat64(value.getTime())
    } else if (Array.isArray(value)) {
      this.writeUInt8(DataType.ARRAY)
      this.writeUInt32(value.length)
      for (const item of value) {
        this.writeValue(item)
      }
    } else if (typeof value === 'object') {
      this.writeUInt8(DataType.OBJECT)
      const keys = Object.keys(value)
      this.writeUInt32(keys.length)
      for (const key of keys) {
        this.writeString(key)
        this.writeValue(value[key])
      }
    } else {
      throw new Error(`Unsupported value type: ${typeof value}`)
    }
  }

  encode(message: IIBINMessage): ArrayBuffer {
    this.position = 0

    // Write header
    this.writeUInt8(0x49) // 'I'
    this.writeUInt8(0x49) // 'I'
    this.writeUInt8(0x42) // 'B'
    this.writeUInt8(0x01) // Version

    // Write message type
    this.writeUInt8(message.type)

    // Write message ID (optional)
    if (message.id) {
      this.writeUInt8(1) // Has ID
      this.writeString(message.id)
    } else {
      this.writeUInt8(0) // No ID
    }

    // Write timestamp
    this.writeFloat64(message.timestamp || Date.now())

    // Write data
    this.writeValue(message.data)

    // Write metadata (optional)
    if (message.metadata) {
      this.writeUInt8(1) // Has metadata
      this.writeValue(message.metadata)
    } else {
      this.writeUInt8(0) // No metadata
    }

    // Return trimmed buffer
    return this.buffer.slice(0, this.position)
  }
}

export class IIBINDecoder {
  private view: DataView
  private position: number
  private textDecoder: TextDecoder

  constructor(buffer: ArrayBuffer) {
    this.view = new DataView(buffer)
    this.position = 0
    this.textDecoder = new TextDecoder()
  }

  private readUInt8(): number {
    const value = this.view.getUint8(this.position)
    this.position += 1
    return value
  }

  private readUInt16(): number {
    const value = this.view.getUint16(this.position, true) // little-endian
    this.position += 2
    return value
  }

  private readUInt32(): number {
    const value = this.view.getUint32(this.position, true) // little-endian
    this.position += 4
    return value
  }

  private readFloat64(): number {
    const value = this.view.getFloat64(this.position, true) // little-endian
    this.position += 8
    return value
  }

  private readString(): string {
    const length = this.readUInt32()
    const bytes = new Uint8Array(this.view.buffer, this.position, length)
    this.position += length
    return this.textDecoder.decode(bytes)
  }

  private readBinary(): Uint8Array {
    const length = this.readUInt32()
    const bytes = new Uint8Array(this.view.buffer, this.position, length)
    this.position += length
    return bytes
  }

  private readValue(): any {
    const type = this.readUInt8()

    switch (type) {
      case DataType.NULL:
        return null

      case DataType.BOOLEAN:
        return this.readUInt8() === 1

      case DataType.INT8:
        const int8 = this.readUInt8()
        return int8 > 127 ? int8 - 256 : int8

      case DataType.INT16:
        const int16 = this.readUInt16()
        return int16 > 32767 ? int16 - 65536 : int16

      case DataType.INT32:
        const int32 = this.readUInt32()
        return int32 > 2147483647 ? int32 - 4294967296 : int32

      case DataType.INT64:
      case DataType.FLOAT64:
        return this.readFloat64()

      case DataType.STRING:
        return this.readString()

      case DataType.BINARY:
        return this.readBinary()

      case DataType.TIMESTAMP:
        return new Date(this.readFloat64())

      case DataType.ARRAY:
        const arrayLength = this.readUInt32()
        const array = []
        for (let i = 0; i < arrayLength; i++) {
          array.push(this.readValue())
        }
        return array

      case DataType.OBJECT:
        const objectLength = this.readUInt32()
        const object: Record<string, any> = {}
        for (let i = 0; i < objectLength; i++) {
          const key = this.readString()
          const value = this.readValue()
          object[key] = value
        }
        return object

      default:
        throw new Error(`Unknown data type: ${type}`)
    }
  }

  decode(): IIBINMessage {
    // Verify header
    const i1 = this.readUInt8()
    const i2 = this.readUInt8()
    const b = this.readUInt8()
    const version = this.readUInt8()

    if (i1 !== 0x49 || i2 !== 0x49 || b !== 0x42) {
      throw new Error('Invalid IIBIN header')
    }

    if (version !== 0x01) {
      throw new Error(`Unsupported IIBIN version: ${version}`)
    }

    // Read message type
    const type = this.readUInt8() as MessageType

    // Read message ID
    const hasId = this.readUInt8() === 1
    const id = hasId ? this.readString() : undefined

    // Read timestamp
    const timestamp = this.readFloat64()

    // Read data
    const data = this.readValue()

    // Read metadata
    const hasMetadata = this.readUInt8() === 1
    const metadata = hasMetadata ? this.readValue() : undefined

    return {
      type,
      id,
      timestamp,
      data,
      metadata
    }
  }
}

export class IIBINClient {
  private ws: WebSocket | null = null
  private encoder: IIBINEncoder
  private reconnectAttempts: number = 0
  private maxReconnectAttempts: number = 5
  private reconnectDelay: number = 1000
  private pingInterval: ReturnType<typeof setInterval> | null = null
  private messageHandlers: Map<MessageType, ((message: IIBINMessage) => void)[]> = new Map()
  private metrics = {
    messagesSent: 0,
    messagesReceived: 0,
    bytesTransferred: 0,
    averageLatency: 0,
    connectionTime: 0
  }

  constructor() {
    this.encoder = new IIBINEncoder()
  }

  connect(url: string): Promise<void> {
    return new Promise((resolve, reject) => {
      try {
        this.ws = new WebSocket(url)
        this.ws.binaryType = 'arraybuffer'

        this.ws.onopen = () => {
          console.log('🔗 IIBIN WebSocket connected')
          this.reconnectAttempts = 0
          this.metrics.connectionTime = Date.now()
          this.startPingInterval()
          resolve()
        }

        this.ws.onmessage = (event) => {
          this.handleMessage(event.data)
        }

        this.ws.onclose = (event) => {
          console.log('🔌 IIBIN WebSocket disconnected:', event.code, event.reason)
          this.stopPingInterval()
          
          if (!event.wasClean && this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnect(url)
          }
        }

        this.ws.onerror = (error) => {
          console.error('❌ IIBIN WebSocket error:', error)
          reject(error)
        }

      } catch (error) {
        reject(error)
      }
    })
  }

  private async reconnect(url: string): Promise<void> {
    this.reconnectAttempts++
    const delay = this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1)
    
    console.log(`🔄 Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})`)
    
    await new Promise(resolve => setTimeout(resolve, delay))
    
    try {
      await this.connect(url)
    } catch (error) {
      console.error('Reconnection failed:', error)
    }
  }

  private startPingInterval(): void {
    this.pingInterval = setInterval(() => {
      this.send({
        type: MessageType.PING,
        timestamp: Date.now()
      })
    }, 30000) // Ping every 30 seconds
  }

  private stopPingInterval(): void {
    if (this.pingInterval) {
      clearInterval(this.pingInterval)
      this.pingInterval = null
    }
  }

  private handleMessage(data: ArrayBuffer): void {
    try {
      const decoder = new IIBINDecoder(data)
      const message = decoder.decode()
      
      this.metrics.messagesReceived++
      this.metrics.bytesTransferred += data.byteLength

      // Handle ping/pong
      if (message.type === MessageType.PING) {
        this.send({
          type: MessageType.PONG,
          id: message.id,
          timestamp: Date.now()
        })
        return
      }

      if (message.type === MessageType.PONG && message.id) {
        const latency = Date.now() - (message.timestamp || 0)
        this.metrics.averageLatency = (this.metrics.averageLatency + latency) / 2
        return
      }

      // Dispatch to handlers
      const handlers = this.messageHandlers.get(message.type)
      if (handlers) {
        handlers.forEach(handler => {
          try {
            handler(message)
          } catch (error) {
            console.error('Message handler error:', error)
          }
        })
      }

    } catch (error) {
      console.error('Failed to decode IIBIN message:', error)
    }
  }

  send(message: IIBINMessage): void {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      throw new Error('WebSocket is not connected')
    }

    try {
      const buffer = this.encoder.encode(message)
      this.ws.send(buffer)
      
      this.metrics.messagesSent++
      this.metrics.bytesTransferred += buffer.byteLength
      
    } catch (error) {
      console.error('Failed to send IIBIN message:', error)
      throw error
    }
  }

  on(messageType: MessageType, handler: (message: IIBINMessage) => void): void {
    if (!this.messageHandlers.has(messageType)) {
      this.messageHandlers.set(messageType, [])
    }
    this.messageHandlers.get(messageType)!.push(handler)
  }

  off(messageType: MessageType, handler: (message: IIBINMessage) => void): void {
    const handlers = this.messageHandlers.get(messageType)
    if (handlers) {
      const index = handlers.indexOf(handler)
      if (index !== -1) {
        handlers.splice(index, 1)
      }
    }
  }

  disconnect(): void {
    this.stopPingInterval()
    if (this.ws) {
      this.ws.close(1000, 'Client disconnect')
      this.ws = null
    }
  }

  getMetrics() {
    return {
      ...this.metrics,
      uptime: this.metrics.connectionTime ? Date.now() - this.metrics.connectionTime : 0,
      isConnected: this.ws?.readyState === WebSocket.OPEN
    }
  }

  isConnected(): boolean {
    return this.ws?.readyState === WebSocket.OPEN || false
  }
}

// Utility functions
export function createTextMessage(text: string, chatId?: string): IIBINMessage {
  return {
    type: MessageType.TEXT_MESSAGE,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      text,
      chatId
    }
  }
}

export function createVoiceMessage(audioData: Uint8Array, duration: number, chatId?: string): IIBINMessage {
  return {
    type: MessageType.VOICE_MESSAGE,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      audio: audioData,
      duration,
      chatId
    }
  }
}

export function createFileMessage(file: File, chatId?: string): IIBINMessage {
  return {
    type: MessageType.FILE_MESSAGE,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      fileName: file.name,
      fileSize: file.size,
      fileType: file.type,
      chatId
    }
  }
}

export function createCallOffer(offer: RTCSessionDescriptionInit, callId: string): IIBINMessage {
  return {
    type: MessageType.CALL_OFFER,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      offer,
      callId
    }
  }
}

export function createCallAnswer(answer: RTCSessionDescriptionInit, callId: string): IIBINMessage {
  return {
    type: MessageType.CALL_ANSWER,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      answer,
      callId
    }
  }
}

export function createIceCandidate(candidate: RTCIceCandidateInit, callId: string): IIBINMessage {
  return {
    type: MessageType.CALL_ICE_CANDIDATE,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      candidate,
      callId
    }
  }
}

// Performance comparison utility
export function compareWithJSON(data: any): { iibin: number, json: number, savings: number } {
  // IIBIN encoding
  const encoder = new IIBINEncoder()
  const iibinMessage: IIBINMessage = {
    type: MessageType.TEXT_MESSAGE,
    data
  }
  const iibinBuffer = encoder.encode(iibinMessage)
  const iibinSize = iibinBuffer.byteLength

  // JSON encoding
  const jsonString = JSON.stringify(data)
  const jsonSize = new TextEncoder().encode(jsonString).length

  const savings = ((jsonSize - iibinSize) / jsonSize) * 100

  return {
    iibin: iibinSize,
    json: jsonSize,
    savings: Math.round(savings * 100) / 100
  }
}
