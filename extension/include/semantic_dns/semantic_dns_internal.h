/*
 * =========================================================================
 * FILENAME:   include/semantic_dns/semantic_dns_internal.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Internal runtime state plus persisted topology/recovery helpers for the
 * Semantic-DNS subsystem.
 * =========================================================================
 */

#ifndef KING_SEMANTIC_DNS_INTERNAL_H
#define KING_SEMANTIC_DNS_INTERNAL_H

#include "php_king.h"
#include "config/smart_dns/base_layer.h"
#include "semantic_dns/semantic_dns.h"
#include <stdbool.h>
#include <limits.h>
#include <sys/types.h>
#include <time.h>

typedef struct _king_semantic_dns_runtime_state {
    bool initialized;
    bool server_active;
    time_t initialized_at;
    time_t server_started_at;
    zend_long start_count;                /* Local server activations. */
    zend_long processed_query_count;      /* Successful bounded local queries. */
    zend_long last_discovered_node_count; /* Last discovery refresh count. */
    zend_long last_synced_node_count;     /* Last mother-node sync count. */
    pid_t listener_pid;                   /* Live UDP listener child PID. */
    char listener_state_path[PATH_MAX];   /* Parent-written snapshot for the listener child. */
    king_semantic_dns_config_t config;
} king_semantic_dns_runtime_state;

extern king_semantic_dns_runtime_state king_semantic_dns_runtime;
extern bool king_semantic_dns_registry_initialized;

/* Persisted state snapshot helpers */
int king_semantic_dns_state_load(void);
int king_semantic_dns_state_save(void);
int king_semantic_dns_state_has_regular_snapshot(void);
int king_semantic_dns_state_transaction_begin(int *lock_fd_out);
void king_semantic_dns_state_transaction_end(int lock_fd);
int king_semantic_dns_state_persist_locked(void);
int king_semantic_dns_export_state_payload(zval *return_value);
int king_semantic_dns_import_state_payload(zval *payload);
int king_semantic_dns_merge_missing_state_payload(zval *payload);
int king_semantic_dns_state_write_snapshot_file(const char *path, zval *payload);
int king_semantic_dns_state_read_snapshot_file(const char *path, zval *payload);
int king_semantic_dns_state_remove_snapshot_file(const char *path);
int king_semantic_dns_listener_write_runtime_snapshot(void);

/* Registry/runtime refresh helpers */
int king_semantic_dns_refresh_runtime_mother_nodes_from_registry(void);
void king_semantic_dns_refresh_live_service_signals(void);

#endif /* KING_SEMANTIC_DNS_INTERNAL_H */
