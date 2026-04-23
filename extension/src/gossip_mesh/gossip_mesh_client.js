/**
 * GossipMesh Client - JavaScript/WebRTC Implementation
 *
 * Runs in the browser. Handles:
 * - Connection to SFU signaling server
 * - WebRTC peer connections to neighbors (with NAT traversal)
 * - Gossip frame forwarding
 * - Fallback to SFU relay when P2P fails
 */

class GossipMeshClient {
    constructor(config = {}) {
        this.localPeerId = config.peerId || this.generatePeerId();
        this.roomId = config.roomId || null;
        this.sfuUrl = config.sfuUrl || 'wss://localhost/sfu';

        // Gossip parameters
        this.ttl = config.ttl || GossipMeshClient.DEFAULT_TTL;
        this.forwardCount = config.forwardCount || GossipMeshClient.FORWARD_COUNT;

        // WebRTC config for NAT traversal
        this.iceServers = config.iceServers || this.getIceServers(config);

        // State
        this.neighbors = new Map(); // peerId -> { connection, state, bandwidth }
        this.peerConnection = null; // WebSocket to SFU
        this.dataChannels = new Map(); // peerId -> RTCDataChannel
        this.relayFallback = new Map(); // peerId -> using SFU relay

        // Gossip state
        this.seenFrames = new Map();
        this.seenWindowSize = 256;

        // Callbacks
        this.onFrameReceived = config.onFrameReceived || null;
        this.onPeerConnected = config.onPeerConnected || null;
        this.onPeerDisconnected = config.onPeerDisconnected || null;
        this.onError = config.onError || null;

        // Statistics
        this.stats = {
            framesReceived: 0,
            framesForwarded: 0,
            framesViaRelay: 0,
            duplicatesDropped: 0,
            neighborsConnected: 0,
            p2pFailures: 0,
        };

        // Debug
        this.debug = config.debug || false;

        // Optional transport codec (IIBIN-style): keeps JSON compatibility
        // while allowing binary payload transport for gossip peer messages.
        this.transportCodec = this.createTransportCodec(
            config.transportCodec || config.iibin || null
        );
    }

    static DEFAULT_TTL = 4;
    static FORWARD_COUNT = 2;
    static SEEN_WINDOW_SIZE = 256;

    /**
     * Get ICE servers - STUN + TURN configuration
     */
    getIceServers(config) {
        const servers = [
            // Google's STUN (free, public)
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun2.l.google.com:19302' },
        ];

        // Add TURN if configured (production需要自己的TURN服务器)
        if (config.turnUrl) {
            servers.push({
                urls: config.turnUrl,
                username: config.turnUser || '',
                credential: config.turnCredential || '',
            });
        }

        return servers;
    }

    generatePeerId() {
        return Math.random().toString(36).substr(2, 9);
    }

    createTransportCodec(codecConfig) {
        if (!codecConfig) {
            return null;
        }

        if (typeof codecConfig.encode === 'function' && typeof codecConfig.decode === 'function') {
            return codecConfig;
        }

        const Encoder = codecConfig.Encoder || codecConfig.encoderClass || null;
        const Decoder = codecConfig.Decoder || codecConfig.decoderClass || null;
        const messageType = typeof codecConfig.messageType === 'number'
            ? codecConfig.messageType
            : (
                codecConfig.MessageType &&
                typeof codecConfig.MessageType.TEXT_MESSAGE === 'number'
                    ? codecConfig.MessageType.TEXT_MESSAGE
                    : null
            );

        if (!Encoder || !Decoder || typeof messageType !== 'number') {
            return null;
        }

        const encoder = codecConfig.encoder || new Encoder();

        return {
            encode: (payload) => encoder.encode({
                type: messageType,
                data: payload,
                timestamp: Date.now(),
            }),
            decode: (decoded) => {
                if (!decoded || decoded.type !== messageType) {
                    return null;
                }
                if (!decoded.data || typeof decoded.data !== 'object') {
                    return null;
                }
                return decoded.data;
            },
            Decoder,
        };
    }

