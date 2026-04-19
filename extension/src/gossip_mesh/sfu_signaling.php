<?php

/**
 * GossipMesh SFU Signaling Server (PHP)
 * 
 * Runs on server side. Handles:
 * - Phase 1: Bootstrap - gives new peers random existing peers to connect to
 * - Phase 2: Peer exchange relay - forwards neighbor lists between peers  
 * - Phase 3: Graph maintenance - handles churn, peer failures
 * 
 * Media flows P2P via WebRTC data channels after initial signaling.
 */

require_once __DIR__ . '/gossip_mesh.php';

class GossipMeshSFU
{
    const MAX_PEERS_PER_ROOM = 500;
    const BOOTSTRAP_PEER_COUNT = 5;
    const NEIGHBOR_EXCHANGE_INTERVAL = 30;
    const PEER_TIMEOUT = 60;
    const MAX_RELAY_PEERS = 10; // Limit relay load
    
    private array $rooms = [];
    private array $peerStates = [];
    private array $socketToPeerId = [];
    private array $relayPeers = []; // Peers willing to relay
    
    /**
     * Register a peer willing to relay for others (good bandwidth)
     */
    public function registerRelayPeer(int $peerId, string $bandwidth = 'good'): void
    {
        if ($bandwidth === 'good' && count($this->relayPeers) < self::MAX_RELAY_PEERS) {
            $this->relayPeers[$peerId] = true;
        }
    }
    
    /**
     * Handle relay request from peer whose P2P failed
     */
    public function handleRelayRequest(int $requesterId, int $targetId): array
    {
        // Find a relay peer for the target
        if (!isset($this->relayPeers[$targetId])) {
            // Target not available as relay - try any relay peer
            $relays = array_keys($this->relayPeers);
            if (empty($relays)) {
                return ['error' => 'No relay available'];
            }
            shuffle($relays);
            $targetId = $relays[0];
        }
        
        // Notify both peers
        return [
            'ok' => true,
            'relay_peer_id' => $targetId,
            'mode' => 'relay',
        ];
    }
    
    /**
     * Handle relay frame forwarding
     */
    public function handleRelayFrame(int $relayPeerId, int $targetId, array $frame): void
    {
        $roomId = $this->peerStates[$relayPeerId]['room_id'] ?? null;
        if (!$roomId || !isset($this->rooms[$roomId]['peers'][$targetId])) {
            return;
        }
        
        $targetPeer = $this->rooms[$roomId]['peers'][$targetId];
        $this->send($targetPeer['websocket'] ?? null, $frame);
    }
    
    public function handleJoin($websocket, string $roomId, string $userId, string $userName, string $role = 'publisher'): array
    {
        $peerId = spl_object_id($websocket);
        $this->socketToPeerId[$peerId] = $peerId;
        
        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = [
                'peers' => [],
                'created_at' => time(),
            ];
        }
        
        if (count($this->rooms[$roomId]['peers']) >= self::MAX_PEERS_PER_ROOM) {
            return ['error' => 'Room is full', 'code' => 'room_full'];
        }
        
        $this->rooms[$roomId]['peers'][$peerId] = [
            'peer_id' => $peerId,
            'user_id' => $userId,
            'user_name' => $userName,
            'role' => $role,
            'joined_at' => time(),
            'last_seen' => time(),
            'neighbors' => [],
            'bandwidth' => 'fair',
        ];
        
        $this->peerStates[$peerId] = ['room_id' => $roomId, 'connected' => true];
        
        $this->send($websocket, [
            'type' => 'sfu/welcome',
            'peer_id' => $peerId,
            'room_id' => $roomId,
            'server_time' => time(),
        ]);
        
        $bootstrapPeers = $this->getBootstrapPeers($roomId, $peerId);
        if (!empty($bootstrapPeers)) {
            $this->send($websocket, [
                'type' => 'sfu/peers',
                'peers' => $bootstrapPeers,
            ]);
        }
        
        $this->broadcastToRoom($roomId, [
            'type' => 'sfu/peer_joined',
            'peer_id' => $peerId,
            'user_id' => $userId,
            'name' => $userName,
            'role' => $role,
        ], $peerId);
        
