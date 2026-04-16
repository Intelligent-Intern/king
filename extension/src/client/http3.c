/*
 * =========================================================================
 * FILENAME:   src/client/http3.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Minimal live HTTP/3 client runtime for the active runtime. The
 * implementation keeps the extension free of a hard liblsquic dependency by
 * loading the bundled/system liblsquic at runtime, then driving the direct
 * one-shot and multi-request HTTPS-over-QUIC leaves with the existing King
 * config snapshot and normalized response contract.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/http3.h"
/* lsquic loaded at runtime via dlopen
#include <lsquic.h>
*/
#include "include/config/config.h"
#include "include/config/quic_transport/base_layer.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/config/tls_and_crypto/base_layer.h"
#include "include/telemetry/telemetry.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/url.h"

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
    bool quic_cc_enable_hystart_plus_plus;
    bool quic_pacing_enable;
    zend_long quic_max_ack_delay_ms;
    zend_long quic_ack_delay_exponent;
    zend_long quic_initial_max_data;
    zend_long quic_initial_max_stream_data_bidi_local;
    zend_long quic_initial_max_stream_data_bidi_remote;
    zend_long quic_initial_max_stream_data_uni;
    zend_long quic_initial_max_streams_bidi;
    zend_long quic_initial_max_streams_uni;
    zend_long quic_active_connection_id_limit;
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

typedef struct _king_http3_request_runtime {
    int socket_fd;
    struct sockaddr_storage peer_addr;
    socklen_t peer_addr_len;
    struct sockaddr_storage local_addr;
    socklen_t local_addr_len;
    lsquic_config *config;
    lsquic_conn *conn;
    lsquic_h3_config *h3_config;
    lsquic_h3_conn *h3_conn;
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
    lsquic_h3_header *request_headers;
    size_t request_header_count;
    zend_string **owned_strings;
    size_t owned_string_count;
    king_http3_response_t response;
    uint64_t request_stream_id;
    size_t body_offset;
    bool effective_headers_initialized;
    bool request_headers_sent;
} king_http3_multi_request_t;

typedef struct _king_http3_lsquic_stats {
    size_t recv;
    size_t sent;
    size_t lost;
    size_t spurious_lost;
    size_t retrans;
    uint64_t sent_bytes;
    uint64_t recv_bytes;
    uint64_t acked_bytes;
    uint64_t lost_bytes;
    uint64_t stream_retrans_bytes;
    size_t dgram_recv;
    size_t dgram_sent;
    size_t paths_count;
    uint64_t reset_stream_count_local;
    uint64_t stopped_stream_count_local;
    uint64_t reset_stream_count_remote;
    uint64_t stopped_stream_count_remote;
    uint64_t data_blocked_sent_count;
    uint64_t stream_data_blocked_sent_count;
    uint64_t data_blocked_recv_count;
    uint64_t stream_data_blocked_recv_count;
    uint64_t streams_blocked_bidi_recv_count;
    uint64_t streams_blocked_uni_recv_count;
    uint64_t path_challenge_rx_count;
    uint64_t bytes_in_flight_duration_msec;
    bool tx_buffered_inconsistent;
} king_http3_lsquic_stats_t;

