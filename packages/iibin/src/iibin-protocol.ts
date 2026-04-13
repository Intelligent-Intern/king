/**
 * IIBIN Protocol - TypeScript Implementation
 * ==========================================
 * 
 * High-performance binary protocol for WebSocket communication
 * with efficient serialization and type safety.
 */

export enum IIBinMessageType {
  // System Messages
  PING = 0x01,
  PONG = 0x02,
  CONNECT = 0x03,
  DISCONNECT = 0x04,
  ERROR = 0x05,
  
  // Chat Messages
  TEXT_MESSAGE = 0x10,
  MEDIA_MESSAGE = 0x11,
  TYPING_INDICATOR = 0x12,
  READ_RECEIPT = 0x13,
  MESSAGE_STATUS = 0x14,
  
  // User Management
  USER_JOIN = 0x20,
  USER_LEAVE = 0x21,
  USER_LIST = 0x22,
  USER_STATUS = 0x23,
  
  // AI Bot Integration
  BOT_MESSAGE = 0x30,
  BOT_COMMAND = 0x31,
  BOT_STATUS = 0x32,
  MCP_REQUEST = 0x33,
  MCP_RESPONSE = 0x34,
  
  // Performance Testing
  STRESS_TEST_START = 0x40,
  STRESS_TEST_DATA = 0x41,
  STRESS_TEST_RESULT = 0x42,
  BENCHMARK_REQUEST = 0x43,
  BENCHMARK_RESPONSE = 0x44,
  
  // Interest Graph (Future)
  INTEREST_GRAPH_EXPORT = 0x50,
  INTEREST_MATCH_REQUEST = 0x51,
  INTEREST_MATCH_RESPONSE = 0x52,
  POLYTOPE_DATA = 0x53,
}

export interface IIBinHeader {
  version: number;        // Protocol version (1 byte)
  messageType: IIBinMessageType; // Message type (1 byte)
  flags: number;          // Flags (1 byte)
  reserved: number;       // Reserved (1 byte)
  payloadLength: number;  // Payload length (4 bytes)
  timestamp: bigint;      // Timestamp (8 bytes)
  messageId: bigint;      // Message ID (8 bytes)
}

export interface IIBinMessage {
  header: IIBinHeader;
  payload: Uint8Array;
}

export interface TextMessage {
  userId: string;
  roomId: string;
  content: string;
  timestamp: number;
  messageId: string;
}

export interface MediaMessage {
  userId: string;
  roomId: string;
  mediaType: 'image' | 'video' | 'audio' | 'file';
  mediaData: Uint8Array;
  fileName?: string;
  mimeType: string;
  timestamp: number;
  messageId: string;
}

export interface UserStatus {
  userId: string;
  status: 'online' | 'offline' | 'away' | 'busy';
  lastSeen: number;
  customStatus?: string;
}

export interface BotMessage {
  botId: string;
  botName: string;
  roomId: string;
  content: string;
  messageType: 'text' | 'command_response' | 'error';
  timestamp: number;
  messageId: string;
  metadata?: Record<string, any>;
}

export interface StressTestData {
  testId: string;
  sequenceNumber: number;
  payload: Uint8Array;
  timestamp: number;
  expectedResponseTime: number;
}

export interface BenchmarkResult {
  testId: string;
  totalMessages: number;
  totalBytes: number;
  duration: number;
  messagesPerSecond: number;
  bytesPerSecond: number;
  averageLatency: number;
  p95Latency: number;
  p99Latency: number;
  errorCount: number;
}

export class IIBinProtocol {
  private static readonly HEADER_SIZE = 24; // bytes
  private static readonly VERSION = 1;
  private messageIdCounter = 0n;

  /**
   * Serialize a message to binary format
   */
  serialize(messageType: IIBinMessageType, payload: any, flags: number = 0): Uint8Array {
    const payloadBytes = this.serializePayload(messageType, payload);
    const totalSize = IIBinProtocol.HEADER_SIZE + payloadBytes.length;
    
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    
    // Write header
    let offset = 0;
    view.setUint8(offset++, IIBinProtocol.VERSION);
    view.setUint8(offset++, messageType);
    view.setUint8(offset++, flags);
    view.setUint8(offset++, 0); // reserved
    view.setUint32(offset, payloadBytes.length, true); // little endian
    offset += 4;
    view.setBigUint64(offset, BigInt(Date.now() * 1000), true); // microseconds
    offset += 8;
    view.setBigUint64(offset, ++this.messageIdCounter, true);
    offset += 8;
    
    // Write payload
    uint8View.set(payloadBytes, offset);
    
    return uint8View;
  }

