/*
 * GossipMesh - Decentralized SFU using gossip protocol on expander graph
 * 
 * Architecture:
 * - Each peer connects to 3-5 neighbors (random expander)
 * - Frames propagate via stochastic forwarding
 * - TTL ensures bounded propagation, duplicates filtered
 */

#ifndef GOSSIP_MESH_H
#define GOSSIP_MESH_H

#include <stdint.h>
#include <stdbool.h>
#include <stddef.h>

#define GOSSIP_MESH_MAX_NEIGHBORS 8
#define GOSSIP_MESH_DEFAULT_NEIGHBORS 4
#define GOSSIP_MESH_DEFAULT_TTL 4
#define GOSSIP_MESH_FORWARD_COUNT 2
#define GOSSIP_MESH_SEEN_WINDOW 256

typedef struct {
    uint64_t publisher_id;
    uint32_t sequence;
} gossip_frame_id_t;

typedef enum {
    GOSSIP_PEER_STATE_CONNECTING,
    GOSSIP_PEER_STATE_CONNECTED,
    GOSSIP_PEER_STATE_DISCONNECTED
} gossip_peer_state_t;

typedef struct {
    uint64_t peer_id;
    char ip[64];
    uint16_t port;
    gossip_peer_state_t state;
    uint8_t bandwidth_score; /* 0=unknown, 1=limited, 2=fair, 3=good */
    uint64_t last_seen;
    uint64_t packets_received;
    uint64_t packets_forwarded;
} gossip_peer_t;

typedef struct {
    uint64_t peer_id;
    uint64_t timestamp;
} gossip_seen_entry_t;

typedef struct {
    uint64_t peer_id;
    uint8_t ttl;
    uint8_t forward_count;
    uint64_t timestamp;
} gossip_forward_record_t;

typedef struct {
    uint64_t peer_id;
    gossip_peer_t neighbors[GOSSIP_MESH_MAX_NEIGHBORS];
    uint8_t neighbor_count;
    
    /* Seen frames window (circular buffer) */
    gossip_seen_entry_t seen_window[GOSSIP_MESH_SEEN_WINDOW];
    uint16_t seen_head;
    uint16_t seen_count;
    
    /* Pending forwards per frame */
    gossip_forward_record_t pending_forwards[32];
    uint8_t pending_count;
    
    /* Callbacks */
    void (*on_frame_received)(uint64_t publisher_id, uint32_t sequence, 
                              const uint8_t *data, size_t len, void *user_data);
    void (*on_peer_connected)(uint64_t peer_id, void *user_data);
    void (*on_peer_disconnected)(uint64_t peer_id, void *user_data);
    void *user_data;
    
    /* Stats */
    uint64_t total_frames_received;
    uint64_t total_frames_forwarded;
    uint64_t total_duplicates_dropped;
} gossip_mesh_t;

/* Initialize mesh context */
gossip_mesh_t *gossip_mesh_create(void);

void gossip_mesh_destroy(gossip_mesh_t *mesh);

/* Configuration */
void gossip_mesh_set_callbacks(
    gossip_mesh_t *mesh,
    void (*on_frame_received)(uint64_t, uint32_t, const uint8_t *, size_t, void *),
    void (*on_peer_connected)(uint64_t, void *),
    void (*on_peer_disconnected)(uint64_t, void *),
    void *user_data
);

/* Neighbor management */
int gossip_mesh_add_neighbor(gossip_mesh_t *mesh, uint64_t peer_id, 
                              const char *ip, uint16_t port);

int gossip_mesh_remove_neighbor(gossip_mesh_t *mesh, uint64_t peer_id);

gossip_peer_t *gossip_mesh_get_neighbor(gossip_mesh_t *mesh, uint64_t peer_id);

gossip_peer_t *gossip_mesh_get_neighbors(gossip_mesh_t *mesh, uint8_t *count);

/* Frame processing */
int gossip_mesh_receive_frame(gossip_mesh_t *mesh, uint64_t publisher_id,
                               uint32_t sequence, uint8_t ttl,
                               const uint8_t *data, size_t len);

/* Forward decision - returns list of peers to forward to */
int gossip_mesh_compute_forwards(gossip_mesh_t *mesh, uint64_t frame_id,
                                  uint64_t *out_peers, uint8_t *out_count);

/* Mark frame as forwarded to a specific peer */
void gossip_mesh_mark_forwarded(gossip_mesh_t *mesh, uint64_t frame_id, 
                                 uint64_t peer_id);

/* Stats */
void gossip_mesh_get_stats(gossip_mesh_t *mesh, 
                           uint64_t *frames_received,
                           uint64_t *frames_forwarded,
                           uint64_t *duplicates_dropped);

/* Utility */
bool gossip_mesh_has_peer(gossip_mesh_t *mesh, uint64_t peer_id);

uint8_t gossip_mesh_estimate_ttl(size_t peer_count);

#endif /* GOSSIP_MESH_H */