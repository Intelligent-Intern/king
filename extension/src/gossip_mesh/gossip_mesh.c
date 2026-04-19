/*
 * GossipMesh Implementation - Decentralized SFU forwarding logic
 */

#include "gossip_mesh.h"
#include <stdlib.h>
#include <string.h>
#include <time.h>

static inline uint64_t gossip_hash(uint64_t a, uint64_t b)
{
    return a ^ (b * 0x9e3779b97f4a7c15ULL);
}

static bool gossip_seen_contains(gossip_mesh_t *mesh, uint64_t publisher_id, 
                                  uint32_t sequence)
{
    for (uint16_t i = 0; i < mesh->seen_count; i++) {
        uint16_t idx = (mesh->seen_head + i) % GOSSIP_MESH_SEEN_WINDOW;
        if (mesh->seen_window[idx].peer_id == publisher_id &&
            mesh->seen_window[idx].timestamp == sequence) {
            return true;
        }
    }
    return false;
}

static void gossip_seen_add(gossip_mesh_t *mesh, uint64_t publisher_id,
                            uint32_t sequence)
{
    uint16_t idx = mesh->seen_head;
    mesh->seen_window[idx].peer_id = publisher_id;
    mesh->seen_window[idx].timestamp = sequence;
    mesh->seen_head = (mesh->seen_head + 1) % GOSSIP_MESH_SEEN_WINDOW;
    if (mesh->seen_count < GOSSIP_MESH_SEEN_WINDOW) {
        mesh->seen_count++;
    }
}

gossip_mesh_t *gossip_mesh_create(void)
{
    gossip_mesh_t *mesh = calloc(1, sizeof(gossip_mesh_t));
    if (mesh == NULL) {
        return NULL;
    }
    
    mesh->neighbor_count = 0;
    mesh->seen_head = 0;
    mesh->seen_count = 0;
    mesh->pending_count = 0;
    
    return mesh;
}

void gossip_mesh_destroy(gossip_mesh_t *mesh)
{
    if (mesh != NULL) {
        free(mesh);
    }
}

void gossip_mesh_set_callbacks(
    gossip_mesh_t *mesh,
    void (*on_frame_received)(uint64_t, uint32_t, const uint8_t *, size_t, void *),
    void (*on_peer_connected)(uint64_t, void *),
    void (*on_peer_disconnected)(uint64_t, void *),
    void *user_data)
{
    mesh->on_frame_received = on_frame_received;
    mesh->on_peer_connected = on_peer_connected;
    mesh->on_peer_disconnected = on_peer_disconnected;
    mesh->user_data = user_data;
}

int gossip_mesh_add_neighbor(gossip_mesh_t *mesh, uint64_t peer_id,
                              const char *ip, uint16_t port)
{
    if (mesh == NULL || mesh->neighbor_count >= GOSSIP_MESH_MAX_NEIGHBORS) {
        return -1;
    }
    
    /* Check if already exists */
    for (uint8_t i = 0; i < mesh->neighbor_count; i++) {
        if (mesh->neighbors[i].peer_id == peer_id) {
            return -2; /* Already exists */
        }
    }
    
    gossip_peer_t *peer = &mesh->neighbors[mesh->neighbor_count++];
    peer->peer_id = peer_id;
    peer->port = port;
    peer->state = GOSSIP_PEER_STATE_CONNECTING;
    peer->bandwidth_score = 2; /* Default fair */
    peer->last_seen = time(NULL);
    peer->packets_received = 0;
    peer->packets_forwarded = 0;
    
    if (ip != NULL) {
        strncpy(peer->ip, ip, sizeof(peer->ip) - 1);
    }
    
    if (mesh->on_peer_connected != NULL) {
        mesh->on_peer_connected(peer_id, mesh->user_data);
    }
    
    return 0;
}

int gossip_mesh_remove_neighbor(gossip_mesh_t *mesh, uint64_t peer_id)
{
    if (mesh == NULL) {
        return -1;
    }
    
    for (uint8_t i = 0; i < mesh->neighbor_count; i++) {
        if (mesh->neighbors[i].peer_id == peer_id) {
            /* Notify callback before removing */
            if (mesh->on_peer_disconnected != NULL) {
                mesh->on_peer_disconnected(peer_id, mesh->user_data);
            }
            
            /* Shift remaining neighbors */
            for (uint8_t j = i; j < mesh->neighbor_count - 1; j++) {
                mesh->neighbors[j] = mesh->neighbors[j + 1];
            }
            mesh->neighbor_count--;
            return 0;
        }
    }
    
    return -2; /* Not found */
}