  /**
   * Deserialize binary data to message
   */
  deserialize(data: Uint8Array): IIBinMessage {
    if (data.length < IIBinProtocol.HEADER_SIZE) {
      throw new Error('Invalid message: too short');
    }
    
    const view = new DataView(data.buffer, data.byteOffset);
    
    // Parse header
    let offset = 0;
    const header: IIBinHeader = {
      version: view.getUint8(offset++),
      messageType: view.getUint8(offset++),
      flags: view.getUint8(offset++),
      reserved: view.getUint8(offset++),
      payloadLength: view.getUint32(offset, true),
      timestamp: view.getBigUint64(offset + 4, true),
      messageId: view.getBigUint64(offset + 12, true)
    };
    offset += 16;
    
    // Validate
    if (header.version !== IIBinProtocol.VERSION) {
      throw new Error(`Unsupported protocol version: ${header.version}`);
    }
    
    if (data.length !== IIBinProtocol.HEADER_SIZE + header.payloadLength) {
      throw new Error('Invalid message: length mismatch');
    }
    
    // Extract payload
    const payload = data.slice(offset, offset + header.payloadLength);
    
    return { header, payload };
  }

  /**
   * Serialize payload based on message type
   */
  private serializePayload(messageType: IIBinMessageType, payload: any): Uint8Array {
    switch (messageType) {
      case IIBinMessageType.PING:
      case IIBinMessageType.PONG:
        return new Uint8Array(0);
        
      case IIBinMessageType.TEXT_MESSAGE:
        return this.serializeTextMessage(payload as TextMessage);
        
      case IIBinMessageType.MEDIA_MESSAGE:
        return this.serializeMediaMessage(payload as MediaMessage);
        
      case IIBinMessageType.USER_STATUS:
        return this.serializeUserStatus(payload as UserStatus);
        
      case IIBinMessageType.BOT_MESSAGE:
        return this.serializeBotMessage(payload as BotMessage);
        
      case IIBinMessageType.STRESS_TEST_DATA:
        return this.serializeStressTestData(payload as StressTestData);
        
      case IIBinMessageType.BENCHMARK_RESPONSE:
        return this.serializeBenchmarkResult(payload as BenchmarkResult);
        
      default:
        // Generic JSON serialization for unknown types
        return new TextEncoder().encode(JSON.stringify(payload));
    }
  }

  /**
   * Deserialize payload based on message type
   */
  deserializePayload(messageType: IIBinMessageType, payload: Uint8Array): any {
    switch (messageType) {
      case IIBinMessageType.PING:
      case IIBinMessageType.PONG:
        return null;
        
      case IIBinMessageType.TEXT_MESSAGE:
        return this.deserializeTextMessage(payload);
        
      case IIBinMessageType.MEDIA_MESSAGE:
        return this.deserializeMediaMessage(payload);
        
      case IIBinMessageType.USER_STATUS:
        return this.deserializeUserStatus(payload);
        
      case IIBinMessageType.BOT_MESSAGE:
        return this.deserializeBotMessage(payload);
        
      case IIBinMessageType.STRESS_TEST_DATA:
        return this.deserializeStressTestData(payload);
        
      case IIBinMessageType.BENCHMARK_RESPONSE:
        return this.deserializeBenchmarkResult(payload);
        
      default:
        // Generic JSON deserialization
        return JSON.parse(new TextDecoder().decode(payload));
    }
  }

  // Specific serialization methods
  private serializeTextMessage(msg: TextMessage): Uint8Array {
    const encoder = new TextEncoder();
    const userIdBytes = encoder.encode(msg.userId);
    const roomIdBytes = encoder.encode(msg.roomId);
    const contentBytes = encoder.encode(msg.content);
    const messageIdBytes = encoder.encode(msg.messageId);
    
    const totalSize = 4 + userIdBytes.length + 4 + roomIdBytes.length + 
                     4 + contentBytes.length + 8 + 4 + messageIdBytes.length;
    
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    
    let offset = 0;
    
    // userId
    view.setUint32(offset, userIdBytes.length, true);
    offset += 4;
    uint8View.set(userIdBytes, offset);
    offset += userIdBytes.length;
    
    // roomId
    view.setUint32(offset, roomIdBytes.length, true);
    offset += 4;
    uint8View.set(roomIdBytes, offset);
    offset += roomIdBytes.length;
    
    // content
    view.setUint32(offset, contentBytes.length, true);
    offset += 4;
    uint8View.set(contentBytes, offset);
    offset += contentBytes.length;
    
    // timestamp
    view.setBigUint64(offset, BigInt(msg.timestamp), true);
    offset += 8;
    
    // messageId
    view.setUint32(offset, messageIdBytes.length, true);
    offset += 4;
    uint8View.set(messageIdBytes, offset);
    
    return uint8View;
  }

