/*
 * =========================================================================
 * FILENAME:   src/semantic_dns/mother_node_discovery.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Implements the native Semantic-DNS mother node discovery and
 * topology maintenance loops.
 * =========================================================================
 */

#include "semantic_dns/semantic_dns_internal.h"
#include <stdlib.h>
#include <string.h>

int king_semantic_dns_discover_mother_nodes(void)
{
    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (!king_semantic_dns_runtime.config.semantic_mode_enable) {
        king_semantic_dns_runtime.last_discovered_node_count = 0;
        return SUCCESS;
    }

    if (king_semantic_dns_refresh_runtime_mother_nodes_from_registry() == SUCCESS
        && king_semantic_dns_runtime.config.mother_node_count > 0) {
        king_semantic_dns_runtime.last_discovered_node_count =
            king_semantic_dns_runtime.config.mother_node_count;
        return SUCCESS;
    }

    king_semantic_dns_runtime.last_discovered_node_count =
        (king_semantic_dns_runtime.config.mothernode_uri[0] != '\0') ? 1 : 0;

    /* Seed a single configured mother node only when no registry-backed topology exists yet. */
    if (king_semantic_dns_runtime.last_discovered_node_count > 0
        && king_semantic_dns_runtime.config.mother_node_count == 0) {
        king_semantic_dns_runtime.config.mother_nodes = pecalloc(1, sizeof(king_mother_node_t), 1);
        if (king_semantic_dns_runtime.config.mother_nodes == NULL) {
            king_semantic_dns_runtime.last_discovered_node_count = 0;
            return FAILURE;
        }
        king_semantic_dns_runtime.config.mother_node_count = 1;

        strncpy(
            king_semantic_dns_runtime.config.mother_nodes[0].node_id,
            king_semantic_dns_runtime.config.mothernode_uri,
            sizeof(king_semantic_dns_runtime.config.mother_nodes[0].node_id) - 1
        );
        king_semantic_dns_runtime.config.mother_nodes[0].node_id[
            sizeof(king_semantic_dns_runtime.config.mother_nodes[0].node_id) - 1
        ] = '\0';
        king_semantic_dns_runtime.config.mother_nodes[0].status = KING_SERVICE_STATUS_HEALTHY;
        king_semantic_dns_runtime.config.mother_nodes[0].trust_score = 1.0;
        king_semantic_dns_runtime.config.mother_nodes[0].last_heartbeat = time(NULL);
    }

    return SUCCESS;
}

int king_semantic_dns_sync_with_mother_nodes(void)
{
    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (king_semantic_dns_runtime.config.semantic_mode_enable) {
        if (king_semantic_dns_refresh_runtime_mother_nodes_from_registry() == SUCCESS
            && king_semantic_dns_runtime.config.mother_node_count > 0) {
            king_semantic_dns_runtime.last_discovered_node_count =
                king_semantic_dns_runtime.config.mother_node_count;
        }
    }

    king_semantic_dns_runtime.last_synced_node_count =
        king_semantic_dns_runtime.config.mother_node_count > 0
            ? king_semantic_dns_runtime.config.mother_node_count
            : king_semantic_dns_runtime.last_discovered_node_count;

    if (king_semantic_dns_runtime.config.semantic_mode_enable && king_semantic_dns_runtime.config.mother_node_count > 0) {
        for (uint32_t i = 0; i < king_semantic_dns_runtime.config.mother_node_count; i++) {
            king_semantic_dns_runtime.config.mother_nodes[i].last_heartbeat = time(NULL);
        }
    }
    
    return SUCCESS;
}
