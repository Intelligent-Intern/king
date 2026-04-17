/**
 * IIBIN (Intelligent Indexed Binary) JavaScript Library
 * =====================================================
 *
 * Ultra-efficient binary protocol for real-time communication
 * Optimized for WebSocket messaging and media streaming
 */
interface IIBINMessage {
    type: MessageType;
    id?: string;
    timestamp?: number;
    data?: any;
    metadata?: Record<string, any>;
}
declare enum MessageType {
    TEXT_MESSAGE = 1,
    VOICE_MESSAGE = 2,
    FILE_MESSAGE = 3,
    IMAGE_MESSAGE = 4,
    VIDEO_MESSAGE = 5,
    USER_JOIN = 16,
    USER_LEAVE = 17,
    USER_TYPING = 18,
    USER_PRESENCE = 19,
    MESSAGE_READ = 20,
    MESSAGE_DELIVERED = 21,
    CALL_OFFER = 32,
    CALL_ANSWER = 33,
    CALL_ICE_CANDIDATE = 34,
    CALL_HANGUP = 35,
    CALL_MUTE = 36,
    CALL_UNMUTE = 37,
    GROUP_CREATE = 48,
    GROUP_JOIN = 49,
    GROUP_LEAVE = 50,
    GROUP_UPDATE = 51,
    PING = 240,
    PONG = 241,
    METRICS = 242,
    ERROR = 255
}
declare enum DataType {
    NULL = 0,
    BOOLEAN = 1,
    INT8 = 2,
    INT16 = 3,
    INT32 = 4,
    INT64 = 5,
    FLOAT32 = 6,
    FLOAT64 = 7,
    STRING = 8,
    BINARY = 9,
    ARRAY = 10,
    OBJECT = 11,
    TIMESTAMP = 12
}
declare class IIBINEncoder {
    private buffer;
    private view;
    private position;
    private textEncoder;
    constructor(initialSize?: number);
    private ensureCapacity;
    private writeUInt8;
    private writeUInt16;
    private writeUInt32;
    private writeFloat64;
    private writeString;
    private writeBinary;
    private writeValue;
    encode(message: IIBINMessage): ArrayBuffer;
}
declare class IIBINDecoder {
    private view;
    private position;
    private textDecoder;
    constructor(buffer: ArrayBuffer);
    private readUInt8;
    private readUInt16;
    private readUInt32;
    private readFloat64;
    private readString;
    private readBinary;
    private readValue;
    decode(): IIBINMessage;
}
declare class IIBINClient {
    private ws;
    private encoder;
    private reconnectAttempts;
    private maxReconnectAttempts;
    private reconnectDelay;
    private pingInterval;
    private messageHandlers;
    private metrics;
    constructor();
    connect(url: string): Promise<void>;
    private reconnect;
    private startPingInterval;
    private stopPingInterval;
    private handleMessage;
    send(message: IIBINMessage): void;
    on(messageType: MessageType, handler: (message: IIBINMessage) => void): void;
    off(messageType: MessageType, handler: (message: IIBINMessage) => void): void;
    disconnect(): void;
    getMetrics(): {
        uptime: number;
        isConnected: boolean;
        messagesSent: number;
        messagesReceived: number;
        bytesTransferred: number;
        averageLatency: number;
        connectionTime: number;
    };
    isConnected(): boolean;
}
declare function createTextMessage(text: string, chatId?: string): IIBINMessage;
declare function createVoiceMessage(audioData: Uint8Array, duration: number, chatId?: string): IIBINMessage;
declare function createFileMessage(file: File, chatId?: string): IIBINMessage;
declare function createCallOffer(offer: RTCSessionDescriptionInit, callId: string): IIBINMessage;
declare function createCallAnswer(answer: RTCSessionDescriptionInit, callId: string): IIBINMessage;
declare function createIceCandidate(candidate: RTCIceCandidateInit, callId: string): IIBINMessage;
declare function compareWithJSON(data: any): {
    iibin: number;
    json: number;
    savings: number;
};