    encodeTransportMessage(payload) {
        if (!this.transportCodec) {
            return JSON.stringify(payload);
        }

        try {
            return this.transportCodec.encode(payload);
        } catch (error) {
            console.warn('[GossipMesh] Transport encode failed, falling back to JSON', error);
            return JSON.stringify(payload);
        }
    }

    async payloadToArrayBuffer(payload) {
        if (payload instanceof ArrayBuffer) {
            return payload;
        }

        if (ArrayBuffer.isView(payload)) {
            return payload.buffer.slice(
                payload.byteOffset,
                payload.byteOffset + payload.byteLength
            );
        }

        if (typeof Blob !== 'undefined' && payload instanceof Blob) {
            return payload.arrayBuffer();
        }

        return null;
    }

    async decodeTransportMessage(payload) {
        if (typeof payload === 'string') {
            try {
                return JSON.parse(payload);
            } catch (error) {
                if (!this.transportCodec) {
                    return null;
                }
            }
        }

        if (!this.transportCodec) {
            return null;
        }

        try {
            const buffer = await this.payloadToArrayBuffer(payload);
            if (!buffer) {
                return null;
            }

            const decoded = new this.transportCodec.Decoder(buffer).decode();
            return this.transportCodec.decode(decoded);
        } catch (error) {
            console.warn('[GossipMesh] Transport decode failed', error);
            return null;
        }
    }

    /**
     * Connect to the SFU signaling server
     */
    async connect(sessionToken) {
        return new Promise((resolve, reject) => {
            this.peerConnection = new WebSocket(this.buildSfuUrl(sessionToken));
            this.peerConnection.binaryType = 'arraybuffer';

            this.peerConnection.onopen = () => {
                console.log('[GossipMesh] Connected to SFU');

                // Send join message
                this.sendToSFU({
                    type: 'sfu/join',
                    room_id: this.roomId,
                    peer_id: this.localPeerId,
                });

                resolve();
            };

            this.peerConnection.onmessage = (event) => {
                void this.handleSFUPayload(event.data);
            };

            this.peerConnection.onerror = (error) => {
                console.error('[GossipMesh] SFU connection error:', error);
                if (this.onError) this.onError(error);
                reject(error);
            };

            this.peerConnection.onclose = () => {
                console.log('[GossipMesh] Disconnected from SFU');
                this.handleDisconnect();
            };
        });
    }

    buildSfuUrl(sessionToken) {
        const separator = this.sfuUrl.includes('?') ? '&' : '?';
        const transport = this.transportCodec ? 'iibin' : 'json';
        return `${this.sfuUrl}${separator}token=${encodeURIComponent(sessionToken)}&transport=${transport}`;
    }

    /**
     * Handle messages from SFU signaling server
     */
    async handleSFUPayload(payload) {
        const message = await this.decodeTransportMessage(payload);
        if (!message || typeof message !== 'object') {
            return;
        }

        this.handleSFUMessage(message);
    }

    handleSFUMessage(message) {
        switch (message.type) {
            case 'sfu/welcome':
                console.log('[GossipMesh] Welcome, peer_id:', message.peer_id);
                break;

            case 'sfu/peers':
                // Phase 1: Bootstrap - connect to these peers
                this.connectToPeers(message.peers);
                break;

            case 'sfu/peer_joined':
                // New peer joined, maybe connect to them
                this.maybeConnectToPeer(message.peer_id, message.name);
                break;

            case 'sfu/peer_left':
                this.handlePeerLeft(message.peer_id);
                break;

            case 'ice-candidate':
                // Forward ICE candidate to appropriate peer
                this.handleRemoteCandidate(message.peer_id, message.candidate);
                break;

            default:
                console.warn('[GossipMesh] Unknown message type:', message.type);
        }
    }

    /**
     * Connect to bootstrap peers from SFU
     */
    async connectToPeers(peers) {
        for (const peer of peers) {
            if (peer.peer_id !== this.localPeerId) {
                await this.createPeerConnection(peer.peer_id);
            }
        }
    }