  private deserializeTextMessage(payload: Uint8Array): TextMessage {
    const view = new DataView(payload.buffer, payload.byteOffset);
    const decoder = new TextDecoder();
    let offset = 0;
    
    // userId
    const userIdLength = view.getUint32(offset, true);
    offset += 4;
    const userId = decoder.decode(payload.slice(offset, offset + userIdLength));
    offset += userIdLength;
    
    // roomId
    const roomIdLength = view.getUint32(offset, true);
    offset += 4;
    const roomId = decoder.decode(payload.slice(offset, offset + roomIdLength));
    offset += roomIdLength;
    
    // content
    const contentLength = view.getUint32(offset, true);
    offset += 4;
    const content = decoder.decode(payload.slice(offset, offset + contentLength));
    offset += contentLength;
    
    // timestamp
    const timestamp = Number(view.getBigUint64(offset, true));
    offset += 8;
    
    // messageId
    const messageIdLength = view.getUint32(offset, true);
    offset += 4;
    const messageId = decoder.decode(payload.slice(offset, offset + messageIdLength));
    
    return { userId, roomId, content, timestamp, messageId };
  }

  private serializeMediaMessage(msg: MediaMessage): Uint8Array {
    const encoder = new TextEncoder();
    const userIdBytes = encoder.encode(msg.userId);
    const roomIdBytes = encoder.encode(msg.roomId);
    const mediaTypeBytes = encoder.encode(msg.mediaType);
    const fileNameBytes = encoder.encode(msg.fileName || '');
    const mimeTypeBytes = encoder.encode(msg.mimeType);
    const messageIdBytes = encoder.encode(msg.messageId);
    
    const totalSize = 4 + userIdBytes.length + 4 + roomIdBytes.length + 
                     4 + mediaTypeBytes.length + 4 + msg.mediaData.length +
                     4 + fileNameBytes.length + 4 + mimeTypeBytes.length +
                     8 + 4 + messageIdBytes.length;
    
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    
    let offset = 0;
    
    // Serialize all fields similar to text message but with media data
    view.setUint32(offset, userIdBytes.length, true);
    offset += 4;
    uint8View.set(userIdBytes, offset);
    offset += userIdBytes.length;
    
    view.setUint32(offset, roomIdBytes.length, true);
    offset += 4;
    uint8View.set(roomIdBytes, offset);
    offset += roomIdBytes.length;
    
    view.setUint32(offset, mediaTypeBytes.length, true);
    offset += 4;
    uint8View.set(mediaTypeBytes, offset);
    offset += mediaTypeBytes.length;
    
    view.setUint32(offset, msg.mediaData.length, true);
    offset += 4;
    uint8View.set(msg.mediaData, offset);
    offset += msg.mediaData.length;
    
    view.setUint32(offset, fileNameBytes.length, true);
    offset += 4;
    uint8View.set(fileNameBytes, offset);
    offset += fileNameBytes.length;
    
    view.setUint32(offset, mimeTypeBytes.length, true);
    offset += 4;
    uint8View.set(mimeTypeBytes, offset);
    offset += mimeTypeBytes.length;
    
    view.setBigUint64(offset, BigInt(msg.timestamp), true);
    offset += 8;
    
    view.setUint32(offset, messageIdBytes.length, true);
    offset += 4;
    uint8View.set(messageIdBytes, offset);
    
    return uint8View;
  }

  private deserializeMediaMessage(payload: Uint8Array): MediaMessage {
    const view = new DataView(payload.buffer, payload.byteOffset);
    const decoder = new TextDecoder();
    let offset = 0;
    
    // Similar deserialization logic for media message
    const userIdLength = view.getUint32(offset, true);
    offset += 4;
    const userId = decoder.decode(payload.slice(offset, offset + userIdLength));
    offset += userIdLength;
    
    const roomIdLength = view.getUint32(offset, true);
    offset += 4;
    const roomId = decoder.decode(payload.slice(offset, offset + roomIdLength));
    offset += roomIdLength;
    
    const mediaTypeLength = view.getUint32(offset, true);
    offset += 4;
    const mediaType = decoder.decode(payload.slice(offset, offset + mediaTypeLength)) as 'image' | 'video' | 'audio' | 'file';
    offset += mediaTypeLength;
    
    const mediaDataLength = view.getUint32(offset, true);
    offset += 4;
    const mediaData = payload.slice(offset, offset + mediaDataLength);
    offset += mediaDataLength;
    
    const fileNameLength = view.getUint32(offset, true);
    offset += 4;
    const fileName = decoder.decode(payload.slice(offset, offset + fileNameLength)) || undefined;
    offset += fileNameLength;
    
    const mimeTypeLength = view.getUint32(offset, true);
    offset += 4;
    const mimeType = decoder.decode(payload.slice(offset, offset + mimeTypeLength));
    offset += mimeTypeLength;
    
    const timestamp = Number(view.getBigUint64(offset, true));
    offset += 8;
    
    const messageIdLength = view.getUint32(offset, true);
    offset += 4;
    const messageId = decoder.decode(payload.slice(offset, offset + messageIdLength));
    
    return { userId, roomId, mediaType, mediaData, fileName, mimeType, timestamp, messageId };
  }