/**
 * IIBIN Protocol - TypeScript Implementation
 * ==========================================
 *
 * High-performance binary protocol for WebSocket communication
 * with efficient serialization and type safety.
 */
declare enum IIBinMessageType {
    PING = 1,
    PONG = 2,
    CONNECT = 3,
    DISCONNECT = 4,
    ERROR = 5,
    TEXT_MESSAGE = 16,
    MEDIA_MESSAGE = 17,
    TYPING_INDICATOR = 18,
    READ_RECEIPT = 19,
    MESSAGE_STATUS = 20,
    USER_JOIN = 32,
    USER_LEAVE = 33,
    USER_LIST = 34,
    USER_STATUS = 35,
    BOT_MESSAGE = 48,
    BOT_COMMAND = 49,
    BOT_STATUS = 50,
    MCP_REQUEST = 51,
    MCP_RESPONSE = 52,
    STRESS_TEST_START = 64,
    STRESS_TEST_DATA = 65,
    STRESS_TEST_RESULT = 66,
    BENCHMARK_REQUEST = 67,
    BENCHMARK_RESPONSE = 68,
    INTEREST_GRAPH_EXPORT = 80,
    INTEREST_MATCH_REQUEST = 81,
    INTEREST_MATCH_RESPONSE = 82,
    POLYTOPE_DATA = 83
}
interface IIBinHeader {
    version: number;
    messageType: IIBinMessageType;
    flags: number;
    reserved: number;
    payloadLength: number;
    timestamp: bigint;
    messageId: bigint;
}
interface IIBinMessage {
    header: IIBinHeader;
    payload: Uint8Array;
}
interface TextMessage {
    userId: string;
    roomId: string;
    content: string;
    timestamp: number;
    messageId: string;
}
interface MediaMessage {
    userId: string;
    roomId: string;
    mediaType: 'image' | 'video' | 'audio' | 'file';
    mediaData: Uint8Array;
    fileName?: string;
    mimeType: string;
    timestamp: number;
    messageId: string;
}
interface UserStatus {
    userId: string;
    status: 'online' | 'offline' | 'away' | 'busy';
    lastSeen: number;
    customStatus?: string;
}
interface BotMessage {
    botId: string;
    botName: string;
    roomId: string;
    content: string;
    messageType: 'text' | 'command_response' | 'error';
    timestamp: number;
    messageId: string;
    metadata?: Record<string, any>;
}
interface StressTestData {
    testId: string;
    sequenceNumber: number;
    payload: Uint8Array;
    timestamp: number;
    expectedResponseTime: number;
}
interface BenchmarkResult {
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
declare class IIBinProtocol {
    private static readonly HEADER_SIZE;
    private static readonly VERSION;
    private messageIdCounter;
    /**
     * Serialize a message to binary format
     */
    serialize(messageType: IIBinMessageType, payload: any, flags?: number): Uint8Array;
    /**
     * Deserialize binary data to message
     */
    deserialize(data: Uint8Array): IIBinMessage;
    /**
     * Serialize payload based on message type
     */
    private serializePayload;
    /**
     * Deserialize payload based on message type
     */
    deserializePayload(messageType: IIBinMessageType, payload: Uint8Array): any;
    private serializeTextMessage;
    private deserializeTextMessage;
    private serializeMediaMessage;
    private deserializeMediaMessage;
    private serializeUserStatus;
    private deserializeUserStatus;
    private serializeBotMessage;
    private deserializeBotMessage;
    private serializeStressTestData;
    private deserializeStressTestData;
    private serializeBenchmarkResult;
    private deserializeBenchmarkResult;
}

export { type BenchmarkResult, type BotMessage, DataType, IIBINClient, IIBINDecoder, IIBINEncoder, type IIBINMessage, type IIBinHeader, type IIBinMessage, IIBinMessageType, IIBinProtocol, type MediaMessage, MessageType, type StressTestData, type TextMessage, type UserStatus, compareWithJSON, createCallAnswer, createCallOffer, createFileMessage, createIceCandidate, createTextMessage, createVoiceMessage };
