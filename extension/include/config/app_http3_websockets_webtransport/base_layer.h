/*
 * =========================================================================
 * FILENAME:   include/config/app_http3_websockets_webtransport/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for the HTTP/3, WebSocket, and
 * WebTransport module.
 *
 * ARCHITECTURE:
 * This struct stores the runtime settings for the application protocol layer.
 * =========================================================================
 */
#ifndef KING_CONFIG_APP_PROTOCOLS_BASE_H
#define KING_CONFIG_APP_PROTOCOLS_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_app_protocols_config_t {
    /* --- HTTP/3 General Settings --- */
    bool http_advertise_h3_alt_svc;
    char *http_auto_compress;
    zend_long h3_max_header_list_size;
    zend_long h3_qpack_max_table_capacity;
    zend_long h3_qpack_blocked_streams;
    bool h3_server_push_enable;
    bool http_enable_early_hints;

    /* --- WebSocket Protocol Settings --- */
    zend_long websocket_default_max_payload_size;
    zend_long websocket_default_max_queued_messages;
    zend_long websocket_default_max_queued_bytes;
    zend_long websocket_default_ping_interval_ms;
    zend_long websocket_handshake_timeout_ms;

    /* --- WebTransport Protocol Settings --- */
    bool webtransport_enable;
    zend_long webtransport_max_concurrent_sessions;
    zend_long webtransport_max_streams_per_session;

} kg_app_protocols_config_t;

/* Module-global configuration instance. */
extern kg_app_protocols_config_t king_app_protocols_config;

#endif /* KING_CONFIG_APP_PROTOCOLS_BASE_H */
