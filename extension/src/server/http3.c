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
#include <quiche.h>
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
    quiche_h3_header *headers;
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
    quiche_config *config;
    quiche_conn *conn;
    quiche_h3_config *h3_config;
    quiche_h3_conn *h3_conn;
} king_server_http3_runtime_t;

typedef struct _king_server_http3_quiche_api {
    void *handle;
    bool load_attempted;
    bool ready;
    char load_error[KING_ERR_LEN];
    quiche_config *(*quiche_config_new_fn)(uint32_t);
    int (*quiche_config_load_cert_chain_from_pem_file_fn)(quiche_config *, const char *);
    int (*quiche_config_load_priv_key_from_pem_file_fn)(quiche_config *, const char *);
    void (*quiche_config_grease_fn)(quiche_config *, bool);
    void (*quiche_config_enable_hystart_fn)(quiche_config *, bool);
    void (*quiche_config_enable_pacing_fn)(quiche_config *, bool);
    int (*quiche_config_set_application_protos_fn)(quiche_config *, const uint8_t *, size_t);
    void (*quiche_config_set_max_idle_timeout_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_max_recv_udp_payload_size_fn)(quiche_config *, size_t);
    void (*quiche_config_set_max_send_udp_payload_size_fn)(quiche_config *, size_t);
    void (*quiche_config_set_initial_max_data_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_initial_max_stream_data_bidi_local_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_initial_max_stream_data_bidi_remote_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_initial_max_stream_data_uni_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_initial_max_streams_bidi_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_initial_max_streams_uni_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_ack_delay_exponent_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_max_ack_delay_fn)(quiche_config *, uint64_t);
    void (*quiche_config_set_disable_active_migration_fn)(quiche_config *, bool);
    int (*quiche_config_set_cc_algorithm_name_fn)(quiche_config *, const char *);
    void (*quiche_config_set_initial_congestion_window_packets_fn)(quiche_config *, size_t);
    void (*quiche_config_set_active_connection_id_limit_fn)(quiche_config *, uint64_t);
    void (*quiche_config_enable_dgram_fn)(quiche_config *, bool, size_t, size_t);
    void (*quiche_config_free_fn)(quiche_config *);
    int (*quiche_header_info_fn)(const uint8_t *, size_t, size_t, uint32_t *, uint8_t *, uint8_t *, size_t *, uint8_t *, size_t *, uint8_t *, size_t *);
    bool (*quiche_version_is_supported_fn)(uint32_t);
    ssize_t (*quiche_negotiate_version_fn)(const uint8_t *, size_t, const uint8_t *, size_t, uint8_t *, size_t);
    quiche_conn *(*quiche_accept_fn)(const uint8_t *, size_t, const uint8_t *, size_t, const struct sockaddr *, socklen_t, const struct sockaddr *, socklen_t, quiche_config *);
    ssize_t (*quiche_conn_recv_fn)(quiche_conn *, uint8_t *, size_t, const quiche_recv_info *);
    ssize_t (*quiche_conn_send_fn)(quiche_conn *, uint8_t *, size_t, quiche_send_info *);
    uint64_t (*quiche_conn_timeout_as_millis_fn)(const quiche_conn *);
    void (*quiche_conn_on_timeout_fn)(quiche_conn *);
    bool (*quiche_conn_is_established_fn)(const quiche_conn *);
    bool (*quiche_conn_is_closed_fn)(const quiche_conn *);
    int (*quiche_conn_close_fn)(quiche_conn *, bool, uint64_t, const uint8_t *, size_t);
    void (*quiche_conn_free_fn)(quiche_conn *);
    quiche_h3_config *(*quiche_h3_config_new_fn)(void);
    void (*quiche_h3_config_free_fn)(quiche_h3_config *);
    quiche_h3_conn *(*quiche_h3_conn_new_with_transport_fn)(quiche_conn *, quiche_h3_config *);
    int64_t (*quiche_h3_conn_poll_fn)(quiche_h3_conn *, quiche_conn *, quiche_h3_event **);
    enum quiche_h3_event_type (*quiche_h3_event_type_fn)(quiche_h3_event *);
    int (*quiche_h3_event_for_each_header_fn)(quiche_h3_event *, int (*)(uint8_t *, size_t, uint8_t *, size_t, void *), void *);
    bool (*quiche_h3_event_headers_has_more_frames_fn)(quiche_h3_event *);
    void (*quiche_h3_event_free_fn)(quiche_h3_event *);
    int (*quiche_h3_send_response_fn)(quiche_h3_conn *, quiche_conn *, uint64_t, const quiche_h3_header *, size_t, bool);
    ssize_t (*quiche_h3_send_body_fn)(quiche_h3_conn *, quiche_conn *, uint64_t, const uint8_t *, size_t, bool);
    ssize_t (*quiche_h3_recv_body_fn)(quiche_h3_conn *, quiche_conn *, uint64_t, uint8_t *, size_t);
    int (*quiche_h3_send_goaway_fn)(quiche_h3_conn *, quiche_conn *, uint64_t);
    void (*quiche_h3_conn_free_fn)(quiche_h3_conn *);
} king_server_http3_quiche_api_t;

static king_server_http3_quiche_api_t king_server_http3_quiche = {0};

static void king_server_http3_build_request(
    zval *request,
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port
)
{
    zval headers;
    zend_string *authority;

    authority = strpprintf(0, "%.*s:%ld", (int) host_len, host, port);

    array_init(request);
    add_assoc_string(request, "method", "GET");
    add_assoc_string(request, "uri", "/");
    add_assoc_string(request, "version", "HTTP/3");
    add_assoc_str(request, "host", zend_string_copy(authority));

    array_init(&headers);
    add_assoc_string(&headers, ":method", "GET");
    add_assoc_string(&headers, ":path", "/");
    add_assoc_string(&headers, ":scheme", "https");
    add_assoc_str(&headers, ":authority", authority);
    add_assoc_zval(request, "headers", &headers);
    add_assoc_string(request, "body", "");

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/3",
        "https",
        0
    );
}

PHP_FUNCTION(king_http3_server_listen)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session;
    zval request;
    zval retval;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_local_open_session(
        "king_http3_server_listen",
        host,
        host_len,
        port,
        config,
        3,
        "h3",
        "server_http3_local"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    king_server_http3_build_request(&request, session, host, host_len, port);

    if (king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http3_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);

        if (!Z_ISUNDEF(retval)) {
            zval_ptr_dtor(&retval);
        }

        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_local_validate_response(
            &retval,
            session,
            "http/3",
            "king_http3_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);
        zval_ptr_dtor(&retval);
        RETURN_FALSE;
    }

    king_server_local_close_session(session);
    zval_ptr_dtor(&request);
    zval_ptr_dtor(&retval);

    king_set_error("");
    RETURN_TRUE;
}

static const char *king_server_http3_map_candidate_error(const char *path)
{
    return path != NULL && path[0] != '\0' ? path : "unknown";
}

