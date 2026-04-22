<?php

/**
 * GossipMesh - Decentralized SFU using gossip protocol on expander graph
 * 
 * This class implements the gossip forwarding logic in PHP.
 * For production, this should be moved to C for performance.
 * 
 * Protocol:
 * - Each peer connects to 3-5 neighbors (random expander)
 * - Frames propagate via stochastic forwarding (random subset of 2 neighbors)
 * - TTL ensures bounded propagation, duplicates filtered via seen window
 * - Multiple frames propagate simultaneously - each node plays in arrival order
 */

class GossipMesh
{
    const MAX_NEIGHBORS = 8;
    const DEFAULT_NEIGHBORS = 4;
    const DEFAULT_TTL = 4;
    const FORWARD_COUNT = 2;
    const SEEN_WINDOW_SIZE = 256;
    
    private array $neighbors = [];
    private array $seenFrames = [];
    private int $seenIndex = 0;
    private int $seenCount = 0;
    
    private int $framesReceived = 0;
    private int $framesForwarded = 0;
    private int $duplicatesDropped = 0;
    
    private $onFrameReceived = null;
    private $onPeerConnected = null;
    private $onPeerDisconnected = null;
    
    public function __construct(
        ?callable $onFrameReceived = null,
        ?callable $onPeerConnected = null,
        ?callable $onPeerDisconnected = null
    ) {
        $this->onFrameReceived = $onFrameReceived;
        $this->onPeerConnected = $onPeerConnected;
        $this->onPeerDisconnected = $onPeerDisconnected;
    }
    
    public function addNeighbor(int $peerId, ?string $ip = null, int $port = 0): bool
    {
        if (count($this->neighbors) >= self::MAX_NEIGHBORS) {
            return false;
        }
        
        if (isset($this->neighbors[$peerId])) {
            return false;
        }
        
        $this->neighbors[$peerId] = [
            'peer_id' => $peerId,
            'ip' => $ip ?? '',
            'port' => $port,
            'bandwidth_score' => 2, // fair
            'packets_received' => 0,
            'packets_forwarded' => 0,
            'connected' => true,
        ];
        
        if ($this->onPeerConnected) {
            ($this->onPeerConnected)($peerId);
        }
        
        return true;
    }
    
    public function removeNeighbor(int $peerId): bool
    {
        if (!isset($this->neighbors[$peerId])) {
            return false;
        }
        
        if ($this->onPeerDisconnected) {
            ($this->onPeerDisconnected)($peerId);
        }
        
        unset($this->neighbors[$peerId]);
        return true;
    }
    
    public function getNeighbors(): array
    {
        return array_values($this->neighbors);
    }
    
    public function getNeighborCount(): int
    {
        return count($this->neighbors);
    }
    
    private function frameSeen(int $publisherId, int $sequence): bool
    {
        $key = "{$publisherId}:{$sequence}";
        return isset($this->seenFrames[$key]);
    }
    
    private function markFrameSeen(int $publisherId, int $sequence): void
    {
        $key = "{$publisherId}:{$sequence}";
        $this->seenFrames[$key] = true;
        $this->seenCount++;
        
        // Prune old entries if window is full
        if (count($this->seenFrames) > self::SEEN_WINDOW_SIZE) {
            // Simple approach: rebuild array periodically
            // In production, use circular buffer
            $this->seenFrames = array_slice($this->seenFrames, -self::SEEN_WINDOW_SIZE, null, true);
        }
    }
    
    /**
     * Receive a frame from a peer
     * 
     * @return bool True if frame should be forwarded to neighbors
     */
    public function receiveFrame(
        int $publisherId,
        int $sequence,
        int $ttl,
        string $data
    ): bool {
        // Check for duplicates
        if ($this->frameSeen($publisherId, $sequence)) {
            $this->duplicatesDropped++;
            return false;
        }
        
        // Mark as seen
        $this->markFrameSeen($publisherId, $sequence);
        
        // Update stats
        $this->framesReceived++;
        
        // Update neighbor stats if we know the sender
        // (would need to track source peer)
        
        // Notify callback
        if ($this->onFrameReceived) {
            ($this->onFrameReceived)($publisherId, $sequence, $data);
        }
        
        // Return whether we should forward
        return $ttl > 0;
    }
    
    /**
     * Compute which neighbors to forward a frame to
     * 
     * @return array List of peer IDs to forward to
     */
    public function computeForwards(int $publisherId, int $sequence): array
    {
        $neighborIds = array_keys($this->neighbors);
        
        if (empty($neighborIds)) {
            return [];
        }
        
        // Shuffle and take first N (deterministic based on frame id)
        $seed = $publisherId ^ ($sequence << 16) ^ 0x12345678;
        mt_srand($seed);
        shuffle($neighborIds);
        
        $forwardCount = min(self::FORWARD_COUNT, count($neighborIds));
        $forwards = array_slice($neighborIds, 0, $forwardCount);
        
        // Update stats
        foreach ($forwards as $peerId) {
            if (isset($this->neighbors[$peerId])) {
                $this->neighbors[$peerId]['packets_forwarded']++;
            }
        }
        $this->framesForwarded += count($forwards);
        
        // Reset random
        mt_srand();
        
        return $forwards;
    }
    
    public function markForwarded(int $publisherId, int $sequence, int $peerId): void
    {
        if (isset($this->neighbors[$peerId])) {
            $this->neighbors[$peerId]['packets_forwarded']++;
        }
    }
    
    public function getStats(): array
    {
        return [
            'frames_received' => $this->framesReceived,
            'frames_forwarded' => $this->framesForwarded,
            'duplicates_dropped' => $this->duplicatesDropped,
            'neighbor_count' => count($this->neighbors),
        ];
    }
    
    public static function estimateTtl(int $peerCount): int
    {
        if ($peerCount <= 10) return 3;
        if ($peerCount <= 50) return 4;
        if ($peerCount <= 100) return 5;
        if ($peerCount <= 500) return 6;
        return 7;
    }
}