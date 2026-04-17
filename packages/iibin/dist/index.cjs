"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// src/index.ts
var index_exports = {};
__export(index_exports, {
  DataType: () => DataType,
  IIBINClient: () => IIBINClient,
  IIBINDecoder: () => IIBINDecoder,
  IIBINEncoder: () => IIBINEncoder,
  IIBinMessageType: () => IIBinMessageType,
  IIBinProtocol: () => IIBinProtocol,
  MessageType: () => MessageType,
  compareWithJSON: () => compareWithJSON,
  createCallAnswer: () => createCallAnswer,
  createCallOffer: () => createCallOffer,
  createFileMessage: () => createFileMessage,
  createIceCandidate: () => createIceCandidate,
  createTextMessage: () => createTextMessage,
  createVoiceMessage: () => createVoiceMessage
});
module.exports = __toCommonJS(index_exports);

// src/iibin.ts
var MessageType = /* @__PURE__ */ ((MessageType2) => {
  MessageType2[MessageType2["TEXT_MESSAGE"] = 1] = "TEXT_MESSAGE";
  MessageType2[MessageType2["VOICE_MESSAGE"] = 2] = "VOICE_MESSAGE";
  MessageType2[MessageType2["FILE_MESSAGE"] = 3] = "FILE_MESSAGE";
  MessageType2[MessageType2["IMAGE_MESSAGE"] = 4] = "IMAGE_MESSAGE";
  MessageType2[MessageType2["VIDEO_MESSAGE"] = 5] = "VIDEO_MESSAGE";
  MessageType2[MessageType2["USER_JOIN"] = 16] = "USER_JOIN";
  MessageType2[MessageType2["USER_LEAVE"] = 17] = "USER_LEAVE";
  MessageType2[MessageType2["USER_TYPING"] = 18] = "USER_TYPING";
  MessageType2[MessageType2["USER_PRESENCE"] = 19] = "USER_PRESENCE";
  MessageType2[MessageType2["MESSAGE_READ"] = 20] = "MESSAGE_READ";
  MessageType2[MessageType2["MESSAGE_DELIVERED"] = 21] = "MESSAGE_DELIVERED";
  MessageType2[MessageType2["CALL_OFFER"] = 32] = "CALL_OFFER";
  MessageType2[MessageType2["CALL_ANSWER"] = 33] = "CALL_ANSWER";
  MessageType2[MessageType2["CALL_ICE_CANDIDATE"] = 34] = "CALL_ICE_CANDIDATE";
  MessageType2[MessageType2["CALL_HANGUP"] = 35] = "CALL_HANGUP";
  MessageType2[MessageType2["CALL_MUTE"] = 36] = "CALL_MUTE";
  MessageType2[MessageType2["CALL_UNMUTE"] = 37] = "CALL_UNMUTE";
  MessageType2[MessageType2["GROUP_CREATE"] = 48] = "GROUP_CREATE";
  MessageType2[MessageType2["GROUP_JOIN"] = 49] = "GROUP_JOIN";
  MessageType2[MessageType2["GROUP_LEAVE"] = 50] = "GROUP_LEAVE";
  MessageType2[MessageType2["GROUP_UPDATE"] = 51] = "GROUP_UPDATE";
  MessageType2[MessageType2["PING"] = 240] = "PING";
  MessageType2[MessageType2["PONG"] = 241] = "PONG";
  MessageType2[MessageType2["METRICS"] = 242] = "METRICS";
  MessageType2[MessageType2["ERROR"] = 255] = "ERROR";
  return MessageType2;
})(MessageType || {});
var DataType = /* @__PURE__ */ ((DataType2) => {
  DataType2[DataType2["NULL"] = 0] = "NULL";
  DataType2[DataType2["BOOLEAN"] = 1] = "BOOLEAN";
  DataType2[DataType2["INT8"] = 2] = "INT8";
  DataType2[DataType2["INT16"] = 3] = "INT16";
  DataType2[DataType2["INT32"] = 4] = "INT32";
  DataType2[DataType2["INT64"] = 5] = "INT64";
  DataType2[DataType2["FLOAT32"] = 6] = "FLOAT32";
  DataType2[DataType2["FLOAT64"] = 7] = "FLOAT64";
  DataType2[DataType2["STRING"] = 8] = "STRING";
  DataType2[DataType2["BINARY"] = 9] = "BINARY";
  DataType2[DataType2["ARRAY"] = 10] = "ARRAY";
  DataType2[DataType2["OBJECT"] = 11] = "OBJECT";
  DataType2[DataType2["TIMESTAMP"] = 12] = "TIMESTAMP";
  return DataType2;
})({});
var IIBINEncoder = class {
  constructor(initialSize = 1024) {
    this.buffer = new ArrayBuffer(initialSize);
    this.view = new DataView(this.buffer);
    this.position = 0;
    this.textEncoder = new TextEncoder();
  }
  ensureCapacity(additionalBytes) {
    if (this.position + additionalBytes > this.buffer.byteLength) {
      const newSize = Math.max(this.buffer.byteLength * 2, this.position + additionalBytes);
      const newBuffer = new ArrayBuffer(newSize);
      new Uint8Array(newBuffer).set(new Uint8Array(this.buffer));
      this.buffer = newBuffer;
      this.view = new DataView(this.buffer);
    }
  }
  writeUInt8(value) {
    this.ensureCapacity(1);
    this.view.setUint8(this.position, value);
    this.position += 1;
  }
  writeUInt16(value) {
    this.ensureCapacity(2);
    this.view.setUint16(this.position, value, true);
    this.position += 2;
  }
  writeUInt32(value) {
    this.ensureCapacity(4);
    this.view.setUint32(this.position, value, true);
    this.position += 4;
  }
  writeFloat64(value) {
    this.ensureCapacity(8);
    this.view.setFloat64(this.position, value, true);
    this.position += 8;
  }
  writeString(value) {
    const encoded = this.textEncoder.encode(value);
    this.writeUInt32(encoded.length);
    this.ensureCapacity(encoded.length);
    new Uint8Array(this.buffer, this.position).set(encoded);
    this.position += encoded.length;
  }
  writeBinary(value) {
    this.writeUInt32(value.length);
    this.ensureCapacity(value.length);
    new Uint8Array(this.buffer, this.position).set(value);
    this.position += value.length;
  }
  writeValue(value) {
    if (value === null || value === void 0) {
      this.writeUInt8(0 /* NULL */);
    } else if (typeof value === "boolean") {
      this.writeUInt8(1 /* BOOLEAN */);
      this.writeUInt8(value ? 1 : 0);
    } else if (typeof value === "number") {
      if (Number.isInteger(value)) {
        if (value >= -128 && value <= 127) {
          this.writeUInt8(2 /* INT8 */);
          this.writeUInt8(value & 255);
        } else if (value >= -32768 && value <= 32767) {
          this.writeUInt8(3 /* INT16 */);
          this.writeUInt16(value & 65535);
        } else if (value >= -2147483648 && value <= 2147483647) {
          this.writeUInt8(4 /* INT32 */);
          this.writeUInt32(value >>> 0);
        } else {
          this.writeUInt8(5 /* INT64 */);
          this.writeFloat64(value);
        }
      } else {
        this.writeUInt8(7 /* FLOAT64 */);
        this.writeFloat64(value);
      }
    } else if (typeof value === "string") {
      this.writeUInt8(8 /* STRING */);
      this.writeString(value);
    } else if (value instanceof Uint8Array) {
      this.writeUInt8(9 /* BINARY */);
      this.writeBinary(value);
    } else if (value instanceof Date) {
      this.writeUInt8(12 /* TIMESTAMP */);
      this.writeFloat64(value.getTime());
    } else if (Array.isArray(value)) {
      this.writeUInt8(10 /* ARRAY */);
      this.writeUInt32(value.length);
      for (const item of value) {
        this.writeValue(item);
      }
    } else if (typeof value === "object") {
      this.writeUInt8(11 /* OBJECT */);
      const keys = Object.keys(value);
      this.writeUInt32(keys.length);
      for (const key of keys) {
        this.writeString(key);
        this.writeValue(value[key]);
      }
    } else {
      throw new Error(`Unsupported value type: ${typeof value}`);
    }
  }
  encode(message) {
    this.position = 0;
    this.writeUInt8(73);
    this.writeUInt8(73);
    this.writeUInt8(66);
    this.writeUInt8(1);
    this.writeUInt8(message.type);
    if (message.id) {
      this.writeUInt8(1);
      this.writeString(message.id);
    } else {
      this.writeUInt8(0);
    }
    this.writeFloat64(message.timestamp || Date.now());
    this.writeValue(message.data);
    if (message.metadata) {
      this.writeUInt8(1);
      this.writeValue(message.metadata);
    } else {
      this.writeUInt8(0);
    }
    return this.buffer.slice(0, this.position);
  }
};
var IIBINDecoder = class {
  constructor(buffer) {
    this.view = new DataView(buffer);
    this.position = 0;
    this.textDecoder = new TextDecoder();
  }
  readUInt8() {
    const value = this.view.getUint8(this.position);
    this.position += 1;
    return value;
  }
  readUInt16() {
    const value = this.view.getUint16(this.position, true);
    this.position += 2;
    return value;
  }
  readUInt32() {
    const value = this.view.getUint32(this.position, true);
    this.position += 4;
    return value;
  }
  readFloat64() {
    const value = this.view.getFloat64(this.position, true);
    this.position += 8;
    return value;
  }
  readString() {
    const length = this.readUInt32();
    const bytes = new Uint8Array(this.view.buffer, this.position, length);
    this.position += length;
    return this.textDecoder.decode(bytes);
  }
  readBinary() {
    const length = this.readUInt32();
    const bytes = new Uint8Array(this.view.buffer, this.position, length);
    this.position += length;
    return bytes;
  }
  readValue() {
    const type = this.readUInt8();
    switch (type) {
      case 0 /* NULL */:
        return null;
      case 1 /* BOOLEAN */:
        return this.readUInt8() === 1;
      case 2 /* INT8 */:
        const int8 = this.readUInt8();
        return int8 > 127 ? int8 - 256 : int8;
      case 3 /* INT16 */:
        const int16 = this.readUInt16();
        return int16 > 32767 ? int16 - 65536 : int16;
      case 4 /* INT32 */:
        const int32 = this.readUInt32();
        return int32 > 2147483647 ? int32 - 4294967296 : int32;
      case 5 /* INT64 */:
      case 7 /* FLOAT64 */:
        return this.readFloat64();
      case 8 /* STRING */:
        return this.readString();
      case 9 /* BINARY */:
        return this.readBinary();
      case 12 /* TIMESTAMP */:
        return new Date(this.readFloat64());
      case 10 /* ARRAY */:
        const arrayLength = this.readUInt32();
        const array = [];
        for (let i = 0; i < arrayLength; i++) {
          array.push(this.readValue());
        }
        return array;
      case 11 /* OBJECT */:
        const objectLength = this.readUInt32();
        const object = {};
        for (let i = 0; i < objectLength; i++) {
          const key = this.readString();
          const value = this.readValue();
          object[key] = value;
        }
        return object;
      default:
        throw new Error(`Unknown data type: ${type}`);
    }
  }
  decode() {
    const i1 = this.readUInt8();
    const i2 = this.readUInt8();
    const b = this.readUInt8();
    const version = this.readUInt8();
    if (i1 !== 73 || i2 !== 73 || b !== 66) {
      throw new Error("Invalid IIBIN header");
    }
    if (version !== 1) {
      throw new Error(`Unsupported IIBIN version: ${version}`);
    }
    const type = this.readUInt8();
    const hasId = this.readUInt8() === 1;
    const id = hasId ? this.readString() : void 0;
    const timestamp = this.readFloat64();
    const data = this.readValue();
    const hasMetadata = this.readUInt8() === 1;
    const metadata = hasMetadata ? this.readValue() : void 0;
    return {
      type,
      id,
      timestamp,
      data,
      metadata
    };
  }
};
var IIBINClient = class {
  constructor() {
    this.ws = null;
    this.reconnectAttempts = 0;
    this.maxReconnectAttempts = 5;
    this.reconnectDelay = 1e3;
    this.pingInterval = null;
    this.messageHandlers = /* @__PURE__ */ new Map();
    this.metrics = {
      messagesSent: 0,
      messagesReceived: 0,
      bytesTransferred: 0,
      averageLatency: 0,
      connectionTime: 0
    };
    this.encoder = new IIBINEncoder();
  }
  connect(url) {
    return new Promise((resolve, reject) => {
      try {
        this.ws = new WebSocket(url);
        this.ws.binaryType = "arraybuffer";
        this.ws.onopen = () => {
          console.log("\u{1F517} IIBIN WebSocket connected");
          this.reconnectAttempts = 0;
          this.metrics.connectionTime = Date.now();
          this.startPingInterval();
          resolve();
        };
        this.ws.onmessage = (event) => {
          this.handleMessage(event.data);
        };
        this.ws.onclose = (event) => {
          console.log("\u{1F50C} IIBIN WebSocket disconnected:", event.code, event.reason);
          this.stopPingInterval();
          if (!event.wasClean && this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnect(url);
          }
        };
        this.ws.onerror = (error) => {
          console.error("\u274C IIBIN WebSocket error:", error);
          reject(error);
        };
      } catch (error) {
        reject(error);
      }
    });
  }
  async reconnect(url) {
    this.reconnectAttempts++;
    const delay = this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1);
    console.log(`\u{1F504} Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
    await new Promise((resolve) => setTimeout(resolve, delay));
    try {
      await this.connect(url);
    } catch (error) {
      console.error("Reconnection failed:", error);
    }
  }
  startPingInterval() {
    this.pingInterval = setInterval(() => {
      this.send({
        type: 240 /* PING */,
        timestamp: Date.now()
      });
    }, 3e4);
  }
  stopPingInterval() {
    if (this.pingInterval) {
      clearInterval(this.pingInterval);
      this.pingInterval = null;
    }
  }
  handleMessage(data) {
    try {
      const decoder = new IIBINDecoder(data);
      const message = decoder.decode();
      this.metrics.messagesReceived++;
      this.metrics.bytesTransferred += data.byteLength;
      if (message.type === 240 /* PING */) {
        this.send({
          type: 241 /* PONG */,
          id: message.id,
          timestamp: Date.now()
        });
        return;
      }
      if (message.type === 241 /* PONG */ && message.id) {
        const latency = Date.now() - (message.timestamp || 0);
        this.metrics.averageLatency = (this.metrics.averageLatency + latency) / 2;
        return;
      }
      const handlers = this.messageHandlers.get(message.type);
      if (handlers) {
        handlers.forEach((handler) => {
          try {
            handler(message);
          } catch (error) {
            console.error("Message handler error:", error);
          }
        });
      }
    } catch (error) {
      console.error("Failed to decode IIBIN message:", error);
    }
  }
  send(message) {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      throw new Error("WebSocket is not connected");
    }
    try {
      const buffer = this.encoder.encode(message);
      this.ws.send(buffer);
      this.metrics.messagesSent++;
      this.metrics.bytesTransferred += buffer.byteLength;
    } catch (error) {
      console.error("Failed to send IIBIN message:", error);
      throw error;
    }
  }
  on(messageType, handler) {
    if (!this.messageHandlers.has(messageType)) {
      this.messageHandlers.set(messageType, []);
    }
    this.messageHandlers.get(messageType).push(handler);
  }
  off(messageType, handler) {
    const handlers = this.messageHandlers.get(messageType);
    if (handlers) {
      const index = handlers.indexOf(handler);
      if (index !== -1) {
        handlers.splice(index, 1);
      }
    }
  }
  disconnect() {
    this.stopPingInterval();
    if (this.ws) {
      this.ws.close(1e3, "Client disconnect");
      this.ws = null;
    }
  }
  getMetrics() {
    return {
      ...this.metrics,
      uptime: this.metrics.connectionTime ? Date.now() - this.metrics.connectionTime : 0,
      isConnected: this.ws?.readyState === WebSocket.OPEN
    };
  }
  isConnected() {
    return this.ws?.readyState === WebSocket.OPEN || false;
  }
};
function createTextMessage(text, chatId) {
  return {
    type: 1 /* TEXT_MESSAGE */,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      text,
      chatId
    }
  };
}
function createVoiceMessage(audioData, duration, chatId) {
  return {
    type: 2 /* VOICE_MESSAGE */,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      audio: audioData,
      duration,
      chatId
    }
  };
}
function createFileMessage(file, chatId) {
  return {
    type: 3 /* FILE_MESSAGE */,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      fileName: file.name,
      fileSize: file.size,
      fileType: file.type,
      chatId
    }
  };
}
function createCallOffer(offer, callId) {
  return {
    type: 32 /* CALL_OFFER */,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      offer,
      callId
    }
  };
}
function createCallAnswer(answer, callId) {
  return {
    type: 33 /* CALL_ANSWER */,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      answer,
      callId
    }
  };
}
function createIceCandidate(candidate, callId) {
  return {
    type: 34 /* CALL_ICE_CANDIDATE */,
    id: crypto.randomUUID(),
    timestamp: Date.now(),
    data: {
      candidate,
      callId
    }
  };
}
function compareWithJSON(data) {
  const encoder = new IIBINEncoder();
  const iibinMessage = {
    type: 1 /* TEXT_MESSAGE */,
    data
  };
  const iibinBuffer = encoder.encode(iibinMessage);
  const iibinSize = iibinBuffer.byteLength;
  const jsonString = JSON.stringify(data);
  const jsonSize = new TextEncoder().encode(jsonString).length;
  const savings = (jsonSize - iibinSize) / jsonSize * 100;
  return {
    iibin: iibinSize,
    json: jsonSize,
    savings: Math.round(savings * 100) / 100
  };
}

// src/iibin-protocol.ts
var IIBinMessageType;
var IIBinMessageType = /* @__PURE__ */ ((IIBinMessageType2) => {
  IIBinMessageType2[IIBinMessageType2["PING"] = 1] = "PING";
  IIBinMessageType2[IIBinMessageType2["PONG"] = 2] = "PONG";
  IIBinMessageType2[IIBinMessageType2["CONNECT"] = 3] = "CONNECT";
  IIBinMessageType2[IIBinMessageType2["DISCONNECT"] = 4] = "DISCONNECT";
  IIBinMessageType2[IIBinMessageType2["ERROR"] = 5] = "ERROR";
  IIBinMessageType2[IIBinMessageType2["TEXT_MESSAGE"] = 16] = "TEXT_MESSAGE";
  IIBinMessageType2[IIBinMessageType2["MEDIA_MESSAGE"] = 17] = "MEDIA_MESSAGE";
  IIBinMessageType2[IIBinMessageType2["TYPING_INDICATOR"] = 18] = "TYPING_INDICATOR";
  IIBinMessageType2[IIBinMessageType2["READ_RECEIPT"] = 19] = "READ_RECEIPT";
  IIBinMessageType2[IIBinMessageType2["MESSAGE_STATUS"] = 20] = "MESSAGE_STATUS";
  IIBinMessageType2[IIBinMessageType2["USER_JOIN"] = 32] = "USER_JOIN";
  IIBinMessageType2[IIBinMessageType2["USER_LEAVE"] = 33] = "USER_LEAVE";
  IIBinMessageType2[IIBinMessageType2["USER_LIST"] = 34] = "USER_LIST";
  IIBinMessageType2[IIBinMessageType2["USER_STATUS"] = 35] = "USER_STATUS";
  IIBinMessageType2[IIBinMessageType2["BOT_MESSAGE"] = 48] = "BOT_MESSAGE";
  IIBinMessageType2[IIBinMessageType2["BOT_COMMAND"] = 49] = "BOT_COMMAND";
  IIBinMessageType2[IIBinMessageType2["BOT_STATUS"] = 50] = "BOT_STATUS";
  IIBinMessageType2[IIBinMessageType2["MCP_REQUEST"] = 51] = "MCP_REQUEST";
  IIBinMessageType2[IIBinMessageType2["MCP_RESPONSE"] = 52] = "MCP_RESPONSE";
  IIBinMessageType2[IIBinMessageType2["STRESS_TEST_START"] = 64] = "STRESS_TEST_START";
  IIBinMessageType2[IIBinMessageType2["STRESS_TEST_DATA"] = 65] = "STRESS_TEST_DATA";
  IIBinMessageType2[IIBinMessageType2["STRESS_TEST_RESULT"] = 66] = "STRESS_TEST_RESULT";
  IIBinMessageType2[IIBinMessageType2["BENCHMARK_REQUEST"] = 67] = "BENCHMARK_REQUEST";
  IIBinMessageType2[IIBinMessageType2["BENCHMARK_RESPONSE"] = 68] = "BENCHMARK_RESPONSE";
  IIBinMessageType2[IIBinMessageType2["INTEREST_GRAPH_EXPORT"] = 80] = "INTEREST_GRAPH_EXPORT";
  IIBinMessageType2[IIBinMessageType2["INTEREST_MATCH_REQUEST"] = 81] = "INTEREST_MATCH_REQUEST";
  IIBinMessageType2[IIBinMessageType2["INTEREST_MATCH_RESPONSE"] = 82] = "INTEREST_MATCH_RESPONSE";
  IIBinMessageType2[IIBinMessageType2["POLYTOPE_DATA"] = 83] = "POLYTOPE_DATA";
  return IIBinMessageType2;
})(IIBinMessageType || {});
var _IIBinProtocol = class _IIBinProtocol {
  constructor() {
    this.messageIdCounter = 0n;
  }
  /**
   * Serialize a message to binary format
   */
  serialize(messageType, payload, flags = 0) {
    const payloadBytes = this.serializePayload(messageType, payload);
    const totalSize = _IIBinProtocol.HEADER_SIZE + payloadBytes.length;
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    let offset = 0;
    view.setUint8(offset++, _IIBinProtocol.VERSION);
    view.setUint8(offset++, messageType);
    view.setUint8(offset++, flags);
    view.setUint8(offset++, 0);
    view.setUint32(offset, payloadBytes.length, true);
    offset += 4;
    view.setBigUint64(offset, BigInt(Date.now() * 1e3), true);
    offset += 8;
    view.setBigUint64(offset, ++this.messageIdCounter, true);
    offset += 8;
    uint8View.set(payloadBytes, offset);
    return uint8View;
  }
  /**
   * Deserialize binary data to message
   */
  deserialize(data) {
    if (data.length < _IIBinProtocol.HEADER_SIZE) {
      throw new Error("Invalid message: too short");
    }
    const view = new DataView(data.buffer, data.byteOffset);
    let offset = 0;
    const header = {
      version: view.getUint8(offset++),
      messageType: view.getUint8(offset++),
      flags: view.getUint8(offset++),
      reserved: view.getUint8(offset++),
      payloadLength: view.getUint32(offset, true),
      timestamp: view.getBigUint64(offset + 4, true),
      messageId: view.getBigUint64(offset + 12, true)
    };
    offset += 16;
    if (header.version !== _IIBinProtocol.VERSION) {
      throw new Error(`Unsupported protocol version: ${header.version}`);
    }
    if (data.length !== _IIBinProtocol.HEADER_SIZE + header.payloadLength) {
      throw new Error("Invalid message: length mismatch");
    }
    const payload = data.slice(offset, offset + header.payloadLength);
    return { header, payload };
  }
  /**
   * Serialize payload based on message type
   */
  serializePayload(messageType, payload) {
    switch (messageType) {
      case 1 /* PING */:
      case 2 /* PONG */:
        return new Uint8Array(0);
      case 16 /* TEXT_MESSAGE */:
        return this.serializeTextMessage(payload);
      case 17 /* MEDIA_MESSAGE */:
        return this.serializeMediaMessage(payload);
      case 35 /* USER_STATUS */:
        return this.serializeUserStatus(payload);
      case 48 /* BOT_MESSAGE */:
        return this.serializeBotMessage(payload);
      case 65 /* STRESS_TEST_DATA */:
        return this.serializeStressTestData(payload);
      case 68 /* BENCHMARK_RESPONSE */:
        return this.serializeBenchmarkResult(payload);
      default:
        return new TextEncoder().encode(JSON.stringify(payload));
    }
  }
  /**
   * Deserialize payload based on message type
   */
  deserializePayload(messageType, payload) {
    switch (messageType) {
      case 1 /* PING */:
      case 2 /* PONG */:
        return null;
      case 16 /* TEXT_MESSAGE */:
        return this.deserializeTextMessage(payload);
      case 17 /* MEDIA_MESSAGE */:
        return this.deserializeMediaMessage(payload);
      case 35 /* USER_STATUS */:
        return this.deserializeUserStatus(payload);
      case 48 /* BOT_MESSAGE */:
        return this.deserializeBotMessage(payload);
      case 65 /* STRESS_TEST_DATA */:
        return this.deserializeStressTestData(payload);
      case 68 /* BENCHMARK_RESPONSE */:
        return this.deserializeBenchmarkResult(payload);
      default:
        return JSON.parse(new TextDecoder().decode(payload));
    }
  }
  // Specific serialization methods
  serializeTextMessage(msg) {
    const encoder = new TextEncoder();
    const userIdBytes = encoder.encode(msg.userId);
    const roomIdBytes = encoder.encode(msg.roomId);
    const contentBytes = encoder.encode(msg.content);
    const messageIdBytes = encoder.encode(msg.messageId);
    const totalSize = 4 + userIdBytes.length + 4 + roomIdBytes.length + 4 + contentBytes.length + 8 + 4 + messageIdBytes.length;
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    let offset = 0;
    view.setUint32(offset, userIdBytes.length, true);
    offset += 4;
    uint8View.set(userIdBytes, offset);
    offset += userIdBytes.length;
    view.setUint32(offset, roomIdBytes.length, true);
    offset += 4;
    uint8View.set(roomIdBytes, offset);
    offset += roomIdBytes.length;
    view.setUint32(offset, contentBytes.length, true);
    offset += 4;
    uint8View.set(contentBytes, offset);
    offset += contentBytes.length;
    view.setBigUint64(offset, BigInt(msg.timestamp), true);
    offset += 8;
    view.setUint32(offset, messageIdBytes.length, true);
    offset += 4;
    uint8View.set(messageIdBytes, offset);
    return uint8View;
  }
  deserializeTextMessage(payload) {
    const view = new DataView(payload.buffer, payload.byteOffset);
    const decoder = new TextDecoder();
    let offset = 0;
    const userIdLength = view.getUint32(offset, true);
    offset += 4;
    const userId = decoder.decode(payload.slice(offset, offset + userIdLength));
    offset += userIdLength;
    const roomIdLength = view.getUint32(offset, true);
    offset += 4;
    const roomId = decoder.decode(payload.slice(offset, offset + roomIdLength));
    offset += roomIdLength;
    const contentLength = view.getUint32(offset, true);
    offset += 4;
    const content = decoder.decode(payload.slice(offset, offset + contentLength));
    offset += contentLength;
    const timestamp = Number(view.getBigUint64(offset, true));
    offset += 8;
    const messageIdLength = view.getUint32(offset, true);
    offset += 4;
    const messageId = decoder.decode(payload.slice(offset, offset + messageIdLength));
    return { userId, roomId, content, timestamp, messageId };
  }
  serializeMediaMessage(msg) {
    const encoder = new TextEncoder();
    const userIdBytes = encoder.encode(msg.userId);
    const roomIdBytes = encoder.encode(msg.roomId);
    const mediaTypeBytes = encoder.encode(msg.mediaType);
    const fileNameBytes = encoder.encode(msg.fileName || "");
    const mimeTypeBytes = encoder.encode(msg.mimeType);
    const messageIdBytes = encoder.encode(msg.messageId);
    const totalSize = 4 + userIdBytes.length + 4 + roomIdBytes.length + 4 + mediaTypeBytes.length + 4 + msg.mediaData.length + 4 + fileNameBytes.length + 4 + mimeTypeBytes.length + 8 + 4 + messageIdBytes.length;
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const uint8View = new Uint8Array(buffer);
    let offset = 0;
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
  deserializeMediaMessage(payload) {
    const view = new DataView(payload.buffer, payload.byteOffset);
    const decoder = new TextDecoder();
    let offset = 0;
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
    const mediaType = decoder.decode(payload.slice(offset, offset + mediaTypeLength));
    offset += mediaTypeLength;
    const mediaDataLength = view.getUint32(offset, true);
    offset += 4;
    const mediaData = payload.slice(offset, offset + mediaDataLength);
    offset += mediaDataLength;
    const fileNameLength = view.getUint32(offset, true);
    offset += 4;
    const fileName = decoder.decode(payload.slice(offset, offset + fileNameLength)) || void 0;
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
  serializeUserStatus(status) {
    const encoder = new TextEncoder();
    const userIdBytes = encoder.encode(status.userId);
    const statusBytes = encoder.encode(status.status);
    const customStatusBytes = encoder.encode(status.customStatus || "");
    const totalSize = 4 + userIdBytes.length + 4 + statusBytes.length + 8 + 4 + customStatusBytes.length;
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
  deserializeUserStatus(payload) {
    const view = new DataView(payload.buffer, payload.byteOffset);
    const decoder = new TextDecoder();
    let offset = 0;
    const userIdLength = view.getUint32(offset, true);
    offset += 4;
    const userId = decoder.decode(payload.slice(offset, offset + userIdLength));
    offset += userIdLength;
    const statusLength = view.getUint32(offset, true);
    offset += 4;
    const status = decoder.decode(payload.slice(offset, offset + statusLength));
    offset += statusLength;
    const lastSeen = Number(view.getBigUint64(offset, true));
    offset += 8;
    const customStatusLength = view.getUint32(offset, true);
    offset += 4;
    const customStatus = decoder.decode(payload.slice(offset, offset + customStatusLength)) || void 0;
    return { userId, status, lastSeen, customStatus };
  }
  serializeBotMessage(msg) {
    return new TextEncoder().encode(JSON.stringify(msg));
  }
  deserializeBotMessage(payload) {
    return JSON.parse(new TextDecoder().decode(payload));
  }
  serializeStressTestData(data) {
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
  deserializeStressTestData(payload) {
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
  serializeBenchmarkResult(result) {
    return new TextEncoder().encode(JSON.stringify(result));
  }
  deserializeBenchmarkResult(payload) {
    return JSON.parse(new TextDecoder().decode(payload));
  }
};
_IIBinProtocol.HEADER_SIZE = 24;
// bytes
_IIBinProtocol.VERSION = 1;
var IIBinProtocol = _IIBinProtocol;
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  DataType,
  IIBINClient,
  IIBINDecoder,
  IIBINEncoder,
  IIBinMessageType,
  IIBinProtocol,
  MessageType,
  compareWithJSON,
  createCallAnswer,
  createCallOffer,
  createFileMessage,
  createIceCandidate,
  createTextMessage,
  createVoiceMessage
});