static zend_result king_server_http3_load_symbol(void **target, const char *name)
{
    *target = dlsym(king_server_http3_quiche.handle, name);
    if (*target == NULL) {
        snprintf(
            king_server_http3_quiche.load_error,
            sizeof(king_server_http3_quiche.load_error),
            "Failed to load libquiche symbol '%s'.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_server_http3_close_runtime_handle(void)
{
    if (king_server_http3_quiche.handle != NULL) {
        dlclose(king_server_http3_quiche.handle);
        king_server_http3_quiche.handle = NULL;
    }
}

static zend_result king_server_http3_ensure_quiche_ready(void)
{
    const char *env_path = getenv("KING_QUICHE_LIBRARY");
    const char *const candidates[] = {
        NULL,
        "../quiche/target/release/libquiche.so",
        "../quiche/target/debug/libquiche.so",
        "../../quiche/target/release/libquiche.so",
        "../../quiche/target/debug/libquiche.so",
        "libquiche.so",
        NULL
    };
    size_t i;

    if (king_server_http3_quiche.ready) {
        return SUCCESS;
    }

    if (king_server_http3_quiche.load_attempted) {
        return FAILURE;
    }

    king_server_http3_quiche.load_attempted = true;

    if (env_path != NULL && env_path[0] != '\0') {
        king_server_http3_quiche.handle = dlopen(env_path, RTLD_LAZY | RTLD_LOCAL);
    }

    for (i = 1; king_server_http3_quiche.handle == NULL && candidates[i] != NULL; ++i) {
        king_server_http3_quiche.handle = dlopen(candidates[i], RTLD_LAZY | RTLD_LOCAL);
    }

    if (king_server_http3_quiche.handle == NULL) {
        snprintf(
            king_server_http3_quiche.load_error,
            sizeof(king_server_http3_quiche.load_error),
            "Failed to load libquiche for the active HTTP/3 server runtime (checked '%s' first).",
            king_server_http3_map_candidate_error(env_path)
        );
        return FAILURE;
    }

    if (king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_new_fn, "quiche_config_new") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_load_cert_chain_from_pem_file_fn, "quiche_config_load_cert_chain_from_pem_file") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_load_priv_key_from_pem_file_fn, "quiche_config_load_priv_key_from_pem_file") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_grease_fn, "quiche_config_grease") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_enable_hystart_fn, "quiche_config_enable_hystart") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_enable_pacing_fn, "quiche_config_enable_pacing") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_application_protos_fn, "quiche_config_set_application_protos") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_idle_timeout_fn, "quiche_config_set_max_idle_timeout") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_recv_udp_payload_size_fn, "quiche_config_set_max_recv_udp_payload_size") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_send_udp_payload_size_fn, "quiche_config_set_max_send_udp_payload_size") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_data_fn, "quiche_config_set_initial_max_data") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_local_fn, "quiche_config_set_initial_max_stream_data_bidi_local") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_remote_fn, "quiche_config_set_initial_max_stream_data_bidi_remote") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_stream_data_uni_fn, "quiche_config_set_initial_max_stream_data_uni") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_streams_bidi_fn, "quiche_config_set_initial_max_streams_bidi") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_streams_uni_fn, "quiche_config_set_initial_max_streams_uni") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_ack_delay_exponent_fn, "quiche_config_set_ack_delay_exponent") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_ack_delay_fn, "quiche_config_set_max_ack_delay") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_disable_active_migration_fn, "quiche_config_set_disable_active_migration") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_cc_algorithm_name_fn, "quiche_config_set_cc_algorithm_name") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_congestion_window_packets_fn, "quiche_config_set_initial_congestion_window_packets") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_active_connection_id_limit_fn, "quiche_config_set_active_connection_id_limit") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_enable_dgram_fn, "quiche_config_enable_dgram") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_free_fn, "quiche_config_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_header_info_fn, "quiche_header_info") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_version_is_supported_fn, "quiche_version_is_supported") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_negotiate_version_fn, "quiche_negotiate_version") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_accept_fn, "quiche_accept") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_recv_fn, "quiche_conn_recv") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_send_fn, "quiche_conn_send") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_timeout_as_millis_fn, "quiche_conn_timeout_as_millis") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_on_timeout_fn, "quiche_conn_on_timeout") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_is_established_fn, "quiche_conn_is_established") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_is_closed_fn, "quiche_conn_is_closed") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_close_fn, "quiche_conn_close") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_free_fn, "quiche_conn_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_config_new_fn, "quiche_h3_config_new") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_config_free_fn, "quiche_h3_config_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_conn_new_with_transport_fn, "quiche_h3_conn_new_with_transport") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_conn_poll_fn, "quiche_h3_conn_poll") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_type_fn, "quiche_h3_event_type") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_for_each_header_fn, "quiche_h3_event_for_each_header") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_headers_has_more_frames_fn, "quiche_h3_event_headers_has_more_frames") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_free_fn, "quiche_h3_event_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_send_response_fn, "quiche_h3_send_response") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_send_body_fn, "quiche_h3_send_body") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_recv_body_fn, "quiche_h3_recv_body") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_send_goaway_fn, "quiche_h3_send_goaway") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_conn_free_fn, "quiche_h3_conn_free") != SUCCESS) {
        king_server_http3_close_runtime_handle();
        return FAILURE;
    }

    king_server_http3_quiche.ready = true;
    return SUCCESS;
}

static void king_server_http3_options_destroy(king_server_http3_options_t *options)
{
    if (options->owned_config != NULL) {
        king_config_free(options->owned_config);
        options->owned_config = NULL;
    }
}