typedef struct _king_http3_lsquic_api {
    void *handle;
    bool load_attempted;
    bool ready;
    char load_error[KING_ERR_LEN];
    lsquic_config *(*lsquic_config_new_fn)(uint32_t);
    int (*lsquic_config_load_cert_chain_from_pem_file_fn)(lsquic_config *, const char *);
    int (*lsquic_config_load_priv_key_from_pem_file_fn)(lsquic_config *, const char *);
    int (*lsquic_config_load_verify_locations_from_file_fn)(lsquic_config *, const char *);
    void (*lsquic_config_verify_peer_fn)(lsquic_config *, bool);
    void (*lsquic_config_grease_fn)(lsquic_config *, bool);
    void (*lsquic_config_enable_early_data_fn)(lsquic_config *);
    void (*lsquic_config_enable_hystart_fn)(lsquic_config *, bool);
    void (*lsquic_config_enable_pacing_fn)(lsquic_config *, bool);
    int (*lsquic_config_set_application_protos_fn)(lsquic_config *, const uint8_t *, size_t);
    void (*lsquic_config_set_max_idle_timeout_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_max_recv_udp_payload_size_fn)(lsquic_config *, size_t);
    void (*lsquic_config_set_max_send_udp_payload_size_fn)(lsquic_config *, size_t);
    void (*lsquic_config_set_initial_max_data_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_initial_max_stream_data_bidi_local_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_initial_max_stream_data_bidi_remote_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_initial_max_stream_data_uni_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_initial_max_streams_bidi_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_initial_max_streams_uni_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_ack_delay_exponent_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_max_ack_delay_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_set_disable_active_migration_fn)(lsquic_config *, bool);
    int (*lsquic_config_set_cc_algorithm_name_fn)(lsquic_config *, const char *);
    void (*lsquic_config_set_initial_congestion_window_packets_fn)(lsquic_config *, size_t);
    void (*lsquic_config_set_active_connection_id_limit_fn)(lsquic_config *, uint64_t);
    void (*lsquic_config_enable_dgram_fn)(lsquic_config *, bool, size_t, size_t);
    void (*lsquic_config_free_fn)(lsquic_config *);
    lsquic_conn *(*lsquic_connect_fn)(
        const char *,
        const uint8_t *,
        size_t,
        const struct sockaddr *,
        socklen_t,
        const struct sockaddr *,
        socklen_t,
        lsquic_config *
    );
    ssize_t (*lsquic_conn_recv_fn)(lsquic_conn *, uint8_t *, size_t, const lsquic_recv_info *);
    ssize_t (*lsquic_conn_send_fn)(lsquic_conn *, uint8_t *, size_t, lsquic_send_info *);
    uint64_t (*lsquic_conn_timeout_as_millis_fn)(const lsquic_conn *);
    void (*lsquic_conn_on_timeout_fn)(lsquic_conn *);
    bool (*lsquic_conn_is_established_fn)(const lsquic_conn *);
    bool (*lsquic_conn_is_resumed_fn)(const lsquic_conn *);
    bool (*lsquic_conn_is_in_early_data_fn)(const lsquic_conn *);
    void (*lsquic_conn_stats_fn)(const lsquic_conn *, king_http3_lsquic_stats_t *);
    bool (*lsquic_conn_is_closed_fn)(const lsquic_conn *);
    bool (*lsquic_conn_is_timed_out_fn)(const lsquic_conn *);
    int (*lsquic_conn_close_fn)(lsquic_conn *, bool, uint64_t, const uint8_t *, size_t);
    int (*lsquic_conn_set_session_fn)(lsquic_conn *, const uint8_t *, size_t);
    void (*lsquic_conn_session_fn)(const lsquic_conn *, const uint8_t **, size_t *);
    bool (*lsquic_conn_peer_error_fn)(const lsquic_conn *, bool *, uint64_t *, const uint8_t **, size_t *);
    bool (*lsquic_conn_local_error_fn)(const lsquic_conn *, bool *, uint64_t *, const uint8_t **, size_t *);
    void (*lsquic_conn_free_fn)(lsquic_conn *);
    lsquic_h3_config *(*lsquic_h3_config_new_fn)(void);
    void (*lsquic_h3_config_free_fn)(lsquic_h3_config *);
    lsquic_h3_conn *(*lsquic_h3_conn_new_with_transport_fn)(lsquic_conn *, lsquic_h3_config *);
    int64_t (*lsquic_h3_conn_poll_fn)(lsquic_h3_conn *, lsquic_conn *, lsquic_h3_event **);
    enum lsquic_h3_event_type (*lsquic_h3_event_type_fn)(lsquic_h3_event *);
    uint64_t (*lsquic_h3_event_reset_error_fn)(lsquic_h3_event *);
    int (*lsquic_h3_event_for_each_header_fn)(
        lsquic_h3_event *,
        int (*)(uint8_t *, size_t, uint8_t *, size_t, void *),
        void *
    );
    void (*lsquic_h3_event_free_fn)(lsquic_h3_event *);
    int64_t (*lsquic_h3_send_request_fn)(lsquic_h3_conn *, lsquic_conn *, const lsquic_h3_header *, size_t, bool);
    ssize_t (*lsquic_h3_send_body_fn)(lsquic_h3_conn *, lsquic_conn *, uint64_t, const uint8_t *, size_t, bool);
    ssize_t (*lsquic_h3_recv_body_fn)(lsquic_h3_conn *, lsquic_conn *, uint64_t, uint8_t *, size_t);
    void (*lsquic_h3_conn_free_fn)(lsquic_h3_conn *);
} king_http3_lsquic_api_t;

static king_http3_lsquic_api_t king_http3_lsquic = {0};

static void king_http3_free_request_headers(
    lsquic_h3_header *headers,
    zend_string **owned_strings,
    size_t owned_string_count);
static void king_http3_request_target_destroy(
    php_url *parsed_url,
    king_http3_request_target_t *target);


#include "http3/errors_and_validation.inc"
#include "http3/lsquic_loader.inc"
#include "http3/runtime_helpers.inc"
#include "http3/runtime_init.inc"
#include "http3/request_response.inc"
#include "http3/multi_request.inc"
#include "http3/dispatch_api.inc"