gossip_peer_t *gossip_mesh_get_neighbor(gossip_mesh_t *mesh, uint64_t peer_id)
{
    if (mesh == NULL) {
        return NULL;
    }
    
    for (uint8_t i = 0; i < mesh->neighbor_count; i++) {
        if (mesh->neighbors[i].peer_id == peer_id) {
            return &mesh->neighbors[i];
        }
    }
    return NULL;
}

gossip_peer_t *gossip_mesh_get_neighbors(gossip_mesh_t *mesh, uint8_t *count)
{
    if (mesh == NULL || count == NULL) {
        return NULL;
    }
    *count = mesh->neighbor_count;
    return mesh->neighbors;
}

int gossip_mesh_receive_frame(gossip_mesh_t *mesh, uint64_t publisher_id,
                               uint32_t sequence, uint8_t ttl,
                               const uint8_t *data, size_t len)
{
    if (mesh == NULL || data == NULL || len == 0) {
        return -1;
    }
    
    /* Check for duplicates */
    if (gossip_seen_contains(mesh, publisher_id, sequence)) {
        mesh->total_duplicates_dropped++;
        return -2; /* Duplicate */
    }
    
    /* Add to seen window */
    gossip_seen_add(mesh, publisher_id, sequence);
    
    /* Update stats */
    mesh->total_frames_received++;
    
    /* Notify callback */
    if (mesh->on_frame_received != NULL) {
        mesh->on_frame_received(publisher_id, sequence, data, len, mesh->user_data);
    }
    
    /* If TTL > 0, we should forward */
    /* The caller (or forwarding logic) will handle actual forward */
    return ttl > 0 ? 1 : 0; /* Return 1 if should forward */
}

int gossip_mesh_compute_forwards(gossip_mesh_t *mesh, uint64_t frame_id,
                                  uint64_t *out_peers, uint8_t *out_count)
{
    if (mesh == NULL || out_peers == NULL || out_count == NULL) {
        return -1;
    }
    
    *out_count = 0;
    
    /* Simple random forward to GOSSIP_MESH_FORWARD_COUNT peers */
    if (mesh->neighbor_count == 0) {
        return 0;
    }
    
    /* Use frame_id as seed for deterministic randomness */
    srand((unsigned int)(frame_id ^ 0x12345678));
    
    /* Shuffle neighbors (Fisher-Yates partial) */
    uint8_t count = mesh->neighbor_count < GOSSIP_MESH_FORWARD_COUNT 
        ? mesh->neighbor_count 
        : GOSSIP_MESH_FORWARD_COUNT;
    
    for (uint8_t i = 0; i < count; i++) {
        uint8_t j = i + rand() % (mesh->neighbor_count - i);
        
        /* Swap */
        gossip_peer_t tmp = mesh->neighbors[i];
        mesh->neighbors[i] = mesh->neighbors[j];
        mesh->neighbors[j] = tmp;
        
        out_peers[*out_count] = mesh->neighbors[i].peer_id;
        (*out_count)++;
    }
    
    /* Reset random for other uses */
    srand((unsigned int)time(NULL));
    
    return 0;
}

void gossip_mesh_mark_forwarded(gossip_mesh_t *mesh, uint64_t frame_id, 
                                 uint64_t peer_id)
{
    if (mesh == NULL) {
        return;
    }
    
    /* Update peer stats */
    gossip_peer_t *peer = gossip_mesh_get_neighbor(mesh, peer_id);
    if (peer != NULL) {
        peer->packets_forwarded++;
        mesh->total_frames_forwarded++;
    }
}

void gossip_mesh_get_stats(gossip_mesh_t *mesh, 
                           uint64_t *frames_received,
                           uint64_t *frames_forwarded,
                           uint64_t *duplicates_dropped)
{
    if (mesh == NULL) {
        return;
    }
    
    if (frames_received != NULL) {
        *frames_received = mesh->total_frames_received;
    }
    if (frames_forwarded != NULL) {
        *frames_forwarded = mesh->total_frames_forwarded;
    }
    if (duplicates_dropped != NULL) {
        *duplicates_dropped = mesh->total_duplicates_dropped;
    }
}

bool gossip_mesh_has_peer(gossip_mesh_t *mesh, uint64_t peer_id)
{
    return gossip_mesh_get_neighbor(mesh, peer_id) != NULL;
}

uint8_t gossip_mesh_estimate_ttl(size_t peer_count)
{
    /* TTL = ceil(log_base(forward_count)(peer_count)) + 1 safety margin */
    if (peer_count <= 10) return 3;
    if (peer_count <= 50) return 4;
    if (peer_count <= 100) return 5;
    if (peer_count <= 500) return 6;
    return 7;
}