    /**
     * Create WebRTC connection to a peer
     */
    async createPeerConnection(peerId) {
        if (this.neighbors.has(peerId)) {
            return; // Already connected
        }

        const pc = new RTCPeerConnection({
            iceServers: this.iceServers,
            iceTransportPolicy: 'all',  // Try 'all' for relay fallback
        });

        // Create data channel for gossip frames
        const channel = pc.createDataChannel('gossip', {
            ordered: true,
            maxRetransmits: 3,
        });

        channel.onopen = () => {
            console.log(`[GossipMesh] Data channel open to peer ${peerId}`);
            this.stats.neighborsConnected++;
            if (this.onPeerConnected) this.onPeerConnected(peerId);
        };

        channel.onmessage = (event) => {
            void this.handlePeerPayload(peerId, event.data);
        };

        channel.onclose = () => {
            console.log(`[GossipMesh] Data channel closed to peer ${peerId}`);
            this.handlePeerDisconnect(peerId);
        };

        channel.onerror = (error) => {
            console.error(`[GossipMesh] Data channel error:`, error);
        };

        // Handle ICE candidates
        pc.onicecandidate = (event) => {
            if (event.candidate) {
                // Send via SFU to target peer
                this.sendToSFU({
                    type: 'ice-candidate',
                    target_peer_id: peerId,
                    candidate: event.candidate,
                });
            }
        };

        pc.oniceconnectionstatechange = () => {
            console.log(`[GossipMesh] ICE state to ${peerId}:`, pc.iceConnectionState);
        };

        pc.onconnectionstatechange = () => {
            console.log(`[GossipMesh] Connection state to ${peerId}:`, pc.connectionState);
        };

        // Create and send offer
        const offer = await pc.createOffer({
            offerToReceiveAudio: true,
            offerToReceiveVideo: true,
        });
        await pc.setLocalDescription(offer);

        this.sendToSFU({
            type: 'offer',
            target_peer_id: peerId,
            sdp: pc.localDescription,
        });

        this.neighbors.set(peerId, {
            pc,
            channel,
            state: 'connecting',
            bandwidth: 'fair',
        });
    }

    /**
     * Handle incoming WebRTC offer from SFU
     */
    async handleOffer(peerId, sdp) {
        let neighbor = this.neighbors.get(peerId);

        if (!neighbor) {
            const pc = new RTCPeerConnection({
                iceServers: this.iceServers,
                iceTransportPolicy: 'all',
            });

            pc.ondatachannel = (event) => {
                const channel = event.channel;
                channel.onmessage = (e) => {
                    void this.handlePeerPayload(peerId, e.data);
                };
                channel.onopen = () => {
                    this.stats.neighborsConnected++;
                    if (this.onPeerConnected) this.onPeerConnected(peerId);
                };
                channel.onclose = () => {
                    this.handlePeerDisconnect(peerId);
                };
            };

            pc.onicecandidate = (event) => {
                if (event.candidate) {
                    this.sendToSFU({
                        type: 'ice-candidate',
                        target_peer_id: peerId,
                        candidate: event.candidate,
                    });
                }
            };

            pc.oniceconnectionstatechange = () => {
                console.log(`[GossipMesh] ICE state from ${peerId}:`, pc.iceConnectionState);

                // Handle P2P failure - fallback to relay
                if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
                    this.handleP2PFailure(peerId);
                }
            };

            neighbor = { pc, state: 'connecting', bandwidth: 'fair' };
            this.neighbors.set(peerId, neighbor);
        }

        await neighbor.pc.setRemoteDescription(new RTCSessionDescription(sdp));
        const answer = await neighbor.pc.createAnswer();
        await neighbor.pc.setLocalDescription(answer);

