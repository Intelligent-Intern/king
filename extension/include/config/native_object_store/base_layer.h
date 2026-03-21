/*
 * =========================================================================
 * FILENAME:   include/config/native_object_store/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for the native object store module.
 *
 * ARCHITECTURE:
 * This struct stores the object-store placement, discovery, and cache
 * settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_NATIVE_OBJECT_STORE_BASE_H
#define KING_CONFIG_NATIVE_OBJECT_STORE_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_native_object_store_config_t {
    /* --- General & API --- */
    bool enable;
    bool s3_api_compat_enable;
    bool versioning_enable;
    bool allow_anonymous_access;

    /* --- Data Placement & Redundancy --- */
    char *default_redundancy_mode;
    char *erasure_coding_shards;
    zend_long default_replication_factor;
    zend_long default_chunk_size_mb;

    /* --- Cluster Topology & Discovery --- */
    char *metadata_agent_uri;
    char *node_discovery_mode;
    char *node_static_list;

    /* --- Performance & Caching --- */
    bool metadata_cache_enable;
    zend_long metadata_cache_ttl_sec;
    bool enable_directstorage;

} kg_native_object_store_config_t;

/* Module-global configuration instance. */
extern kg_native_object_store_config_t king_native_object_store_config;

#endif /* KING_CONFIG_NATIVE_OBJECT_STORE_BASE_H */
