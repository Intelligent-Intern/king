/*
 * =========================================================================
 * FILENAME:   src/semantic_dns/state.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Implements the native Semantic-DNS durable state persistence,
 * allowing the control plane to recover topology and runtime data
 * across reboots or process limits.
 * =========================================================================
 */

#include "semantic_dns_internal.h"
#include <stdio.h>
#include <sys/stat.h>
#include <unistd.h>
#include <string.h>

#define KING_SEMANTIC_DNS_STATE_FILE "/tmp/king_semantic_dns_durable_state.bin"
#define KING_SEMANTIC_DNS_STATE_MAGIC 0x53444e53 /* 'SDNS' */
#define KING_SEMANTIC_DNS_STATE_VERSION 1

int king_semantic_dns_state_save(void)
{
    FILE *fp;
    uint32_t magic = KING_SEMANTIC_DNS_STATE_MAGIC;
    uint32_t version = KING_SEMANTIC_DNS_STATE_VERSION;

    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    fp = fopen(KING_SEMANTIC_DNS_STATE_FILE, "wb");
    if (fp == NULL) {
        return FAILURE;
    }

    fwrite(&magic, sizeof(magic), 1, fp);
    fwrite(&version, sizeof(version), 1, fp);

    /* Write the native mother_nodes list */
    fwrite(&king_semantic_dns_runtime.config.mother_node_count, sizeof(uint32_t), 1, fp);
    if (king_semantic_dns_runtime.config.mother_node_count > 0 && king_semantic_dns_runtime.config.mother_nodes != NULL) {
        fwrite(king_semantic_dns_runtime.config.mother_nodes, sizeof(king_mother_node_t), king_semantic_dns_runtime.config.mother_node_count, fp);
    }
    
    // Write scalar runtime states to persist basic scoring and discovery topology metadata
    fwrite(&king_semantic_dns_runtime.last_discovered_node_count, sizeof(zend_long), 1, fp);
    fwrite(&king_semantic_dns_runtime.last_synced_node_count, sizeof(zend_long), 1, fp);

    fclose(fp);
    return SUCCESS;
}

int king_semantic_dns_state_load(void)
{
    FILE *fp;
    uint32_t magic, version, node_count;
    
    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    fp = fopen(KING_SEMANTIC_DNS_STATE_FILE, "rb");
    if (fp == NULL) {
        return FAILURE; /* expected on first run */
    }

    if (fread(&magic, sizeof(magic), 1, fp) != 1 || magic != KING_SEMANTIC_DNS_STATE_MAGIC) {
        fclose(fp);
        return FAILURE;
    }

    if (fread(&version, sizeof(version), 1, fp) != 1 || version != KING_SEMANTIC_DNS_STATE_VERSION) {
        fclose(fp);
        return FAILURE;
    }

    /* Restore mother nodes topology */
    if (fread(&node_count, sizeof(uint32_t), 1, fp) == 1) {
        if (node_count > 0) {
            if (king_semantic_dns_runtime.config.mother_nodes != NULL) {
                pefree(king_semantic_dns_runtime.config.mother_nodes, 1);
            }
            king_semantic_dns_runtime.config.mother_nodes = pecalloc(node_count, sizeof(king_mother_node_t), 1);
            if (fread(king_semantic_dns_runtime.config.mother_nodes, sizeof(king_mother_node_t), node_count, fp) == node_count) {
                king_semantic_dns_runtime.config.mother_node_count = node_count;
            } else {
                king_semantic_dns_runtime.config.mother_node_count = 0;
            }
        }
    }
    
    /* Restore other relevant scalar fields */
    if(fread(&king_semantic_dns_runtime.last_discovered_node_count, sizeof(zend_long), 1, fp) != 1) return FAILURE;
    if(fread(&king_semantic_dns_runtime.last_synced_node_count, sizeof(zend_long), 1, fp) != 1) return FAILURE;

    fclose(fp);
    return SUCCESS;
}