  private serializeUserStatus(status: UserStatus): Uint8Array {
    const encoder = new TextEncoder();
    const userIdBytes = encoder.encode(status.userId);
    const statusBytes = encoder.encode(status.status);
    const customStatusBytes = encoder.encode(status.customStatus || '');
    
    const totalSize = 4 + userIdBytes.length + 4 + statusBytes.length + 
                     8 + 4 + customStatusBytes.length;
    
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    
    let offset = 0;
    
    view.setUint32(offset, userIdBytes.length, true);
    offset += 4;
    uint8View.set(userIdBytes, offset);
    offset += userIdBytes.length;
    
    view.setUint32(offset, statusBytes.length, true);
    offset += 4;
    uint8View.set(statusBytes, offset);
    offset += statusBytes.length;
    
    view.setBigUint64(offset, BigInt(status.lastSeen), true);
    offset += 8;
    
    view.setUint32(offset, customStatusBytes.length, true);
    offset += 4;
    uint8View.set(customStatusBytes, offset);
    
    return uint8View;
  }

  private deserializeUserStatus(payload: Uint8Array): UserStatus {
    const view = new DataView(payload.buffer, payload.byteOffset);
    const decoder = new TextDecoder();
    let offset = 0;
    
    const userIdLength = view.getUint32(offset, true);
    offset += 4;
    const userId = decoder.decode(payload.slice(offset, offset + userIdLength));
    offset += userIdLength;
    
    const statusLength = view.getUint32(offset, true);
    offset += 4;
    const status = decoder.decode(payload.slice(offset, offset + statusLength)) as 'online' | 'offline' | 'away' | 'busy';
    offset += statusLength;
    
    const lastSeen = Number(view.getBigUint64(offset, true));
    offset += 8;
    
    const customStatusLength = view.getUint32(offset, true);
    offset += 4;
    const customStatus = decoder.decode(payload.slice(offset, offset + customStatusLength)) || undefined;
    
    return { userId, status, lastSeen, customStatus };
  }

  private serializeBotMessage(msg: BotMessage): Uint8Array {
    // Similar to text message but with bot-specific fields
    return new TextEncoder().encode(JSON.stringify(msg));
  }

  private deserializeBotMessage(payload: Uint8Array): BotMessage {
    return JSON.parse(new TextDecoder().decode(payload));
  }

  private serializeStressTestData(data: StressTestData): Uint8Array {
    const encoder = new TextEncoder();
    const testIdBytes = encoder.encode(data.testId);
    
    const totalSize = 4 + testIdBytes.length + 8 + 4 + data.payload.length + 8 + 8;
    
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    
    let offset = 0;
    
    view.setUint32(offset, testIdBytes.length, true);
    offset += 4;
    uint8View.set(testIdBytes, offset);
    offset += testIdBytes.length;
    
    view.setBigUint64(offset, BigInt(data.sequenceNumber), true);
    offset += 8;
    
    view.setUint32(offset, data.payload.length, true);
    offset += 4;
    uint8View.set(data.payload, offset);
    offset += data.payload.length;
    
    view.setBigUint64(offset, BigInt(data.timestamp), true);
    offset += 8;
    
    view.setBigUint64(offset, BigInt(data.expectedResponseTime), true);
    
    return uint8View;
  }

  private deserializeStressTestData(payload: Uint8Array): StressTestData {
    const view = new DataView(payload.buffer, payload.byteOffset);
    const decoder = new TextDecoder();
    let offset = 0;
    
    const testIdLength = view.getUint32(offset, true);
    offset += 4;
    const testId = decoder.decode(payload.slice(offset, offset + testIdLength));
    offset += testIdLength;
    
    const sequenceNumber = Number(view.getBigUint64(offset, true));
    offset += 8;
    
    const payloadLength = view.getUint32(offset, true);
    offset += 4;
    const testPayload = payload.slice(offset, offset + payloadLength);
    offset += payloadLength;
    
    const timestamp = Number(view.getBigUint64(offset, true));
    offset += 8;
    
    const expectedResponseTime = Number(view.getBigUint64(offset, true));
    
    return { testId, sequenceNumber, payload: testPayload, timestamp, expectedResponseTime };
  }

  private serializeBenchmarkResult(result: BenchmarkResult): Uint8Array {
    return new TextEncoder().encode(JSON.stringify(result));
  }

  private deserializeBenchmarkResult(payload: Uint8Array): BenchmarkResult {
    return JSON.parse(new TextDecoder().decode(payload));
  }
}