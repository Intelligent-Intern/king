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
/* lsquic loaded at runtime via dlopen
#include <lsquic.h>
*/
#include "include/config/config.h"
#include "include/config/quic_transport/base_layer.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/config/tls_and_crypto/base_layer.h"
#include "include/server/http3.h"
#include "include/server/session.h"

#include "Zend/zend_smart_str.h"

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

typedef struct _king_server_http3_response_state {
    lsquic_h3_header *headers;
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
    lsquic_config *config;
    lsquic_conn *conn;
    lsquic_h3_config *h3_config;
    lsquic_h3_conn *h3_conn;
} king_server_http3_runtime_t;

typedef struct _king_server_http3_lsquic_api {
    void *handle;
    bool load_attempted;
    bool ready;
    char load_error[KING_ERR_LEN];
    lsquic_config *(*lsquic_config_new_fn)(uint32_t);
    int (*lsquic_config_load_cert_chain_from_pem_file_fn)(lsquic_config *, const char *);
    int (*lsquic_config_load_priv_key_from_pem_file_fn)(lsquic_config *, const char *);
    void (*lsquic_config_grease_fn)(lsquic_config *, bool);
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
    int (*lsquic_header_info_fn)(const uint8_t *, size_t, size_t, uint32_t *, uint8_t *, uint8_t *, size_t *, uint8_t *, size_t *, uint8_t *, size_t *);
    bool (*lsquic_version_is_supported_fn)(uint32_t);
    ssize_t (*lsquic_negotiate_version_fn)(const uint8_t *, size_t, const uint8_t *, size_t, uint8_t *, size_t);
    lsquic_conn *(*lsquic_accept_fn)(const uint8_t *, size_t, const uint8_t *, size_t, const struct sockaddr *, socklen_t, const struct sockaddr *, socklen_t, lsquic_config *);
    ssize_t (*lsquic_conn_recv_fn)(lsquic_conn *, uint8_t *, size_t, const lsquic_recv_info *);
    ssize_t (*lsquic_conn_send_fn)(lsquic_conn *, uint8_t *, size_t, lsquic_send_info *);
    uint64_t (*lsquic_conn_timeout_as_millis_fn)(const lsquic_conn *);
    void (*lsquic_conn_on_timeout_fn)(lsquic_conn *);
    bool (*lsquic_conn_is_established_fn)(const lsquic_conn *);
    bool (*lsquic_conn_is_closed_fn)(const lsquic_conn *);
    int (*lsquic_conn_close_fn)(lsquic_conn *, bool, uint64_t, const uint8_t *, size_t);
    void (*lsquic_conn_free_fn)(lsquic_conn *);
    lsquic_h3_config *(*lsquic_h3_config_new_fn)(void);
    void (*lsquic_h3_config_free_fn)(lsquic_h3_config *);
    lsquic_h3_conn *(*lsquic_h3_conn_new_with_transport_fn)(lsquic_conn *, lsquic_h3_config *);
    int64_t (*lsquic_h3_conn_poll_fn)(lsquic_h3_conn *, lsquic_conn *, lsquic_h3_event **);
    enum lsquic_h3_event_type (*lsquic_h3_event_type_fn)(lsquic_h3_event *);
    int (*lsquic_h3_event_for_each_header_fn)(lsquic_h3_event *, int (*)(uint8_t *, size_t, uint8_t *, size_t, void *), void *);
    bool (*lsquic_h3_event_headers_has_more_frames_fn)(lsquic_h3_event *);
    void (*lsquic_h3_event_free_fn)(lsquic_h3_event *);
    int (*lsquic_h3_send_response_fn)(lsquic_h3_conn *, lsquic_conn *, uint64_t, const lsquic_h3_header *, size_t, bool);
    ssize_t (*lsquic_h3_send_body_fn)(lsquic_h3_conn *, lsquic_conn *, uint64_t, const uint8_t *, size_t, bool);
    ssize_t (*lsquic_h3_recv_body_fn)(lsquic_h3_conn *, lsquic_conn *, uint64_t, uint8_t *, size_t);
    int (*lsquic_h3_send_goaway_fn)(lsquic_h3_conn *, lsquic_conn *, uint64_t);
    void (*lsquic_h3_conn_free_fn)(lsquic_h3_conn *);
} king_server_http3_lsquic_api_t;

static king_server_http3_lsquic_api_t king_server_http3_lsquic = {0};


#include "http3/local_listener_leaf.inc"
#include "http3/lsquic_loader.inc"
#include "http3/options_and_runtime.inc"
#include "http3/request_response.inc"
#include "http3/event_loop.inc"
#include "http3/listen_once_api.inc"
