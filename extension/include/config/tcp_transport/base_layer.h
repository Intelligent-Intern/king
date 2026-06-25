/*
 * =========================================================================
 * FILENAME:   include/config/tcp_transport/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for TCP transport.
 *
 * ARCHITECTURE:
 * This struct stores the TCP connection, keep-alive, and TLS-over-TCP
 * settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_TCP_TRANSPORT_BASE_H
#define KING_CONFIG_TCP_TRANSPORT_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_tcp_transport_config_t {
    /* --- Connection Management --- */
    bool enable;
    zend_long max_connections;
    zend_long connect_timeout_ms;
    zend_long listen_backlog;
    bool reuse_port_enable;

    /* --- Latency & Throughput (Nagle's Algorithm) --- */
    bool nodelay_enable;
    bool cork_enable;

    /* --- Keep-Alive Settings --- */
    bool keepalive_enable;
    zend_long keepalive_time_sec;
    zend_long keepalive_interval_sec;
    zend_long keepalive_probes;

    /* --- TLS over TCP Settings --- */
    char *tls_min_version_allowed;
    char *tls_ciphers_tls12;

} kg_tcp_transport_config_t;

/* Module-global configuration instance. */
extern kg_tcp_transport_config_t king_tcp_transport_config;

#endif /* KING_CONFIG_TCP_TRANSPORT_BASE_H */
