/*
 * =========================================================================
 * FILENAME:   src/semantic_dns/semantic_dns_internal.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Internal header for the Semantic-DNS native runtime.
 * =========================================================================
 */

#ifndef KING_SEMANTIC_DNS_INTERNAL_H
#define KING_SEMANTIC_DNS_INTERNAL_H

#include "php_king.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/semantic_dns/semantic_dns.h"
#include <stdbool.h>
#include <time.h>

typedef struct _king_semantic_dns_runtime_state {
    bool initialized;
    bool server_active;
    time_t initialized_at;
    time_t server_started_at;
    zend_long start_count;
    zend_long processed_query_count;
    zend_long last_discovered_node_count;
    zend_long last_synced_node_count;
    king_semantic_dns_config_t config;
} king_semantic_dns_runtime_state;

extern king_semantic_dns_runtime_state king_semantic_dns_runtime;

int king_semantic_dns_state_load(void);
int king_semantic_dns_state_save(void);

#endif /* KING_SEMANTIC_DNS_INTERNAL_H */
