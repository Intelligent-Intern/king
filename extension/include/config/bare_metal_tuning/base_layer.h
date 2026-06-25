/*
 * =========================================================================
 * FILENAME:   include/config/bare_metal_tuning/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for the bare-metal tuning module.
 *
 * ARCHITECTURE:
 * This struct stores low-level I/O, socket, CPU, and NUMA settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_BARE_METAL_BASE_H
#define KING_CONFIG_BARE_METAL_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_bare_metal_config_t {
    /* --- Low-Level I/O Engine --- */
    bool io_engine_use_uring;
    zend_long io_uring_sq_poll_ms;
    zend_long io_max_batch_read_packets;
    zend_long io_max_batch_write_packets;

    /* --- Socket Buffers & Options --- */
    zend_long socket_receive_buffer_size;
    zend_long socket_send_buffer_size;
    zend_long socket_enable_busy_poll_us;
    bool socket_enable_timestamping;

    /* --- CPU & NUMA Affinity --- */
    char *io_thread_cpu_affinity;
    char *io_thread_numa_node_policy;

} kg_bare_metal_config_t;

/* Module-global configuration instance. */
extern kg_bare_metal_config_t king_bare_metal_config;

#endif /* KING_CONFIG_BARE_METAL_BASE_H */
