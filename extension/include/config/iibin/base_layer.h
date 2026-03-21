/*
 * =========================================================================
 * FILENAME:   include/config/iibin/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for IIBIN serialization.
 *
 * ARCHITECTURE:
 * This struct stores serialization limits and the shared-memory I/O path.
 * =========================================================================
 */
#ifndef KING_CONFIG_IIBIN_BASE_H
#define KING_CONFIG_IIBIN_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_iibin_config_t {
    /* --- IIBIN Serialization Engine --- */
    zend_long max_schema_fields;
    zend_long max_recursion_depth;
    bool string_interning_enable;

    /* --- Zero-Copy I/O via Shared Memory Buffers --- */
    bool use_shared_memory_buffers;
    zend_long default_buffer_size_kb;
    zend_long shm_total_memory_mb;
    char *shm_path;

} kg_iibin_config_t;

/* Module-global configuration instance. */
extern kg_iibin_config_t king_iibin_config;

#endif /* KING_CONFIG_IIBIN_BASE_H */