static zend_result king_server_http3_parse_options(
    zval *config_zv,
    king_server_http3_options_t *options,
    const char *function_name
)
{
    king_cfg_t *cfg = NULL;
    zend_long connect_timeout_ms;

    memset(options, 0, sizeof(*options));

    connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms > 0
        ? king_tcp_transport_config.connect_timeout_ms
        : 5000;
    options->timeout_ms = connect_timeout_ms > KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS
        ? connect_timeout_ms
        : KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS;
    options->tls_default_cert_file = king_tls_and_crypto_config.tls_default_cert_file;
    options->tls_default_key_file = king_tls_and_crypto_config.tls_default_key_file;
    options->quic_cc_algorithm = king_quic_transport_config.cc_algorithm;
    options->quic_cc_initial_cwnd_packets = king_quic_transport_config.cc_initial_cwnd_packets;
    options->quic_cc_enable_hystart_plus_plus = king_quic_transport_config.cc_enable_hystart_plus_plus;
    options->quic_pacing_enable = king_quic_transport_config.pacing_enable;
    options->quic_max_ack_delay_ms = king_quic_transport_config.max_ack_delay_ms;
    options->quic_ack_delay_exponent = king_quic_transport_config.ack_delay_exponent;
    options->quic_initial_max_data = king_quic_transport_config.initial_max_data;
    options->quic_initial_max_stream_data_bidi_local = king_quic_transport_config.initial_max_stream_data_bidi_local;
    options->quic_initial_max_stream_data_bidi_remote = king_quic_transport_config.initial_max_stream_data_bidi_remote;
    options->quic_initial_max_stream_data_uni = king_quic_transport_config.initial_max_stream_data_uni;
    options->quic_initial_max_streams_bidi = king_quic_transport_config.initial_max_streams_bidi;
    options->quic_initial_max_streams_uni = king_quic_transport_config.initial_max_streams_uni;
    options->quic_active_connection_id_limit = king_quic_transport_config.active_connection_id_limit;
    options->quic_grease_enable = king_quic_transport_config.grease_enable;
    options->quic_datagrams_enable = king_quic_transport_config.datagrams_enable;
    options->quic_dgram_recv_queue_len = king_quic_transport_config.dgram_recv_queue_len;
    options->quic_dgram_send_queue_len = king_quic_transport_config.dgram_send_queue_len;

    if (config_zv != NULL && Z_TYPE_P(config_zv) != IS_NULL) {
        if (Z_TYPE_P(config_zv) == IS_ARRAY) {
            cfg = king_config_new_from_options(config_zv);
            if (cfg == NULL) {
                return FAILURE;
            }

            options->owned_config = cfg;
        } else {
            cfg = (king_cfg_t *) king_fetch_config(config_zv);
            if (cfg == NULL) {
                zend_argument_type_error(
                    3,
                    "must be null, array, a King\\Config resource, or a King\\Config object"
                );
                return FAILURE;
            }
        }

        connect_timeout_ms = cfg->tcp.connect_timeout_ms > 0
            ? cfg->tcp.connect_timeout_ms
            : connect_timeout_ms;
        options->timeout_ms = connect_timeout_ms > KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS
            ? connect_timeout_ms
            : KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS;
        options->tls_default_cert_file = cfg->tls.tls_default_cert_file;
        options->tls_default_key_file = cfg->tls.tls_default_key_file;
        options->quic_cc_algorithm = cfg->quic.cc_algorithm;
        options->quic_cc_initial_cwnd_packets = cfg->quic.cc_initial_cwnd_packets;
        options->quic_cc_enable_hystart_plus_plus = cfg->quic.cc_enable_hystart_plus_plus;
        options->quic_pacing_enable = cfg->quic.pacing_enable;
        options->quic_max_ack_delay_ms = cfg->quic.max_ack_delay_ms;
        options->quic_ack_delay_exponent = cfg->quic.ack_delay_exponent;
        options->quic_initial_max_data = cfg->quic.initial_max_data;
        options->quic_initial_max_stream_data_bidi_local = cfg->quic.initial_max_stream_data_bidi_local;
        options->quic_initial_max_stream_data_bidi_remote = cfg->quic.initial_max_stream_data_bidi_remote;
        options->quic_initial_max_stream_data_uni = cfg->quic.initial_max_stream_data_uni;
        options->quic_initial_max_streams_bidi = cfg->quic.initial_max_streams_bidi;
        options->quic_initial_max_streams_uni = cfg->quic.initial_max_streams_uni;
        options->quic_active_connection_id_limit = cfg->quic.active_connection_id_limit;
        options->quic_grease_enable = cfg->quic.grease_enable;
        options->quic_datagrams_enable = cfg->quic.datagrams_enable;
        options->quic_dgram_recv_queue_len = cfg->quic.dgram_recv_queue_len;
        options->quic_dgram_send_queue_len = cfg->quic.dgram_send_queue_len;
    }

    if (options->tls_default_cert_file == NULL || options->tls_default_cert_file[0] == '\0') {
        king_server_local_set_errorf(
            "%s() requires 'tls_default_cert_file' for the on-wire HTTP/3 listener.",
            function_name
        );
        return FAILURE;
    }

    if (options->tls_default_key_file == NULL || options->tls_default_key_file[0] == '\0') {
        king_server_local_set_errorf(
            "%s() requires 'tls_default_key_file' for the on-wire HTTP/3 listener.",
            function_name
        );
        return FAILURE;
    }

    if (access(options->tls_default_cert_file, R_OK) != 0) {
        king_server_local_set_errorf(
            "%s() could not read the HTTP/3 certificate file '%s'.",
            function_name,
            options->tls_default_cert_file
        );
        return FAILURE;
    }

    if (access(options->tls_default_key_file, R_OK) != 0) {
        king_server_local_set_errorf(
            "%s() could not read the HTTP/3 key file '%s'.",
            function_name,
            options->tls_default_key_file
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_server_http3_runtime_destroy(king_server_http3_runtime_t *runtime)
{
    if (runtime->h3_conn != NULL && king_server_http3_quiche.quiche_h3_conn_free_fn != NULL) {
        king_server_http3_quiche.quiche_h3_conn_free_fn(runtime->h3_conn);
    }
    if (runtime->h3_config != NULL && king_server_http3_quiche.quiche_h3_config_free_fn != NULL) {
        king_server_http3_quiche.quiche_h3_config_free_fn(runtime->h3_config);
    }
    if (runtime->conn != NULL && king_server_http3_quiche.quiche_conn_free_fn != NULL) {
        king_server_http3_quiche.quiche_conn_free_fn(runtime->conn);
    }
    if (runtime->config != NULL && king_server_http3_quiche.quiche_config_free_fn != NULL) {
        king_server_http3_quiche.quiche_config_free_fn(runtime->config);
    }
    if (runtime->socket_fd >= 0) {
        close(runtime->socket_fd);
    }
}

static zend_result king_server_http3_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    ssize_t got;

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    got = read(fd, target, target_len);
    close(fd);

    return got == (ssize_t) target_len ? SUCCESS : FAILURE;
}

static zend_long king_server_http3_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static zend_result king_server_http3_make_socket_nonblocking(int socket_fd)
{
    int flags = fcntl(socket_fd, F_GETFL, 0);

    if (flags < 0) {
        return FAILURE;
    }

    return fcntl(socket_fd, F_SETFL, flags | O_NONBLOCK) == 0
        ? SUCCESS
        : FAILURE;
}

static zend_result king_server_http3_set_socket_endpoint(
    zend_string **slot,
    zend_long *port_slot,
    int socket_fd,
    zend_bool peer,
    const char *function_name
)
{
    struct sockaddr_storage address;
    socklen_t address_len = sizeof(address);
    char host[NI_MAXHOST];
    char service[NI_MAXSERV];
    int rc;

    rc = peer
        ? getpeername(socket_fd, (struct sockaddr *) &address, &address_len)
        : getsockname(socket_fd, (struct sockaddr *) &address, &address_len);
    if (rc != 0) {
        king_server_local_set_errorf(
            "%s() failed to capture the accepted HTTP/3 socket endpoints (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    if (
        getnameinfo(
            (struct sockaddr *) &address,
            address_len,
            host,
            sizeof(host),
            service,
            sizeof(service),
            NI_NUMERICHOST | NI_NUMERICSERV
        ) != 0
    ) {
        king_server_local_set_errorf(
            "%s() failed to normalize the accepted HTTP/3 socket endpoints.",
            function_name
        );
        return FAILURE;
    }

    king_server_local_set_string(slot, host);
    *port_slot = strtol(service, NULL, 10);
    return SUCCESS;
}

static zend_result king_server_http3_apply_transport_snapshot_from_socket(
    king_client_session_t *session,
    int socket_fd,
    const char *function_name
)
{
    session->transport_socket_fd = socket_fd;
    session->transport_has_socket = true;
    session->transport_last_errno = 0;
    session->transport_datagrams_enable = true;
    king_server_local_set_string(&session->negotiated_alpn, "h3");
    king_server_local_set_string(&session->transport_backend, "server_http3_socket");
    king_server_local_set_string(&session->transport_socket_family, "udp");
    king_server_local_set_string(&session->transport_error_scope, "none");

    if (
        king_server_http3_set_socket_endpoint(
            &session->transport_local_address,
            &session->transport_local_port,
            socket_fd,
            0,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (
        king_server_http3_set_socket_endpoint(
            &session->transport_peer_address,
            &session->transport_peer_port,
            socket_fd,
            1,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http3_open_listener_socket(
    const char *host,
    zend_long port,
    king_server_http3_runtime_t *runtime,
    const char *function_name
)
{
    struct addrinfo hints;
    struct addrinfo *result = NULL;
    struct addrinfo *cursor;
    char service[16];
    int fd = -1;
    int rc;

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_DGRAM;
    hints.ai_protocol = IPPROTO_UDP;
    hints.ai_flags = AI_ADDRCONFIG;

    snprintf(service, sizeof(service), "%ld", port);

    rc = getaddrinfo(host, service, &hints, &result);
    if (rc != 0) {
        king_server_local_set_errorf(
            "%s() failed to resolve the HTTP/3 bind address '%s:%ld': %s.",
            function_name,
            host,
            port,
            gai_strerror(rc)
        );
        return FAILURE;
    }

    for (cursor = result; cursor != NULL; cursor = cursor->ai_next) {
        int optval = 1;

        fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (fd < 0) {
            continue;
        }

        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &optval, sizeof(optval));

        if (bind(fd, cursor->ai_addr, cursor->ai_addrlen) == 0) {
            break;
        }

        close(fd);
        fd = -1;
    }

    if (fd < 0) {
        freeaddrinfo(result);
        king_server_local_set_errorf(
            "%s() failed to bind the on-wire HTTP/3 listener socket (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    if (king_server_http3_make_socket_nonblocking(fd) != SUCCESS) {
        freeaddrinfo(result);
        close(fd);
        king_server_local_set_errorf(
            "%s() failed to switch the HTTP/3 listener socket into non-blocking mode.",
            function_name
        );
        return FAILURE;
    }

    runtime->socket_fd = fd;
    runtime->local_addr_len = sizeof(runtime->local_addr);
    if (getsockname(fd, (struct sockaddr *) &runtime->local_addr, &runtime->local_addr_len) != 0) {
        freeaddrinfo(result);
        close(fd);
        runtime->socket_fd = -1;
        king_server_local_set_errorf(
            "%s() failed to inspect the bound HTTP/3 listener socket (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    freeaddrinfo(result);
    return SUCCESS;
}

static zend_result king_server_http3_prepare_runtime_config(
    king_server_http3_runtime_t *runtime,
    king_server_http3_options_t *options,
    const char *function_name
)
{
    runtime->config = king_server_http3_quiche.quiche_config_new_fn(QUICHE_PROTOCOL_VERSION);
    if (runtime->config == NULL) {
        king_server_local_set_errorf(
            "%s() failed to allocate the libquiche server config.",
            function_name
        );
        return FAILURE;
    }

    if (king_server_http3_quiche.quiche_config_load_cert_chain_from_pem_file_fn(
            runtime->config,
            options->tls_default_cert_file
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to load the HTTP/3 certificate '%s'.",
            function_name,
            options->tls_default_cert_file
        );
        return FAILURE;
    }

    if (king_server_http3_quiche.quiche_config_load_priv_key_from_pem_file_fn(
            runtime->config,
            options->tls_default_key_file
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to load the HTTP/3 key '%s'.",
            function_name,
            options->tls_default_key_file
        );
        return FAILURE;
    }

    king_server_http3_quiche.quiche_config_grease_fn(runtime->config, options->quic_grease_enable);
    king_server_http3_quiche.quiche_config_enable_hystart_fn(runtime->config, options->quic_cc_enable_hystart_plus_plus);
    king_server_http3_quiche.quiche_config_enable_pacing_fn(runtime->config, options->quic_pacing_enable);

    if (king_server_http3_quiche.quiche_config_set_application_protos_fn(
            runtime->config,
            (const uint8_t *) QUICHE_H3_APPLICATION_PROTOCOL,
            sizeof(QUICHE_H3_APPLICATION_PROTOCOL) - 1
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to configure the HTTP/3 ALPN list.",
            function_name
        );
        return FAILURE;
    }

    king_server_http3_quiche.quiche_config_set_max_idle_timeout_fn(runtime->config, (uint64_t) options->timeout_ms);
    king_server_http3_quiche.quiche_config_set_max_recv_udp_payload_size_fn(runtime->config, KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE);
    king_server_http3_quiche.quiche_config_set_max_send_udp_payload_size_fn(runtime->config, KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE);
    king_server_http3_quiche.quiche_config_set_initial_max_data_fn(runtime->config, (uint64_t) options->quic_initial_max_data);
    king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_local_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_local);
    king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_remote_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_remote);
    king_server_http3_quiche.quiche_config_set_initial_max_stream_data_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_uni);
    king_server_http3_quiche.quiche_config_set_initial_max_streams_bidi_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_bidi);
    king_server_http3_quiche.quiche_config_set_initial_max_streams_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_uni);
    king_server_http3_quiche.quiche_config_set_ack_delay_exponent_fn(runtime->config, (uint64_t) options->quic_ack_delay_exponent);
    king_server_http3_quiche.quiche_config_set_max_ack_delay_fn(runtime->config, (uint64_t) options->quic_max_ack_delay_ms);
    king_server_http3_quiche.quiche_config_set_disable_active_migration_fn(runtime->config, true);
    king_server_http3_quiche.quiche_config_set_initial_congestion_window_packets_fn(runtime->config, (size_t) options->quic_cc_initial_cwnd_packets);
    king_server_http3_quiche.quiche_config_set_active_connection_id_limit_fn(runtime->config, (uint64_t) options->quic_active_connection_id_limit);
    king_server_http3_quiche.quiche_config_enable_dgram_fn(
        runtime->config,
        options->quic_datagrams_enable,
        (size_t) options->quic_dgram_recv_queue_len,
        (size_t) options->quic_dgram_send_queue_len
    );

    if (options->quic_cc_algorithm != NULL && options->quic_cc_algorithm[0] != '\0') {
        if (king_server_http3_quiche.quiche_config_set_cc_algorithm_name_fn(runtime->config, options->quic_cc_algorithm) != 0) {
            king_server_local_set_errorf(
                "%s() received an unsupported QUIC congestion-control algorithm '%s'.",
                function_name,
                options->quic_cc_algorithm
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_server_http3_add_header_value(
    zval *headers,
    const char *name,
    size_t name_len,
    const char *value,
    size_t value_len
)
{
    zend_string *normalized_name;
    zval *existing;
    zval array_value;
    zval old_value;

    normalized_name = zend_string_alloc(name_len, 0);
    for (size_t i = 0; i < name_len; ++i) {
        ZSTR_VAL(normalized_name)[i] = (char) tolower((unsigned char) name[i]);
    }
    ZSTR_VAL(normalized_name)[name_len] = '\0';

    existing = zend_hash_find(Z_ARRVAL_P(headers), normalized_name);
    if (existing == NULL) {
        add_assoc_stringl_ex(
            headers,
            ZSTR_VAL(normalized_name),
            ZSTR_LEN(normalized_name),
            (char *) value,
            value_len
        );
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    if (Z_TYPE_P(existing) == IS_ARRAY) {
        add_next_index_stringl(existing, (char *) value, value_len);
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    array_init(&array_value);
    ZVAL_COPY(&old_value, existing);
    add_next_index_zval(&array_value, &old_value);
    add_next_index_stringl(&array_value, (char *) value, value_len);
    zend_hash_update(Z_ARRVAL_P(headers), normalized_name, &array_value);
    zend_string_release(normalized_name);
    return SUCCESS;
}

static void king_server_http3_request_state_init(king_server_http3_request_state_t *state)
{
    memset(state, 0, sizeof(*state));
    array_init(&state->headers);
    state->headers_initialized = true;
}

static void king_server_http3_request_state_dtor(king_server_http3_request_state_t *state)
{
    if (state->method != NULL) {
        zend_string_release(state->method);
    }
    if (state->path != NULL) {
        zend_string_release(state->path);
    }
    if (state->scheme != NULL) {
        zend_string_release(state->scheme);
    }
    if (state->authority != NULL) {
        zend_string_release(state->authority);
    }
    if (state->headers_initialized) {
        zval_ptr_dtor(&state->headers);
    }
    smart_str_free(&state->body);
}

static int king_server_http3_collect_request_header(
    uint8_t *name,
    size_t name_len,
    uint8_t *value,
    size_t value_len,
    void *argp
)
{
    king_server_http3_request_header_context_t *context = (king_server_http3_request_header_context_t *) argp;
    king_server_http3_request_state_t *state = context->state;
    zend_string **slot = NULL;

    state->header_bytes += name_len + value_len;
    if (state->header_bytes > KING_SERVER_HTTP3_MAX_HEADER_BYTES) {
        king_server_local_set_errorf(
            "%s() received an HTTP/3 request whose headers exceed the active one-shot limit.",
            context->function_name
        );
        return -1;
    }

    if (name_len == sizeof(":method") - 1 && memcmp(name, ":method", sizeof(":method") - 1) == 0) {
        slot = &state->method;
    } else if (name_len == sizeof(":path") - 1 && memcmp(name, ":path", sizeof(":path") - 1) == 0) {
        slot = &state->path;
    } else if (name_len == sizeof(":scheme") - 1 && memcmp(name, ":scheme", sizeof(":scheme") - 1) == 0) {
        slot = &state->scheme;
    } else if (name_len == sizeof(":authority") - 1 && memcmp(name, ":authority", sizeof(":authority") - 1) == 0) {
        slot = &state->authority;
    }

    if (slot != NULL) {
        if (*slot != NULL) {
            zend_string_release(*slot);
        }
        *slot = zend_string_init((const char *) value, value_len, 0);
    }

    if (king_server_http3_add_header_value(
            &state->headers,
            (const char *) name,
            name_len,
            (const char *) value,
            value_len
        ) != SUCCESS) {
        king_server_local_set_errorf(
            "%s() failed to collect the active HTTP/3 request headers.",
            context->function_name
        );
        return -1;
    }

    return 0;
}

static zend_result king_server_http3_build_wire_request(
    zval *request,
    king_server_http3_request_state_t *state,
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port,
    const char *function_name
)
{
    zend_string *authority;
    const char *scheme = "https";

    if (state->method == NULL || state->path == NULL) {
        king_server_local_set_errorf(
            "%s() requires ':method' and ':path' pseudo headers on the on-wire HTTP/3 leaf.",
            function_name
        );
        return FAILURE;
    }

    authority = state->authority != NULL
        ? zend_string_copy(state->authority)
        : strpprintf(0, "%.*s:%ld", (int) host_len, host, port);
    if (state->scheme != NULL && ZSTR_LEN(state->scheme) > 0) {
        scheme = ZSTR_VAL(state->scheme);
    }

    array_init(request);
    add_assoc_str(request, "method", zend_string_copy(state->method));
    add_assoc_str(request, "uri", zend_string_copy(state->path));
    add_assoc_string(request, "version", "HTTP/3");
    add_assoc_str(request, "host", zend_string_copy(authority));
    add_assoc_zval(request, "headers", &state->headers);
    state->headers_initialized = false;

    if (state->body.s != NULL) {
        smart_str_0(&state->body);
        add_assoc_str(request, "body", state->body.s);
        state->body.s = NULL;
    } else {
        add_assoc_string(request, "body", "");
    }

    if (session->server_pending_request_target != NULL) {
        zend_string_release(session->server_pending_request_target);
    }
    session->server_pending_request_target = zend_string_copy(state->path);

    if (session->server_pending_websocket_key != NULL) {
        zend_string_release(session->server_pending_websocket_key);
        session->server_pending_websocket_key = NULL;
    }
    session->server_pending_websocket_upgrade = false;

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/3",
        scheme,
        (zend_long) state->stream_id
    );

    zend_string_release(authority);
    return SUCCESS;
}

static void king_server_http3_response_state_dtor(king_server_http3_response_state_t *state)
{
    size_t i;

    if (state->headers != NULL) {
        efree(state->headers);
    }

    if (state->owned_strings != NULL) {
        for (i = 0; i < state->owned_string_count; ++i) {
            zend_string_release(state->owned_strings[i]);
        }
        efree(state->owned_strings);
    }

    if (state->body != NULL) {
        zend_string_release(state->body);
    }

    memset(state, 0, sizeof(*state));
}

static zend_result king_server_http3_response_append_header(
    king_server_http3_response_state_t *state,
    size_t *index,
    zend_string *header_name,
    zval *header_value
)
{
    zend_string *normalized_name;
    zend_string *value_string;

    if (header_name == NULL || ZSTR_LEN(header_name) == 0) {
        return SUCCESS;
    }

    normalized_name = zend_string_tolower(header_name);
    if (ZSTR_LEN(normalized_name) == 0
        || ZSTR_VAL(normalized_name)[0] == ':'
        || zend_string_equals_literal(normalized_name, "connection")
        || zend_string_equals_literal(normalized_name, "content-length")) {
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    value_string = zval_get_string(header_value);
    state->headers[*index].name = (const uint8_t *) ZSTR_VAL(normalized_name);
    state->headers[*index].name_len = ZSTR_LEN(normalized_name);
    state->headers[*index].value = (const uint8_t *) ZSTR_VAL(value_string);
    state->headers[*index].value_len = ZSTR_LEN(value_string);
    state->owned_strings[state->owned_string_count++] = normalized_name;
    state->owned_strings[state->owned_string_count++] = value_string;
    (*index)++;
    return SUCCESS;
}

static zend_result king_server_http3_prepare_response_state(
    king_server_http3_response_state_t *state,
    zval *retval,
    uint64_t stream_id,
    const char *function_name
)
{
    zval *status_zv;
    zval *headers_zv;
    zval *body_zv;
    zend_long status = 200;
    zend_string *status_string;
    size_t header_count = 1;
    size_t index = 0;

    memset(state, 0, sizeof(*state));
    state->stream_id = stream_id;

    status_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "status", sizeof("status") - 1);
    headers_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "headers", sizeof("headers") - 1);
    body_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "body", sizeof("body") - 1);

    if (status_zv != NULL && Z_TYPE_P(status_zv) != IS_NULL) {
        status = zval_get_long(status_zv);
    }

    if (body_zv != NULL && Z_TYPE_P(body_zv) != IS_NULL) {
        state->body = zval_get_string(body_zv);
    } else {
        state->body = zend_string_init("", 0, 0);
    }

    if (headers_zv != NULL && Z_TYPE_P(headers_zv) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_zv), header_name, header_value)
        {
            if (header_name == NULL) {
                continue;
            }

            if (Z_TYPE_P(header_value) == IS_ARRAY) {
                zval *entry;

                ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
                {
                    header_count++;
                }
                ZEND_HASH_FOREACH_END();
            } else {
                header_count++;
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    state->headers = safe_emalloc(header_count, sizeof(*state->headers), 0);
    state->owned_strings = safe_emalloc(header_count * 2, sizeof(*state->owned_strings), 0);

    status_string = strpprintf(0, "%ld", status);
    state->headers[index].name = (const uint8_t *) ":status";
    state->headers[index].name_len = sizeof(":status") - 1;
    state->headers[index].value = (const uint8_t *) ZSTR_VAL(status_string);
    state->headers[index].value_len = ZSTR_LEN(status_string);
    state->owned_strings[state->owned_string_count++] = status_string;
    index++;

    if (headers_zv != NULL && Z_TYPE_P(headers_zv) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_zv), header_name, header_value)
        {
            if (header_name == NULL) {
                continue;
            }

            if (Z_TYPE_P(header_value) == IS_ARRAY) {
                zval *entry;

                ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
                {
                    if (king_server_http3_response_append_header(state, &index, header_name, entry) != SUCCESS) {
                        king_server_http3_response_state_dtor(state);
                        king_server_local_set_errorf(
                            "%s() failed to normalize the HTTP/3 response headers.",
                            function_name
                        );
                        return FAILURE;
                    }
                }
                ZEND_HASH_FOREACH_END();
            } else {
                if (king_server_http3_response_append_header(state, &index, header_name, header_value) != SUCCESS) {
                    king_server_http3_response_state_dtor(state);
                    king_server_local_set_errorf(
                        "%s() failed to normalize the HTTP/3 response headers.",
                        function_name
                    );
                    return FAILURE;
                }
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    state->headers_len = index;
    state->initialized = true;
    return SUCCESS;
}

static zend_result king_server_http3_flush_egress(
    king_server_http3_runtime_t *runtime,
    const char *function_name
)
{
    uint8_t out[KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE];
    quiche_send_info send_info;
    ssize_t written;

    if (runtime->conn == NULL || runtime->socket_fd < 0) {
        return SUCCESS;
    }

    while ((written = king_server_http3_quiche.quiche_conn_send_fn(
                runtime->conn,
                out,
                sizeof(out),
                &send_info
            )) != QUICHE_ERR_DONE) {
        ssize_t sent;

        if (written < 0) {
            king_server_local_set_errorf(
                "%s() failed while preparing an HTTP/3 datagram for send (%zd).",
                function_name,
                written
            );
            return FAILURE;
        }

        sent = sendto(
            runtime->socket_fd,
            out,
            (size_t) written,
            0,
            (struct sockaddr *) &send_info.to,
            send_info.to_len
        );
        if (sent < 0) {
            if (errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR) {
                continue;
            }

            king_server_local_set_errorf(
                "%s() failed to send the active HTTP/3 datagram (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        if (sent != written) {
            king_server_local_set_errorf(
                "%s() sent a truncated HTTP/3 datagram.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static int king_server_http3_try_send_response(
    king_server_http3_runtime_t *runtime,
    king_server_http3_response_state_t *response,
    const char *function_name
)
{
    int rc;

    if (!response->initialized) {
        return 1;
    }

    if (!response->headers_sent) {
        rc = king_server_http3_quiche.quiche_h3_send_response_fn(
            runtime->h3_conn,
            runtime->conn,
            response->stream_id,
            response->headers,
            response->headers_len,
            ZSTR_LEN(response->body) == 0
        );
        if (rc == 0) {
            response->headers_sent = true;
        } else if (rc == QUICHE_H3_ERR_DONE || rc == QUICHE_H3_ERR_STREAM_BLOCKED) {
            return 0;
        } else {
            king_server_local_set_errorf(
                "%s() failed to send the HTTP/3 response headers (%d).",
                function_name,
                rc
            );
            return -1;
        }
    }

    if (response->headers_sent && response->body_offset < ZSTR_LEN(response->body)) {
        ssize_t wrote = king_server_http3_quiche.quiche_h3_send_body_fn(
            runtime->h3_conn,
            runtime->conn,
            response->stream_id,
            (const uint8_t *) (ZSTR_VAL(response->body) + response->body_offset),
            ZSTR_LEN(response->body) - response->body_offset,
            true
        );
        if (wrote > 0) {
            response->body_offset += (size_t) wrote;
            return 0;
        }

        if (wrote == QUICHE_H3_ERR_DONE || wrote == QUICHE_H3_ERR_STREAM_BLOCKED) {
            return 0;
        }

        king_server_local_set_errorf(
            "%s() failed to send the HTTP/3 response body (%zd).",
            function_name,
            wrote
        );
        return -1;
    }

    if (response->headers_sent && response->body_offset == ZSTR_LEN(response->body) && !response->goaway_sent) {
        rc = king_server_http3_quiche.quiche_h3_send_goaway_fn(
            runtime->h3_conn,
            runtime->conn,
            response->stream_id
        );
        if (rc == 0) {
            response->goaway_sent = true;
        } else if (rc == QUICHE_H3_ERR_DONE || rc == QUICHE_H3_ERR_STREAM_BLOCKED) {
            return 0;
        } else {
            king_server_local_set_errorf(
                "%s() failed to send the HTTP/3 GOAWAY (%d).",
                function_name,
                rc
            );
            return -1;
        }
    }

    if (response->goaway_sent && !response->close_sent) {
        if (king_server_http3_quiche.quiche_conn_close_fn(runtime->conn, false, 0, NULL, 0) != 0) {
            king_server_local_set_errorf(
                "%s() failed to close the active QUIC connection after the response.",
                function_name
            );
            return -1;
        }

        response->close_sent = true;
        response->close_grace_deadline_ms = king_server_http3_now_ms() + KING_SERVER_HTTP3_CLOSE_GRACE_MS;
    }

    return 1;
}

static int king_server_http3_handle_first_packet(
    king_server_http3_runtime_t *runtime,
    king_client_session_t *session,
    const uint8_t *packet,
    size_t packet_len,
    const struct sockaddr *from,
    socklen_t from_len,
    const char *function_name
)
{
    uint32_t version = 0;
    uint8_t packet_type = 0;
    uint8_t scid[QUICHE_MAX_CONN_ID_LEN];
    size_t scid_len = sizeof(scid);
    uint8_t dcid[QUICHE_MAX_CONN_ID_LEN];
    size_t dcid_len = sizeof(dcid);
    uint8_t token[KING_MAX_TICKET_SIZE];
    size_t token_len = sizeof(token);
    uint8_t server_scid[QUICHE_MAX_CONN_ID_LEN];
    quiche_recv_info recv_info;
    ssize_t processed;

    if (king_server_http3_quiche.quiche_header_info_fn(
            packet,
            packet_len,
            QUICHE_MAX_CONN_ID_LEN,
            &version,
            &packet_type,
            scid,
            &scid_len,
            dcid,
            &dcid_len,
            token,
            &token_len
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to parse the first incoming QUIC packet.",
            function_name
        );
        return -1;
    }

    if (!king_server_http3_quiche.quiche_version_is_supported_fn(version)) {
        uint8_t out[KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE];
        ssize_t written = king_server_http3_quiche.quiche_negotiate_version_fn(
            scid,
            scid_len,
            dcid,
            dcid_len,
            out,
            sizeof(out)
        );

        if (written > 0) {
            (void) sendto(runtime->socket_fd, out, (size_t) written, 0, from, from_len);
        }

        return 0;
    }

    if (king_server_http3_fill_random_bytes(server_scid, sizeof(server_scid)) != SUCCESS) {
        king_server_local_set_errorf(
            "%s() failed to initialize an HTTP/3 source connection ID.",
            function_name
        );
        return -1;
    }

    runtime->conn = king_server_http3_quiche.quiche_accept_fn(
        server_scid,
        sizeof(server_scid),
        NULL,
        0,
        (struct sockaddr *) &runtime->local_addr,
        runtime->local_addr_len,
        from,
        from_len,
        runtime->config
    );
    if (runtime->conn == NULL) {
        king_server_local_set_errorf(
            "%s() failed to accept the active QUIC connection.",
            function_name
        );
        return -1;
    }

    memcpy(&runtime->peer_addr, from, from_len);
    runtime->peer_addr_len = from_len;

    if (connect(runtime->socket_fd, from, from_len) != 0) {
        king_server_local_set_errorf(
            "%s() failed to connect the accepted HTTP/3 UDP socket to the peer (errno %d).",
            function_name,
            errno
        );
        return -1;
    }

    if (
        king_server_http3_apply_transport_snapshot_from_socket(
            session,
            runtime->socket_fd,
            function_name
        ) != SUCCESS
    ) {
        return -1;
    }

    recv_info.from = (struct sockaddr *) from;
    recv_info.from_len = from_len;
    recv_info.to = (struct sockaddr *) &runtime->local_addr;
    recv_info.to_len = runtime->local_addr_len;

    processed = king_server_http3_quiche.quiche_conn_recv_fn(
        runtime->conn,
        (uint8_t *) packet,
        packet_len,
        &recv_info
    );
    if (processed < 0 && processed != QUICHE_ERR_DONE) {
        king_server_local_set_errorf(
            "%s() failed while processing the first incoming QUIC packet (%zd).",
            function_name,
            processed
        );
        return -1;
    }

    if (king_server_http3_flush_egress(runtime, function_name) != SUCCESS) {
        return -1;
    }

    return 1;
}

static zend_result king_server_http3_process_events(
    king_server_http3_runtime_t *runtime,
    king_server_http3_request_state_t *request_state,
    zend_bool *request_complete,
    const char *function_name
)
{
    for (;;) {
        quiche_h3_event *event = NULL;
        int64_t stream_id = king_server_http3_quiche.quiche_h3_conn_poll_fn(
            runtime->h3_conn,
            runtime->conn,
            &event
        );

        if (stream_id == QUICHE_H3_ERR_DONE) {
            break;
        }

        if (stream_id < 0) {
            king_server_local_set_errorf(
                "%s() failed while polling HTTP/3 server events (%" PRId64 ").",
                function_name,
                stream_id
            );
            return FAILURE;
        }

        switch (king_server_http3_quiche.quiche_h3_event_type_fn(event)) {
            case QUICHE_H3_EVENT_HEADERS: {
                king_server_http3_request_header_context_t context;
                int rc;

                if (!request_state->request_stream_seen) {
                    request_state->request_stream_seen = true;
                    request_state->stream_id = (uint64_t) stream_id;
                } else if (request_state->stream_id != (uint64_t) stream_id) {
                    king_server_http3_quiche.quiche_h3_event_free_fn(event);
                    king_server_local_set_errorf(
                        "%s() received multiple request streams on the one-shot HTTP/3 listener.",
                        function_name
                    );
                    return FAILURE;
                }

                context.state = request_state;
                context.function_name = function_name;
                rc = king_server_http3_quiche.quiche_h3_event_for_each_header_fn(
                    event,
                    king_server_http3_collect_request_header,
                    &context
                );
                if (rc != 0) {
                    king_server_http3_quiche.quiche_h3_event_free_fn(event);
                    return FAILURE;
                }

                if (!king_server_http3_quiche.quiche_h3_event_headers_has_more_frames_fn(event)) {
                    *request_complete = true;
                }

                break;
            }

            case QUICHE_H3_EVENT_DATA: {
                if (request_state->request_stream_seen && request_state->stream_id == (uint64_t) stream_id) {
                    for (;;) {
                        uint8_t buffer[4096];
                        ssize_t read = king_server_http3_quiche.quiche_h3_recv_body_fn(
                            runtime->h3_conn,
                            runtime->conn,
                            (uint64_t) stream_id,
                            buffer,
                            sizeof(buffer)
                        );

                        if (read == QUICHE_H3_ERR_DONE || read == 0) {
                            break;
                        }

                        if (read < 0) {
                            king_server_http3_quiche.quiche_h3_event_free_fn(event);
                            king_server_local_set_errorf(
                                "%s() failed while reading the active HTTP/3 request body (%zd).",
                                function_name,
                                read
                            );
                            return FAILURE;
                        }

                        request_state->body_bytes += (size_t) read;
                        if (request_state->body_bytes > KING_SERVER_HTTP3_MAX_REQUEST_BODY_BYTES) {
                            king_server_http3_quiche.quiche_h3_event_free_fn(event);
                            king_server_local_set_errorf(
                                "%s() received an HTTP/3 request body that exceeds the active one-shot limit.",
                                function_name
                            );
                            return FAILURE;
                        }

                        smart_str_appendl(&request_state->body, (const char *) buffer, (size_t) read);
                    }
                }

                break;
            }

            case QUICHE_H3_EVENT_FINISHED:
                if (request_state->request_stream_seen && request_state->stream_id == (uint64_t) stream_id) {
                    *request_complete = true;
                }
                break;

            case QUICHE_H3_EVENT_RESET:
                king_server_http3_quiche.quiche_h3_event_free_fn(event);
                king_server_local_set_errorf(
                    "%s() observed an HTTP/3 stream reset before the one-shot request completed.",
                    function_name
                );
                return FAILURE;

            case QUICHE_H3_EVENT_GOAWAY:
            case QUICHE_H3_EVENT_PRIORITY_UPDATE:
                break;
        }

        king_server_http3_quiche.quiche_h3_event_free_fn(event);
    }

    return SUCCESS;
}

PHP_FUNCTION(king_http3_server_listen_once)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session = NULL;
    king_server_http3_options_t options;
    king_server_http3_runtime_t runtime;
    king_server_http3_request_state_t request_state;
    king_server_http3_response_state_t response_state;
    zval request;
    zval retval;
    zend_bool request_complete = 0;
    zend_bool handler_invoked = 0;
    zend_bool rc = 0;
    zend_long deadline_ms;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    memset(&runtime, 0, sizeof(runtime));
    runtime.socket_fd = -1;
    request_state.headers_initialized = false;
    memset(&response_state, 0, sizeof(response_state));
    memset(&options, 0, sizeof(options));
    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    session = king_server_local_open_session(
        "king_http3_server_listen_once",
        host,
        host_len,
        port,
        config,
        3,
        "h3",
        "server_http3_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_http3_parse_options(
            config,
            &options,
            "king_http3_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (king_server_http3_ensure_quiche_ready() != SUCCESS) {
        king_server_local_set_errorf(
            "king_http3_server_listen_once() HTTP/3 runtime is unavailable: %s",
            king_server_http3_quiche.load_error
        );
        goto cleanup;
    }

    if (
        king_server_http3_open_listener_socket(
            host,
            port,
            &runtime,
            "king_http3_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http3_prepare_runtime_config(
            &runtime,
            &options,
            "king_http3_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    king_server_http3_request_state_init(&request_state);
    deadline_ms = king_server_http3_now_ms() + options.timeout_ms;

    while (king_server_http3_now_ms() < deadline_ms) {
        zend_long poll_timeout_ms;

        if (runtime.conn != NULL
            && runtime.h3_conn == NULL
            && king_server_http3_quiche.quiche_conn_is_established_fn(runtime.conn)) {
            runtime.h3_config = king_server_http3_quiche.quiche_h3_config_new_fn();
            if (runtime.h3_config == NULL) {
                king_server_local_set_errorf(
                    "king_http3_server_listen_once() failed to allocate the active HTTP/3 config.",
                    0
                );
                goto cleanup;
            }

            runtime.h3_conn = king_server_http3_quiche.quiche_h3_conn_new_with_transport_fn(
                runtime.conn,
                runtime.h3_config
            );
            if (runtime.h3_conn == NULL) {
                king_server_local_set_errorf(
                    "king_http3_server_listen_once() failed to create the active HTTP/3 connection.",
                    0
                );
                goto cleanup;
            }
        }

        if (runtime.h3_conn != NULL) {
            if (
                king_server_http3_process_events(
                    &runtime,
                    &request_state,
                    &request_complete,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }
        }

        if (request_complete && !handler_invoked) {
            if (
                king_server_http3_build_wire_request(
                    &request,
                    &request_state,
                    session,
                    host,
                    host_len,
                    port,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            if (
                king_server_local_invoke_handler(
                    handler,
                    &request,
                    &retval,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            if (
                king_server_local_validate_response(
                    &retval,
                    session,
                    "http/3",
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            if (
                king_server_http3_prepare_response_state(
                    &response_state,
                    &retval,
                    request_state.stream_id,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            handler_invoked = 1;
        }

        if (runtime.h3_conn != NULL && response_state.initialized) {
            int send_rc = king_server_http3_try_send_response(
                &runtime,
                &response_state,
                "king_http3_server_listen_once"
            );

            if (send_rc < 0) {
                goto cleanup;
            }

            if (king_server_http3_flush_egress(&runtime, "king_http3_server_listen_once") != SUCCESS) {
                goto cleanup;
            }

            if (response_state.close_sent) {
                if (king_server_http3_quiche.quiche_conn_is_closed_fn(runtime.conn)
                    || king_server_http3_now_ms() >= response_state.close_grace_deadline_ms) {
                    rc = 1;
                    break;
                }
            }
        }

        if (runtime.conn != NULL
            && king_server_http3_quiche.quiche_conn_is_closed_fn(runtime.conn)
            && !response_state.close_sent) {
            king_server_local_set_errorf(
                "king_http3_server_listen_once() saw the QUIC connection close before a complete response was sent."
            );
            goto cleanup;
        }

        poll_timeout_ms = deadline_ms - king_server_http3_now_ms();
        if (poll_timeout_ms > 50) {
            poll_timeout_ms = 50;
        }
        if (runtime.conn != NULL) {
            uint64_t quiche_timeout_ms = king_server_http3_quiche.quiche_conn_timeout_as_millis_fn(runtime.conn);

            if (quiche_timeout_ms < (uint64_t) poll_timeout_ms) {
                poll_timeout_ms = (zend_long) quiche_timeout_ms;
            }
        }
        if (poll_timeout_ms < 0) {
            poll_timeout_ms = 0;
        }

        {
            struct pollfd pfd;
            int poll_rc;

            memset(&pfd, 0, sizeof(pfd));
            pfd.fd = runtime.socket_fd;
            pfd.events = POLLIN;

            poll_rc = poll(&pfd, 1, (int) poll_timeout_ms);
            if (poll_rc < 0) {
                if (errno == EINTR) {
                    continue;
                }

                king_server_local_set_errorf(
                    "king_http3_server_listen_once() failed while polling the HTTP/3 listener socket (errno %d).",
                    errno
                );
                goto cleanup;
            }

            if (poll_rc == 0) {
                if (runtime.conn != NULL) {
                    king_server_http3_quiche.quiche_conn_on_timeout_fn(runtime.conn);
                    if (king_server_http3_flush_egress(&runtime, "king_http3_server_listen_once") != SUCCESS) {
                        goto cleanup;
                    }
                }
                continue;
            }

            if ((pfd.revents & POLLIN) != 0) {
                for (;;) {
                    uint8_t buffer[65535];
                    struct sockaddr_storage from;
                    socklen_t from_len = sizeof(from);
                    ssize_t received = recvfrom(
                        runtime.socket_fd,
                        buffer,
                        sizeof(buffer),
                        0,
                        (struct sockaddr *) &from,
                        &from_len
                    );

                    if (received < 0) {
                        if (errno == EAGAIN || errno == EWOULDBLOCK) {
                            break;
                        }

                        king_server_local_set_errorf(
                            "king_http3_server_listen_once() failed to receive the active UDP datagram (errno %d).",
                            errno
                        );
                        goto cleanup;
                    }

                    if (runtime.conn == NULL) {
                        int accept_rc = king_server_http3_handle_first_packet(
                            &runtime,
                            session,
                            buffer,
                            (size_t) received,
                            (struct sockaddr *) &from,
                            from_len,
                            "king_http3_server_listen_once"
                        );

                        if (accept_rc < 0) {
                            goto cleanup;
                        }

                        if (accept_rc == 0) {
                            continue;
                        }
                    } else {
                        quiche_recv_info recv_info;
                        ssize_t processed;

                        recv_info.from = (struct sockaddr *) &from;
                        recv_info.from_len = from_len;
                        recv_info.to = (struct sockaddr *) &runtime.local_addr;
                        recv_info.to_len = runtime.local_addr_len;

                        processed = king_server_http3_quiche.quiche_conn_recv_fn(
                            runtime.conn,
                            buffer,
                            (size_t) received,
                            &recv_info
                        );
                        if (processed < 0 && processed != QUICHE_ERR_DONE) {
                            king_server_local_set_errorf(
                                "king_http3_server_listen_once() failed while processing an incoming QUIC packet (%zd).",
                                processed
                            );
                            goto cleanup;
                        }
                    }

                    if (king_server_http3_flush_egress(&runtime, "king_http3_server_listen_once") != SUCCESS) {
                        goto cleanup;
                    }
                }
            }
        }
    }

    if (!rc) {
        king_server_local_set_errorf(
            "king_http3_server_listen_once() timed out before completing the active one-shot HTTP/3 request."
        );
    }

cleanup:
    if (session != NULL) {
        king_server_local_close_session(session);
        runtime.socket_fd = -1;
    }

    if (request_state.headers_initialized) {
        king_server_http3_request_state_dtor(&request_state);
    }

    if (response_state.initialized) {
        king_server_http3_response_state_dtor(&response_state);
    }

    if (!Z_ISUNDEF(request)) {
        zval_ptr_dtor(&request);
    }

    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }

    king_server_http3_options_destroy(&options);
    king_server_http3_runtime_destroy(&runtime);

    if (!rc) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}
