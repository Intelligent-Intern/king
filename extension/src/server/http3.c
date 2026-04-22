/*
 * =========================================================================
 * FILENAME:   src/server/http3.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the original local HTTP/3 listener leaf and adds a narrow one-shot
 * on-wire HTTP/3 listener slice so v1 can verify a real QUIC/HTTP/3
 * server-side accept path without pretending the full long-lived listener
 * stack is complete yet.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/config/quic_transport/base_layer.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/config/tls_and_crypto/base_layer.h"
#include "include/server/http3.h"
#include "include/server/session.h"

#include "Zend/zend_smart_str.h"

#if defined(KING_HTTP3_BACKEND_LSQUIC)
#include <lsquic.h>
#include <lsxpack_header.h>
#include <openssl/ssl.h>
#endif

#include <arpa/inet.h>
#include <ctype.h>
#include <dlfcn.h>
#include <errno.h>
#include <fcntl.h>
#include <inttypes.h>
#include <netdb.h>
#include <poll.h>
#include <stdarg.h>
#include <stdbool.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "local_listener.inc"

#define KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS 15000L
#define KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE 1350
#define KING_SERVER_HTTP3_MAX_HEADER_BYTES  (128 * 1024)
#define KING_SERVER_HTTP3_MAX_REQUEST_BODY_BYTES (1024 * 1024)
#define KING_SERVER_HTTP3_CLOSE_GRACE_MS 200L

typedef struct _king_server_http3_options {
    king_cfg_t *owned_config;
    const char *tls_default_cert_file;
    const char *tls_default_key_file;
    const char *quic_cc_algorithm;
    zend_long timeout_ms;
    zend_long quic_cc_initial_cwnd_packets;
    zend_long quic_cc_min_cwnd_packets;
    bool quic_cc_enable_hystart_plus_plus;
    bool quic_pacing_enable;
    zend_long quic_pacing_max_burst_packets;
    zend_long quic_max_ack_delay_ms;
    zend_long quic_ack_delay_exponent;
    zend_long quic_pto_timeout_ms_initial;
    zend_long quic_pto_timeout_ms_max;
    zend_long quic_max_pto_probes;
    zend_long quic_ping_interval_ms;
    zend_long quic_initial_max_data;
    zend_long quic_initial_max_stream_data_bidi_local;
    zend_long quic_initial_max_stream_data_bidi_remote;
    zend_long quic_initial_max_stream_data_uni;
    zend_long quic_initial_max_streams_bidi;
    zend_long quic_initial_max_streams_uni;
    zend_long quic_active_connection_id_limit;
    bool quic_stateless_retry_enable;
    bool quic_grease_enable;
    bool quic_datagrams_enable;
    zend_long quic_dgram_recv_queue_len;
    zend_long quic_dgram_send_queue_len;
} king_server_http3_options_t;

typedef struct _king_server_http3_request_state {
    zval headers;
    smart_str body;
    zend_string *method;
    zend_string *path;
    zend_string *scheme;
    zend_string *authority;
    uint64_t stream_id;
    size_t header_bytes;
    size_t body_bytes;
    bool headers_initialized;
    bool request_stream_seen;
} king_server_http3_request_state_t;

typedef struct _king_server_http3_request_header_context {
    king_server_http3_request_state_t *state;
    const char *function_name;
} king_server_http3_request_header_context_t;

typedef struct _king_server_http3_header {
    const uint8_t *name;
    size_t name_len;
    const uint8_t *value;
    size_t value_len;
} king_server_http3_header_t;

#if defined(KING_HTTP3_BACKEND_LSQUIC)
typedef struct _king_server_http3_lsquic_stream_state king_server_http3_lsquic_stream_state_t;
#endif

typedef struct _king_server_http3_response_state {
    king_server_http3_header_t *headers;
    size_t headers_len;
    zend_string **owned_strings;
    size_t owned_string_count;
    zend_string *body;
    uint64_t stream_id;
    size_t body_offset;
    zend_long close_grace_deadline_ms;
    bool initialized;
    bool headers_sent;
    bool goaway_sent;
    bool close_sent;
} king_server_http3_response_state_t;

typedef struct _king_server_http3_runtime {
    int socket_fd;
    struct sockaddr_storage local_addr;
    socklen_t local_addr_len;
    struct sockaddr_storage peer_addr;
    socklen_t peer_addr_len;
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    bool lsquic_backend_active;
    struct lsquic_engine_settings lsquic_settings;
    struct lsquic_engine_api lsquic_api;
    lsquic_engine_t *lsquic_engine;
    lsquic_conn_t *lsquic_conn;
    SSL_CTX *lsquic_ssl_ctx;
    king_client_session_t *lsquic_session;
    king_server_http3_request_state_t *lsquic_request_state;
    king_server_http3_response_state_t *lsquic_response_state;
    zend_bool *lsquic_request_complete;
    king_server_http3_lsquic_stream_state_t *lsquic_stream_state;
    const char *lsquic_function_name;
    bool lsquic_connection_closed;
    bool lsquic_request_failed;
    bool lsquic_response_failed;
#endif
} king_server_http3_runtime_t;

typedef enum _king_server_http3_lsquic_load_error_kind {
    KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_NONE = 0,
    KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY,
    KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL,
    KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT
} king_server_http3_lsquic_load_error_kind_t;

typedef struct _king_server_http3_lsquic_api {
    void *handle;
    bool load_attempted;
    bool ready;
    bool global_initialized;
    king_server_http3_lsquic_load_error_kind_t load_error_kind;
    char load_error[KING_ERR_LEN];
    int (*lsquic_global_init_fn)(int);
    void (*lsquic_global_cleanup_fn)(void);
    void (*lsquic_engine_init_settings_fn)(void *, unsigned);
    int (*lsquic_engine_check_settings_fn)(const void *, unsigned, char *, size_t);
    void *(*lsquic_engine_new_fn)(unsigned, const void *);
    void (*lsquic_engine_destroy_fn)(void *);
    int (*lsquic_engine_packet_in_fn)(void *, const unsigned char *, size_t, const struct sockaddr *, const struct sockaddr *, void *, int);
    void (*lsquic_engine_process_conns_fn)(void *);
    int (*lsquic_engine_has_unsent_packets_fn)(void *);
    void (*lsquic_engine_send_unsent_packets_fn)(void *);
    int (*lsquic_engine_earliest_adv_tick_fn)(void *, int *);
    unsigned (*lsquic_engine_get_conns_count_fn)(void *);
    unsigned (*lsquic_engine_count_attq_fn)(void *, int);
    int (*lsquic_conn_status_fn)(void *, char *, size_t);
    void (*lsquic_conn_close_fn)(void *);
    void *(*lsquic_conn_get_ctx_fn)(const void *);
    void (*lsquic_conn_set_ctx_fn)(void *, void *);
    int (*lsquic_stream_send_headers_fn)(void *, const void *, int);
    void *(*lsquic_stream_get_hset_fn)(void *);
    ssize_t (*lsquic_stream_write_fn)(void *, const void *, size_t);
    ssize_t (*lsquic_stream_read_fn)(void *, void *, size_t);
    int (*lsquic_stream_flush_fn)(void *);
    int (*lsquic_stream_shutdown_fn)(void *, int);
    int (*lsquic_stream_close_fn)(void *);
    int (*lsquic_stream_wantread_fn)(void *, int);
    int (*lsquic_stream_wantwrite_fn)(void *, int);
    void *(*lsquic_stream_get_ctx_fn)(const void *);
    void (*lsquic_stream_set_ctx_fn)(void *, void *);
    uint64_t (*lsquic_stream_id_fn)(const void *);
} king_server_http3_lsquic_api_t;

static king_server_http3_lsquic_api_t king_server_http3_lsquic = {0};


#include "http3/local_listener_leaf.inc"
#if defined(KING_HTTP3_BACKEND_LSQUIC)
#include "http3/lsquic_loader.inc"
#endif
#include "http3/options_and_runtime.inc"
#include "http3/request_response.inc"
#if defined(KING_HTTP3_BACKEND_LSQUIC)
#include "http3/lsquic_tls.inc"
#include "http3/lsquic_stream_runtime.inc"
#include "http3/lsquic_runtime.inc"
#include "http3/lsquic_listen_once.inc"
#endif
#include "http3/listen_once_api.inc"