        this.sendToSFU({
            type: 'answer',
            target_peer_id: peerId,
            sdp: neighbor.pc.localDescription,
        });
    }

    /**
     * Handle P2P failure - fall back to SFU relay
     */
    handleP2PFailure(peerId) {
        const neighbor = this.neighbors.get(peerId);
        if (!neighbor) return;

        if (neighbor.relayTried) {
            // Already tried relay, give up
            this.stats.p2pFailures++;
            console.warn(`[GossipMesh] P2P failed for ${peerId}, no relay available`);
            this.handlePeerDisconnect(peerId);
            return;
        }

        console.log(`[GossipMesh] P2P failed for ${peerId}, trying SFU relay...`);

        // Request relay via SFU
        this.sendToSFU({
            type: 'relay/request',
            target_peer_id: peerId,
        });

        neighbor.relayTried = true;
        neighbor.state = 'relay_pending';
    }

    /**
     * Handle relay mode enabled by SFU
     */
    handleRelayEnabled(peerId) {
        const neighbor = this.neighbors.get(peerId);
        if (!neighbor) return;

        neighbor.state = 'relay';
        neighbor.relayUsed = true;

        console.log(`[GossipMesh] Using SFU relay for ${peerId}`);

        if (this.onPeerConnected) {
            this.onPeerConnected(peerId);
        }
    }

    /**
     * Send via SFU relay instead of P2P
     */
    sendViaRelay(peerId, message) {
        this.sendToSFU({
            type: 'relay/frame',
            target_peer_id: peerId,
            data: message,
        });
        this.stats.framesViaRelay++;
    }

    /**
     * Handle ICE candidate from remote peer
     */
    async handleRemoteCandidate(peerId, candidate) {
        const neighbor = this.neighbors.get(peerId);
        if (neighbor && neighbor.pc) {
            try {
                await neighbor.pc.addIceCandidate(new RTCIceCandidate(candidate));
            } catch (e) {
                console.error(`[GossipMesh] Failed to add ICE candidate:`, e);
            }
        }
    }

    /**
     * Handle message from peer via WebRTC data channel
     */
    async handlePeerPayload(peerId, payload) {
        const message = await this.decodeTransportMessage(payload);
        if (!message || typeof message !== 'object') {
            return;
        }

        this.handlePeerMessage(peerId, message);
    }

    handlePeerMessage(peerId, message) {
        if (message.type === 'frame') {
            this.receiveFrame(
                message.publisher_id,
                message.sequence,
                message.ttl,
                this.base64ToArrayBuffer(message.data)
            );
        } else if (message.type === 'neighbors') {
            // Phase 2: Neighbor exchange
            this.handleNeighborList(peerId, message.neighbors);
        }
    }

    /**
     * Receive a gossip frame
     */
    receiveFrame(publisherId, sequence, ttl, data) {
        const frameKey = `${publisherId}:${sequence}`;

        // Check for duplicates
        if (this.seenFrames.has(frameKey)) {
            this.stats.duplicatesDropped++;
            return;
        }

        // Mark as seen
        this.seenFrames.set(frameKey, Date.now());

        // Prune old entries
        if (this.seenFrames.size > this.seenWindowSize) {
            const now = Date.now();
            for (const [key, timestamp] of this.seenFrames) {
                if (now - timestamp > 60000) { // Remove entries older than 1 min
                    this.seenFrames.delete(key);
                }
            }
        }

        this.stats.framesReceived++;

        // Notify callback
        if (this.onFrameReceived) {
            this.onFrameReceived(publisherId, sequence, data);
        }

        // Forward if TTL > 0
        if (ttl > 0) {
            this.forwardFrame(publisherId, sequence, ttl - 1, data);
        }
    }

    /**
     * Forward frame to random subset of neighbors
     */
    forwardFrame(publisherId, sequence, newTtl, data) {
        const neighborIds = Array.from(this.neighbors.keys());

        if (neighborIds.length === 0) return;

        // Random selection (deterministic based on frame)
        const seed = publisherId ^ (sequence << 16) ^ 0x12345678;
        this.seededRandom(seed);

        // Shuffle
        for (let i = neighborIds.length - 1; i > 0; i--) {
            const j = Math.floor(this.seededRandomNext() * (i + 1));
            [neighborIds[i], neighborIds[j]] = [neighborIds[j], neighborIds[i]];
        }

        const forwardTo = neighborIds.slice(0, this.forwardCount);

        for (const peerId of forwardTo) {
            const neighbor = this.neighbors.get(peerId);

            if (!neighbor) continue;

            // Check connection state
            if (neighbor.state === 'relay') {
                // Use SFU relay
                this.sendViaRelay(peerId, {
                    type: 'frame',
                    publisher_id: publisherId,
                    sequence: sequence,
                    ttl: newTtl,
                    data: this.arrayBufferToBase64(data),
                });
                this.stats.framesForwarded++;
            } else if (neighbor.channel && neighbor.channel.readyState === 'open') {
                // Use P2P data channel
                neighbor.channel.send(this.encodeTransportMessage({
                    type: 'frame',
                    publisher_id: publisherId,
                    sequence: sequence,
                    ttl: newTtl,
                    data: this.arrayBufferToBase64(data),
                }));
                this.stats.framesForwarded++;
            }
        }

        this.resetRandom();
    }

    /**
     * Periodically exchange neighbor lists (Phase 2)
     */
    startNeighborExchange() {
        setInterval(() => {
            const neighborIds = Array.from(this.neighbors.keys());

            for (const [peerId, neighbor] of this.neighbors) {
                if (neighbor.channel && neighbor.channel.readyState === 'open') {
                    neighbor.channel.send(this.encodeTransportMessage({
                        type: 'neighbors',
                        neighbors: neighborIds,
                    }));
                }
            }
        }, 30000); // Every 30 seconds
    }

    /**
     * Handle neighbor list from peer
     */
    handleNeighborList(peerId, neighborList) {
        // Potentially connect to new neighbors to maintain graph
        // Limit total neighbors to avoid overload
        const maxNeighbors = 6;

        if (this.neighbors.size < maxNeighbors) {
            for (const newPeerId of neighborList) {
                if (newPeerId !== this.localPeerId && !this.neighbors.has(newPeerId)) {
                    this.createPeerConnection(newPeerId);
                }
            }
        }
    }

    handlePeerDisconnect(peerId) {
        this.neighbors.delete(peerId);
        this.stats.neighborsConnected--;

        if (this.onPeerDisconnected) {
            this.onPeerDisconnected(peerId);
        }

        // Try to connect to new peers to maintain graph
        this.requestNewPeersFromSFU();
    }

    handlePeerLeft(peerId) {
        this.handlePeerDisconnect(peerId);
    }

    maybeConnectToPeer(peerId) {
        // Stochastic: connect with some probability
        if (Math.random() < 0.3 && !this.neighbors.has(peerId)) {
            this.createPeerConnection(peerId);
        }
    }

    requestNewPeersFromSFU() {
        this.sendToSFU({
            type: 'sfu/request_peers',
            room_id: this.roomId,
        });
    }

    sendToSFU(message) {
        if (this.peerConnection && this.peerConnection.readyState === WebSocket.OPEN) {
            this.peerConnection.send(this.encodeTransportMessage(message));
        }
    }

    handleDisconnect() {
        // Close all peer connections
        for (const [peerId, neighbor] of this.neighbors) {
            neighbor.pc.close();
        }
        this.neighbors.clear();
    }

    /**
     * Utility: Base64 to ArrayBuffer
     */
    base64ToArrayBuffer(base64) {
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * Utility: ArrayBuffer to Base64
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    /**
     * Deterministic random for forwarding decisions
     */
    seededRandom(seed) {
        this._seed = seed;
    }

    seededRandomNext() {
        this._seed = (this._seed * 1103515245 + 12345) & 0x7fffffff;
        return this._seed / 0x7fffffff;
    }

    resetRandom() {
        this._seed = null;
    }

    /**
     * Get statistics
     */
    getStats() {
        return {
            ...this.stats,
            neighbors: this.neighbors.size,
            seen_frames: this.seenFrames.size,
        };
    }

    /**
     * Close all connections
     */
    close() {
        this.handleDisconnect();
        if (this.peerConnection) {
            this.peerConnection.close();
        }
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = GossipMeshClient;
}
