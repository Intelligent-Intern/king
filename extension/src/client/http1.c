/*
 * =========================================================================
 * FILENAME:   src/client/http1.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Minimal live HTTP/1 client runtime for the active runtime. This
 * implementation intentionally stays dependency-free and supports plain
 * `http://` requests over native TCP sockets with a bounded response parser.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/early_hints.h"
#include "include/client/http1.h"
#include "include/config/config.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/telemetry/telemetry.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/url.h"

#include <zend_exceptions.h>
#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <netdb.h>
#include <netinet/tcp.h>
#include <poll.h>
#include <stdarg.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#ifndef MSG_NOSIGNAL
#  define MSG_NOSIGNAL 0
#endif

#define KING_HTTP1_DEFAULT_TIMEOUT_MS 15000L
#define KING_HTTP1_MAX_RESPONSE_BYTES (8 * 1024 * 1024)
#define KING_HTTP1_MAX_HEADER_BYTES   (64 * 1024)
#define KING_HTTP1_MAX_PENDING_EARLY_HINTS 256
#define KING_HTTP1_MAX_PENDING_EARLY_HINT_BYTES KING_HTTP1_MAX_HEADER_BYTES
#define KING_HTTP1_MAX_IDLE_CONNECTIONS 16

typedef struct _king_http1_request_options {
    king_cfg_t *config;
    zend_long connect_timeout_ms;
    zend_long timeout_ms;
    zend_long max_redirects;
    bool tcp_enable;
    bool tcp_keepalive_enable;
    bool tcp_nodelay_enable;
    bool follow_redirects;
    bool response_stream;
    zval *cancel_token;
    const char *cancel_function_name;
    zend_class_entry *cancel_exception_ce;
} king_http1_request_options_t;

typedef struct _king_http1_response_meta {
    zval headers;
    zend_string *status_line;
    zend_long status_code;
    size_t content_length;
    bool headers_initialized;
    bool has_content_length;
    bool chunked_transfer_encoding;
    bool connection_close;
    bool connection_keep_alive;
    bool persistent_by_default;
} king_http1_response_meta_t;

typedef struct _king_http1_pool_entry {
    char *origin;
    int fd;
    struct _king_http1_pool_entry *next;
} king_http1_pool_entry_t;

struct _king_http1_request_context {
    int fd;
    char *origin;
    smart_str header_buffer;
    smart_str chunk_buffer;
    smart_str body_buffer;
    zval pending_early_hints;
    zend_string *effective_url;
    king_http1_response_meta_t meta;
    zend_long timeout_ms;
    size_t chunk_parse_offset;
    size_t total_received_bytes;
    size_t pending_early_hints_count;
    size_t pending_early_hints_bytes;
    bool headers_parsed;
    bool response_complete;
    bool request_allows_keep_alive;
    bool expect_no_body;
    bool chunk_final_chunk_seen;
    bool response_taken;
};

static king_http1_pool_entry_t *king_http1_pool = NULL;
static size_t king_http1_pool_size = 0;

static void king_http1_response_meta_destroy(king_http1_response_meta_t *meta)
{
    if (meta->headers_initialized) {
        zval_ptr_dtor(&meta->headers);
    }

    if (meta->status_line != NULL) {
        zend_string_release(meta->status_line);
    }

    memset(meta, 0, sizeof(*meta));
}

#include "http1/common.inc"
#include "http1/transport.inc"
#include "http1/response.inc"
#include "http1/request.inc"
