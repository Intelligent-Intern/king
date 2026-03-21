/*
 * =========================================================================
 * FILENAME:   include/config/http2/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for HTTP/2.
 *
 * ARCHITECTURE:
 * This struct stores the runtime HTTP/2 engine settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_HTTP2_BASE_H
#define KING_CONFIG_HTTP2_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_http2_config_t {
    /* --- HTTP/2 General Settings --- */
    bool enable;
    zend_long initial_window_size;
    zend_long max_concurrent_streams;
    zend_long max_header_list_size;
    bool enable_push;
    zend_long max_frame_size;

} kg_http2_config_t;

/* Module-global configuration instance. */
extern kg_http2_config_t king_http2_config;

#endif /* KING_CONFIG_HTTP2_BASE_H */
