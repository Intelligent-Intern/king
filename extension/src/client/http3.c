/*
 * =========================================================================
 * FILENAME:   src/client/http3.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Active HTTP/3 client runtime backed by LSQUIC. Builds without the LSQUIC
 * backend fail closed instead of loading a legacy fallback at runtime.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/http3.h"
#include "include/config/config.h"
#include "include/config/quic_transport/base_layer.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/config/tls_and_crypto/base_layer.h"
#include "include/telemetry/telemetry.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/url.h"

#if defined(KING_HTTP3_BACKEND_LSQUIC)
#include <lsquic.h>
#include <lsxpack_header.h>
#include <openssl/x509.h>
#include <openssl/x509v3.h>
#endif

#include <arpa/inet.h>
#include <ctype.h>
#include <dlfcn.h>
#include <fcntl.h>
#include <inttypes.h>
#include <limits.h>
#include <netdb.h>
#include <poll.h>
#include <stdarg.h>
#include <stdbool.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>
#include <sys/stat.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>
#include <zend_exceptions.h>

#define KING_HTTP3_DEFAULT_TIMEOUT_MS 15000L
#define KING_HTTP3_MAX_DATAGRAM_SIZE 1350
#define KING_HTTP3_MAX_RESPONSE_BYTES (8 * 1024 * 1024)
#define KING_HTTP3_MAX_HEADER_BYTES   (128 * 1024)
#define KING_HTTP3_CANCEL_CLOSE_CODE  0x4b01
#define KING_HTTP3_CANCEL_CLOSE_REASON "cancelled by userland CancelToken"
#define KING_HTTP3_CANCEL_CLOSE_REASON_LEN (sizeof(KING_HTTP3_CANCEL_CLOSE_REASON) - 1)

typedef struct _king_http3_request_options {
    king_cfg_t *config;
    zend_long connect_timeout_ms;
    zend_long timeout_ms;
    bool tls_verify_peer;
    bool tls_enable_early_data;
    const char *tls_default_ca_file;
    const char *tls_default_cert_file;
    const char *tls_default_key_file;
    const char *quic_cc_algorithm;
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
    zval *cancel_token;
    const char *cancel_function_name;
    zend_class_entry *cancel_exception_ce;
} king_http3_request_options_t;

typedef struct _king_http3_response {
    zval headers;
    smart_str body;
    zend_string *status_line;
    long status_code;
    size_t body_bytes;
    size_t header_bytes;
    bool headers_initialized;
    bool body_overflowed;
    bool header_overflowed;
    bool response_complete;
} king_http3_response_t;

typedef struct _king_http3_header {
    const uint8_t *name;
    size_t name_len;
    const uint8_t *value;
    size_t value_len;
} king_http3_header_t;

#if defined(KING_HTTP3_BACKEND_LSQUIC)
typedef struct _king_http3_lsquic_request_state king_http3_lsquic_request_state_t;
#endif

typedef struct _king_http3_request_runtime {
    int socket_fd;
    struct sockaddr_storage peer_addr;
    socklen_t peer_addr_len;
    struct sockaddr_storage local_addr;
    socklen_t local_addr_len;
    const char *tls_ticket_source;
    zend_long tls_session_ticket_length;
    bool tls_has_session_ticket;
    bool tls_enable_early_data;
    bool tls_session_resumed;
    bool tls_ticket_published;
    bool tls_request_sent_in_early_data;
    zend_long quic_packets_sent;
    zend_long quic_packets_received;
    zend_long quic_packets_lost;
    zend_long quic_packets_retransmitted;
    zend_long quic_lost_bytes;
    zend_long quic_stream_retransmitted_bytes;
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    bool lsquic_backend_active;
    struct lsquic_engine_settings lsquic_settings;
    struct lsquic_engine_api lsquic_api;
    lsquic_engine_t *lsquic_engine;
    lsquic_conn_t *lsquic_conn;
    king_http3_lsquic_request_state_t *lsquic_pending_requests;
    size_t lsquic_pending_request_count;
    size_t lsquic_pending_request_offset;
    unsigned char lsquic_session_resume[KING_MAX_TICKET_SIZE];
    size_t lsquic_session_resume_len;
    bool lsquic_connection_closed;
    bool lsquic_tls_verify_peer;
    bool lsquic_tls_verify_failed;
    const char *lsquic_tls_ca_file;
    const char *lsquic_tls_verify_host;
#endif
} king_http3_request_runtime_t;

typedef struct _king_http3_response_header_context {
    king_http3_response_t *response;
} king_http3_response_header_context_t;

typedef struct _king_http3_request_target {
    zend_string *authority;
    zend_string *host;
    zend_string *path;
    zend_long port;
    bool secure_transport;
} king_http3_request_target_t;

typedef struct _king_http3_multi_request {
    zend_string *url;
    php_url *parsed_url;
    king_http3_request_target_t target;
    zend_string *method;
    zend_string *body_string;
    zval effective_headers;
    king_http3_header_t *request_headers;
    size_t request_header_count;
    zend_string **owned_strings;
    size_t owned_string_count;
    king_http3_response_t response;
    uint64_t request_stream_id;
    size_t body_offset;
    bool effective_headers_initialized;
    bool request_headers_sent;
} king_http3_multi_request_t;

typedef enum _king_http3_lsquic_load_error_kind {
    KING_HTTP3_LSQUIC_LOAD_ERROR_NONE = 0,
    KING_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY,
    KING_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL,
    KING_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT
} king_http3_lsquic_load_error_kind_t;

typedef struct _king_http3_lsquic_api {
    void *handle;
    bool load_attempted;
    bool ready;
    bool global_initialized;
    king_http3_lsquic_load_error_kind_t load_error_kind;
    char load_error[KING_ERR_LEN];
    int (*lsquic_global_init_fn)(int);
    void (*lsquic_global_cleanup_fn)(void);
    void (*lsquic_engine_init_settings_fn)(void *, unsigned);
    int (*lsquic_engine_check_settings_fn)(const void *, unsigned, char *, size_t);
    void *(*lsquic_engine_new_fn)(unsigned, const void *);
    void (*lsquic_engine_destroy_fn)(void *);
    void *(*lsquic_engine_connect_fn)(void *, int, const struct sockaddr *, const struct sockaddr *, void *, void *, const char *, unsigned short, const unsigned char *, size_t, const unsigned char *, size_t);
    int (*lsquic_engine_packet_in_fn)(void *, const unsigned char *, size_t, const struct sockaddr *, const struct sockaddr *, void *, int);
    void (*lsquic_engine_process_conns_fn)(void *);
    int (*lsquic_engine_has_unsent_packets_fn)(void *);
    void (*lsquic_engine_send_unsent_packets_fn)(void *);
    int (*lsquic_engine_earliest_adv_tick_fn)(void *, int *);
    unsigned (*lsquic_engine_get_conns_count_fn)(void *);
    unsigned (*lsquic_engine_count_attq_fn)(void *, int);
    void (*lsquic_conn_make_stream_fn)(void *);
    unsigned (*lsquic_conn_n_avail_streams_fn)(const void *);
    unsigned (*lsquic_conn_n_pending_streams_fn)(const void *);
    unsigned (*lsquic_conn_cancel_pending_streams_fn)(void *, unsigned);
    int (*lsquic_conn_status_fn)(void *, char *, size_t);
    int (*lsquic_conn_get_info_fn)(void *, void *);
    void (*lsquic_conn_close_fn)(void *);
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
    void *(*lsquic_conn_get_ctx_fn)(const void *);
    void (*lsquic_conn_set_ctx_fn)(void *, void *);
} king_http3_lsquic_api_t;

static king_http3_lsquic_api_t king_http3_lsquic = {0};

static void king_http3_free_request_headers(
    king_http3_header_t *headers,
    zend_string **owned_strings,
    size_t owned_string_count);
static void king_http3_request_target_destroy(
    php_url *parsed_url,
    king_http3_request_target_t *target);
static int king_http3_collect_response_header(
    uint8_t *name,
    size_t name_len,
    uint8_t *value,
    size_t value_len,
    void *argp);

#if defined(KING_HTTP3_BACKEND_LSQUIC)
static void king_http3_lsquic_seed_ticket_from_ring(king_http3_request_runtime_t *runtime);
static void king_http3_lsquic_refresh_transport_stats(king_http3_request_runtime_t *runtime);
static void king_http3_lsquic_runtime_destroy(king_http3_request_runtime_t *runtime);
#endif


#include "http3/errors_and_validation.inc"
#include "http3/lsquic_loader.inc"
#include "http3/lsquic_stream_runtime.inc"
#include "http3/lsquic_option_diagnostics.inc"
#include "http3/lsquic_runtime.inc"
#include "http3/runtime_helpers.inc"
#include "http3/runtime_init.inc"
#include "http3/request_response.inc"
#include "http3/lsquic_dispatch.inc"
#include "http3/lsquic_multi_dispatch.inc"
#include "http3/multi_request.inc"
#include "http3/dispatch_api.inc"