        return ['ok' => true, 'peer_id' => $peerId];
    }
    
    private function getBootstrapPeers(string $roomId, int $excludePeerId): array
    {
        if (!isset($this->rooms[$roomId])) return [];
        
        $peers = array_filter(
            array_keys($this->rooms[$roomId]['peers']),
            fn($id) => $id !== $excludePeerId
        );
        
        if (empty($peers)) return [];
        
        shuffle($peers);
        $bootstrap = array_slice($peers, 0, self::BOOTSTRAP_PEER_COUNT);
        
        return array_map(function($peerId) use ($roomId) {
            $peer = $this->rooms[$roomId]['peers'][$peerId];
            return [
                'peer_id' => $peerId,
                'user_id' => $peer['user_id'],
                'name' => $peer['user_name'],
            ];
        }, $bootstrap);
    }
    
    public function handleNeighbors(int $peerId, array $neighbors): void
    {
        if (!isset($this->peerStates[$peerId])) return;
        
        $roomId = $this->peerStates[$peerId]['room_id'];
        if (!isset($this->rooms[$roomId]['peers'][$peerId])) return;
        
        $this->rooms[$roomId]['peers'][$peerId]['neighbors'] = $neighbors;
    }
    
    public function handleDisconnect($websocket): void
    {
        $peerId = spl_object_id($websocket);
        
        if (!isset($this->peerStates[$peerId])) return;
        
        $roomId = $this->peerStates[$peerId]['room_id'];
        
        if (isset($this->rooms[$roomId]['peers'][$peerId])) {
            $peer = $this->rooms[$roomId]['peers'][$peerId];
            
            $this->broadcastToRoom($roomId, [
                'type' => 'sfu/peer_left',
                'peer_id' => $peerId,
                'user_id' => $peer['user_id'],
            ], $peerId);
            
            unset($this->rooms[$roomId]['peers'][$peerId]);
        }
        
        unset($this->peerStates[$peerId]);
        unset($this->socketToPeerId[$peerId]);
        
        if (isset($this->rooms[$roomId]) && empty($this->rooms[$roomId]['peers'])) {
            unset($this->rooms[$roomId]);
        }
    }
    
    public function handlePing(int $peerId): void
    {
        if (!isset($this->peerStates[$peerId])) return;
        
        $roomId = $this->peerStates[$peerId]['room_id'];
        if (isset($this->rooms[$roomId]['peers'][$peerId])) {
            $this->rooms[$roomId]['peers'][$peerId]['last_seen'] = time();
        }
    }
    
    public function getRoomInfo(string $roomId): array
    {
        if (!isset($this->rooms[$roomId])) {
            return ['error' => 'Room not found'];
        }
        
        $peers = [];
        foreach ($this->rooms[$roomId]['peers'] as $peerId => $peer) {
            $peers[] = [
                'peer_id' => $peerId,
                'user_id' => $peer['user_id'],
                'neighbors' => count($peer['neighbors']),
            ];
        }
        
        return [
            'room_id' => $roomId,
            'peer_count' => count($peers),
            'peers' => $peers,
        ];
    }
    
    private function broadcastToRoom(string $roomId, array $message, ?int $excludePeerId = null): void
    {
        if (!isset($this->rooms[$roomId])) return;
        
        foreach ($this->rooms[$roomId]['peers'] as $peerId => $peer) {
            if ($peerId !== $excludePeerId) {
                $this->send($peer['websocket'] ?? null, $message);
            }
        }
    }
    
    private function send($websocket, array $message): void
    {
        if ($websocket && function_exists('king_websocket_send')) {
            king_websocket_send($websocket, json_encode($message), true);
        }
    }
    
    public function cleanup(): int
    {
        $cleaned = 0;
        $now = time();
        
        foreach ($this->rooms as $roomId => &$room) {
            foreach ($room['peers'] as $peerId => &$peer) {
                if ($now - $peer['last_seen'] > self::PEER_TIMEOUT) {
                    // Mark for cleanup (would need websocket reference)
                    $cleaned++;
                }
            }
        }
        
        return $cleaned;
    }
}