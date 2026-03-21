/*
 * =========================================================================
 * FILENAME:   src/client/http3.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Minimal live HTTP/3 client runtime for the active skeleton build. The
 * implementation keeps the extension free of a hard libquiche dependency by
 * loading the bundled/system libquiche at runtime, then driving a one-shot
 * HTTPS-over-QUIC request path with the existing King config snapshot and
 * normalized response contract.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/http3.h"
#include <quiche.h>
#include "include/config/config.h"
#include "include/config/quic_transport/base_layer.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/config/tls_and_crypto/base_layer.h"

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

typedef struct _king_http3_request_options {
    king_cfg_t *config;
    zend_long connect_timeout_ms;
    zend_long timeout_ms;
    bool tls_verify_peer;
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
    quiche_config *config;
    quiche_conn *conn;
    quiche_h3_config *h3_config;
    quiche_h3_conn *h3_conn;
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

typedef struct _king_http3_quiche_api {
    void *handle;
    bool load_attempted;
    bool ready;
    char load_error[KING_ERR_LEN];
    quiche_config *(*quiche_config_new_fn)(uint32_t);
    int (*quiche_config_load_cert_chain_from_pem_file_fn)(quiche_config *, const char *);
    int (*quiche_config_load_priv_key_from_pem_file_fn)(quiche_config *, const char *);
    int (*quiche_config_load_verify_locations_from_file_fn)(quiche_config *, const char *);
    void (*quiche_config_verify_peer_fn)(quiche_config *, bool);
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
    quiche_conn *(*quiche_connect_fn)(
        const char *,
        const uint8_t *,
        size_t,
        const struct sockaddr *,
        socklen_t,
        const struct sockaddr *,
        socklen_t,
        quiche_config *
    );
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
    int (*quiche_h3_event_for_each_header_fn)(
        quiche_h3_event *,
        int (*)(uint8_t *, size_t, uint8_t *, size_t, void *),
        void *
    );
    void (*quiche_h3_event_free_fn)(quiche_h3_event *);
    int64_t (*quiche_h3_send_request_fn)(quiche_h3_conn *, quiche_conn *, const quiche_h3_header *, size_t, bool);
    ssize_t (*quiche_h3_send_body_fn)(quiche_h3_conn *, quiche_conn *, uint64_t, const uint8_t *, size_t, bool);
    ssize_t (*quiche_h3_recv_body_fn)(quiche_h3_conn *, quiche_conn *, uint64_t, uint8_t *, size_t);
    void (*quiche_h3_conn_free_fn)(quiche_h3_conn *);
} king_http3_quiche_api_t;

static king_http3_quiche_api_t king_http3_quiche = {0};

static void king_http3_set_error(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static void king_http3_throw(
    zend_class_entry *ce,
    const char *format,
    ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
    zend_throw_exception_ex(
        ce != NULL ? ce : king_ce_exception,
        0,
        "%s",
        message
    );
}

static bool king_http3_is_token_char(unsigned char c)
{
    return isalnum(c)
        || c == '!'
        || c == '#'
        || c == '$'
        || c == '%'
        || c == '&'
        || c == '\''
        || c == '*'
        || c == '+'
        || c == '-'
        || c == '.'
        || c == '^'
        || c == '_'
        || c == '`'
        || c == '|'
        || c == '~';
}

static bool king_http3_string_has_crlf(const char *value, size_t value_len)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static zend_result king_http3_validate_method(
    const char *method_str,
    size_t method_len,
    const char *function_name)
{
    size_t i;

    if (method_len == 0) {
        king_http3_set_error("%s() requires a non-empty HTTP method.", function_name);
        return FAILURE;
    }

    for (i = 0; i < method_len; ++i) {
        if (!king_http3_is_token_char((unsigned char) method_str[i])) {
            king_http3_set_error(
                "%s() method contains invalid HTTP token characters.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http3_validate_positive_timeout(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_http3_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        king_http3_set_error(
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_http3_parse_options(
    zval *options_array,
    king_http3_request_options_t *options,
    const char *function_name)
{
    zval *option_value;

    options->config = NULL;
    options->connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms > 0
        ? king_tcp_transport_config.connect_timeout_ms
        : 5000;
    options->timeout_ms = KING_HTTP3_DEFAULT_TIMEOUT_MS;
    options->tls_verify_peer = king_tls_and_crypto_config.tls_verify_peer;
    options->tls_default_ca_file = king_tls_and_crypto_config.tls_default_ca_file;
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
    options->cancel_token = king_transport_cancel_token_from_options(options_array);
    options->cancel_function_name = king_transport_cancel_function_name_from_options(options_array);
    options->cancel_exception_ce = king_transport_cancel_exception_ce_from_options(options_array);

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connection_config",
        sizeof("connection_config") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        options->config = (king_cfg_t *) king_fetch_config(option_value);
        if (options->config == NULL) {
            king_http3_set_error(
                "%s() option 'connection_config' must be a King\\Config resource or object.",
                function_name
            );
            return FAILURE;
        }

        options->connect_timeout_ms = options->config->tcp.connect_timeout_ms > 0
            ? options->config->tcp.connect_timeout_ms
            : options->connect_timeout_ms;
        options->timeout_ms = options->connect_timeout_ms > KING_HTTP3_DEFAULT_TIMEOUT_MS
            ? options->connect_timeout_ms
            : KING_HTTP3_DEFAULT_TIMEOUT_MS;
        options->tls_verify_peer = options->config->tls.tls_verify_peer;
        options->tls_default_ca_file = options->config->tls.tls_default_ca_file;
        options->tls_default_cert_file = options->config->tls.tls_default_cert_file;
        options->tls_default_key_file = options->config->tls.tls_default_key_file;
        options->quic_cc_algorithm = options->config->quic.cc_algorithm;
        options->quic_cc_initial_cwnd_packets = options->config->quic.cc_initial_cwnd_packets;
        options->quic_cc_enable_hystart_plus_plus = options->config->quic.cc_enable_hystart_plus_plus;
        options->quic_pacing_enable = options->config->quic.pacing_enable;
        options->quic_max_ack_delay_ms = options->config->quic.max_ack_delay_ms;
        options->quic_ack_delay_exponent = options->config->quic.ack_delay_exponent;
        options->quic_initial_max_data = options->config->quic.initial_max_data;
        options->quic_initial_max_stream_data_bidi_local = options->config->quic.initial_max_stream_data_bidi_local;
        options->quic_initial_max_stream_data_bidi_remote = options->config->quic.initial_max_stream_data_bidi_remote;
        options->quic_initial_max_stream_data_uni = options->config->quic.initial_max_stream_data_uni;
        options->quic_initial_max_streams_bidi = options->config->quic.initial_max_streams_bidi;
        options->quic_initial_max_streams_uni = options->config->quic.initial_max_streams_uni;
        options->quic_active_connection_id_limit = options->config->quic.active_connection_id_limit;
        options->quic_grease_enable = options->config->quic.grease_enable;
        options->quic_datagrams_enable = options->config->quic.datagrams_enable;
        options->quic_dgram_recv_queue_len = options->config->quic.dgram_recv_queue_len;
        options->quic_dgram_send_queue_len = options->config->quic.dgram_send_queue_len;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connect_timeout_ms",
        sizeof("connect_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http3_validate_positive_timeout(
                option_value,
                "connect_timeout_ms",
                &options->connect_timeout_ms,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http3_validate_positive_timeout(
                option_value,
                "timeout_ms",
                &options->timeout_ms,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

static const char *king_http3_map_curless_candidate_error(const char *path)
{
    return path != NULL && path[0] != '\0' ? path : "unknown";
}

static zend_result king_http3_load_symbol(void **target, const char *name)
{
    *target = dlsym(king_http3_quiche.handle, name);
    if (*target == NULL) {
        snprintf(
            king_http3_quiche.load_error,
            sizeof(king_http3_quiche.load_error),
            "Failed to load libquiche symbol '%s'.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_http3_close_runtime_handle(void)
{
    if (king_http3_quiche.handle != NULL) {
        dlclose(king_http3_quiche.handle);
        king_http3_quiche.handle = NULL;
    }
}

static zend_result king_http3_ensure_quiche_ready(void)
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

    if (king_http3_quiche.ready) {
        return SUCCESS;
    }

    if (king_http3_quiche.load_attempted) {
        return FAILURE;
    }

    king_http3_quiche.load_attempted = true;

    if (env_path != NULL && env_path[0] != '\0') {
        king_http3_quiche.handle = dlopen(env_path, RTLD_LAZY | RTLD_LOCAL);
    }

    for (i = 1; king_http3_quiche.handle == NULL && candidates[i] != NULL; ++i) {
        king_http3_quiche.handle = dlopen(candidates[i], RTLD_LAZY | RTLD_LOCAL);
    }

    if (king_http3_quiche.handle == NULL) {
        snprintf(
            king_http3_quiche.load_error,
            sizeof(king_http3_quiche.load_error),
            "Failed to load libquiche for the active HTTP/3 runtime (checked '%s' first).",
            king_http3_map_curless_candidate_error(env_path)
        );
        return FAILURE;
    }

    if (king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_new_fn, "quiche_config_new") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_load_cert_chain_from_pem_file_fn, "quiche_config_load_cert_chain_from_pem_file") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_load_priv_key_from_pem_file_fn, "quiche_config_load_priv_key_from_pem_file") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_load_verify_locations_from_file_fn, "quiche_config_load_verify_locations_from_file") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_verify_peer_fn, "quiche_config_verify_peer") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_grease_fn, "quiche_config_grease") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_enable_hystart_fn, "quiche_config_enable_hystart") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_enable_pacing_fn, "quiche_config_enable_pacing") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_application_protos_fn, "quiche_config_set_application_protos") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_max_idle_timeout_fn, "quiche_config_set_max_idle_timeout") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_max_recv_udp_payload_size_fn, "quiche_config_set_max_recv_udp_payload_size") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_max_send_udp_payload_size_fn, "quiche_config_set_max_send_udp_payload_size") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_initial_max_data_fn, "quiche_config_set_initial_max_data") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_local_fn, "quiche_config_set_initial_max_stream_data_bidi_local") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_remote_fn, "quiche_config_set_initial_max_stream_data_bidi_remote") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_initial_max_stream_data_uni_fn, "quiche_config_set_initial_max_stream_data_uni") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_initial_max_streams_bidi_fn, "quiche_config_set_initial_max_streams_bidi") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_initial_max_streams_uni_fn, "quiche_config_set_initial_max_streams_uni") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_ack_delay_exponent_fn, "quiche_config_set_ack_delay_exponent") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_max_ack_delay_fn, "quiche_config_set_max_ack_delay") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_disable_active_migration_fn, "quiche_config_set_disable_active_migration") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_cc_algorithm_name_fn, "quiche_config_set_cc_algorithm_name") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_initial_congestion_window_packets_fn, "quiche_config_set_initial_congestion_window_packets") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_set_active_connection_id_limit_fn, "quiche_config_set_active_connection_id_limit") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_enable_dgram_fn, "quiche_config_enable_dgram") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_config_free_fn, "quiche_config_free") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_connect_fn, "quiche_connect") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_recv_fn, "quiche_conn_recv") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_send_fn, "quiche_conn_send") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_timeout_as_millis_fn, "quiche_conn_timeout_as_millis") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_on_timeout_fn, "quiche_conn_on_timeout") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_is_established_fn, "quiche_conn_is_established") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_is_closed_fn, "quiche_conn_is_closed") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_close_fn, "quiche_conn_close") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_conn_free_fn, "quiche_conn_free") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_config_new_fn, "quiche_h3_config_new") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_config_free_fn, "quiche_h3_config_free") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_conn_new_with_transport_fn, "quiche_h3_conn_new_with_transport") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_conn_poll_fn, "quiche_h3_conn_poll") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_event_type_fn, "quiche_h3_event_type") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_event_for_each_header_fn, "quiche_h3_event_for_each_header") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_event_free_fn, "quiche_h3_event_free") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_send_request_fn, "quiche_h3_send_request") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_send_body_fn, "quiche_h3_send_body") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_recv_body_fn, "quiche_h3_recv_body") != SUCCESS
        || king_http3_load_symbol((void **) &king_http3_quiche.quiche_h3_conn_free_fn, "quiche_h3_conn_free") != SUCCESS) {
        king_http3_close_runtime_handle();
        return FAILURE;
    }

    king_http3_quiche.ready = true;
    return SUCCESS;
}

static void king_http3_response_destroy(king_http3_response_t *response)
{
    if (response->headers_initialized) {
        zval_ptr_dtor(&response->headers);
    }
    if (response->status_line != NULL) {
        zend_string_release(response->status_line);
    }
    smart_str_free(&response->body);
}

static void king_http3_runtime_destroy(king_http3_request_runtime_t *runtime)
{
    if (runtime->h3_conn != NULL && king_http3_quiche.quiche_h3_conn_free_fn != NULL) {
        king_http3_quiche.quiche_h3_conn_free_fn(runtime->h3_conn);
    }
    if (runtime->h3_config != NULL && king_http3_quiche.quiche_h3_config_free_fn != NULL) {
        king_http3_quiche.quiche_h3_config_free_fn(runtime->h3_config);
    }
    if (runtime->conn != NULL && king_http3_quiche.quiche_conn_free_fn != NULL) {
        king_http3_quiche.quiche_conn_free_fn(runtime->conn);
    }
    if (runtime->config != NULL && king_http3_quiche.quiche_config_free_fn != NULL) {
        king_http3_quiche.quiche_config_free_fn(runtime->config);
    }
    if (runtime->socket_fd >= 0) {
        close(runtime->socket_fd);
    }
}

static zend_result king_http3_fill_random_bytes(uint8_t *target, size_t target_len)
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

static zend_long king_http3_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static zend_result king_http3_make_socket_nonblocking(int socket_fd)
{
    int flags = fcntl(socket_fd, F_GETFL, 0);

    if (flags < 0) {
        return FAILURE;
    }

    return fcntl(socket_fd, F_SETFL, flags | O_NONBLOCK) == 0
        ? SUCCESS
        : FAILURE;
}

static zend_result king_http3_add_header_value(
    zval *headers,
    const char *name,
    size_t name_len,
    const char *value,
    size_t value_len)
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
        add_assoc_stringl_ex(headers, ZSTR_VAL(normalized_name), ZSTR_LEN(normalized_name), (char *) value, value_len);
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

static int king_http3_collect_response_header(
    uint8_t *name,
    size_t name_len,
    uint8_t *value,
    size_t value_len,
    void *argp)
{
    king_http3_response_header_context_t *context = (king_http3_response_header_context_t *) argp;
    king_http3_response_t *response = context->response;

    response->header_bytes += name_len + value_len;
    if (response->header_bytes > KING_HTTP3_MAX_HEADER_BYTES) {
        response->header_overflowed = true;
        return -1;
    }

    if (name_len == sizeof(":status") - 1
        && memcmp(name, ":status", sizeof(":status") - 1) == 0) {
        char buffer[16];

        if (value_len >= sizeof(buffer)) {
            response->header_overflowed = true;
            return -1;
        }

        memcpy(buffer, value, value_len);
        buffer[value_len] = '\0';
        response->status_code = strtol(buffer, NULL, 10);
        return 0;
    }

    if (king_http3_add_header_value(
            &response->headers,
            (const char *) name,
            name_len,
            (const char *) value,
            value_len
        ) != SUCCESS) {
        response->header_overflowed = true;
        return -1;
    }

    return 0;
}

static zend_result king_http3_request_target_from_url(
    const char *url_str,
    size_t url_len,
    php_url **parsed_url_out,
    king_http3_request_target_t *target,
    const char *function_name)
{
    php_url *parsed_url;
    smart_str authority = {0};
    smart_str path = {0};
    bool bracket_host = false;

    memset(target, 0, sizeof(*target));

    parsed_url = php_url_parse_ex(url_str, url_len);
    if (parsed_url == NULL) {
        king_http3_set_error("%s() requires a valid absolute HTTPS URL.", function_name);
        return FAILURE;
    }

    if (parsed_url->scheme == NULL
        || strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") != 0) {
        php_url_free(parsed_url);
        king_http3_set_error("%s() requires an absolute https:// URL for the active HTTP/3 runtime.", function_name);
        return FAILURE;
    }

    if (parsed_url->host == NULL || ZSTR_LEN(parsed_url->host) == 0) {
        php_url_free(parsed_url);
        king_http3_set_error("%s() requires a host component in the HTTPS URL.", function_name);
        return FAILURE;
    }

    target->port = parsed_url->port > 0 ? parsed_url->port : 443;
    target->secure_transport = true;

    target->host = zend_string_copy(parsed_url->host);

    bracket_host = strchr(ZSTR_VAL(parsed_url->host), ':') != NULL;
    if (bracket_host) {
        smart_str_appends(&authority, "[");
    }
    smart_str_appends(&authority, ZSTR_VAL(parsed_url->host));
    if (bracket_host) {
        smart_str_appends(&authority, "]");
    }
    if (target->port != 443) {
        smart_str_appendc(&authority, ':');
        smart_str_append_long(&authority, target->port);
    }
    smart_str_0(&authority);
    target->authority = authority.s;

    if (parsed_url->path != NULL && ZSTR_LEN(parsed_url->path) > 0) {
        smart_str_appends(&path, ZSTR_VAL(parsed_url->path));
    } else {
        smart_str_appends(&path, "/");
    }
    if (parsed_url->query != NULL && ZSTR_LEN(parsed_url->query) > 0) {
        smart_str_appendc(&path, '?');
        smart_str_appends(&path, ZSTR_VAL(parsed_url->query));
    }
    smart_str_0(&path);
    target->path = path.s;

    *parsed_url_out = parsed_url;
    return SUCCESS;
}

static void king_http3_request_target_destroy(
    php_url *parsed_url,
    king_http3_request_target_t *target)
{
    if (parsed_url != NULL) {
        php_url_free(parsed_url);
    }
    if (target->authority != NULL) {
        zend_string_release(target->authority);
    }
    if (target->host != NULL) {
        zend_string_release(target->host);
    }
    if (target->path != NULL) {
        zend_string_release(target->path);
    }
}

static zend_result king_http3_runtime_init(
    king_http3_request_runtime_t *runtime,
    const king_http3_request_target_t *target,
    const king_http3_request_options_t *options,
    const char *function_name)
{
    struct addrinfo hints;
    struct addrinfo *result = NULL;
    struct addrinfo *candidate;
    char port_buffer[16];
    uint8_t scid[QUICHE_MAX_CONN_ID_LEN];

    memset(runtime, 0, sizeof(*runtime));
    runtime->socket_fd = -1;

    snprintf(port_buffer, sizeof(port_buffer), "%ld", options->config != NULL ? target->port : target->port);

    memset(&hints, 0, sizeof(hints));
    hints.ai_socktype = SOCK_DGRAM;
    hints.ai_family = AF_UNSPEC;

    if (getaddrinfo(ZSTR_VAL(target->host), port_buffer, &hints, &result) != 0) {
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to resolve the active HTTP/3 endpoint '%s'.",
            function_name,
            ZSTR_VAL(target->host)
        );
        return FAILURE;
    }

    for (candidate = result; candidate != NULL; candidate = candidate->ai_next) {
        runtime->socket_fd = socket(candidate->ai_family, candidate->ai_socktype, candidate->ai_protocol);
        if (runtime->socket_fd >= 0) {
            memcpy(&runtime->peer_addr, candidate->ai_addr, candidate->ai_addrlen);
            runtime->peer_addr_len = (socklen_t) candidate->ai_addrlen;
            break;
        }
    }

    if (runtime->socket_fd < 0) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to open a UDP socket for the active HTTP/3 runtime.",
            function_name
        );
        return FAILURE;
    }

    if (king_http3_make_socket_nonblocking(runtime->socket_fd) != SUCCESS) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to switch the active HTTP/3 socket into non-blocking mode.",
            function_name
        );
        return FAILURE;
    }

    if (candidate->ai_family == AF_INET) {
        struct sockaddr_in local4;

        memset(&local4, 0, sizeof(local4));
        local4.sin_family = AF_INET;
        local4.sin_addr.s_addr = htonl(INADDR_ANY);
        local4.sin_port = htons(0);

        if (bind(runtime->socket_fd, (struct sockaddr *) &local4, sizeof(local4)) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed to bind the active HTTP/3 UDP socket.",
                function_name
            );
            return FAILURE;
        }
    } else if (candidate->ai_family == AF_INET6) {
        struct sockaddr_in6 local6;

        memset(&local6, 0, sizeof(local6));
        local6.sin6_family = AF_INET6;
        local6.sin6_addr = in6addr_any;
        local6.sin6_port = htons(0);

        if (bind(runtime->socket_fd, (struct sockaddr *) &local6, sizeof(local6)) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed to bind the active HTTP/3 UDP socket.",
                function_name
            );
            return FAILURE;
        }
    } else {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() resolved the active HTTP/3 endpoint to an unsupported socket family.",
            function_name
        );
        return FAILURE;
    }

    runtime->local_addr_len = sizeof(runtime->local_addr);
    if (getsockname(
            runtime->socket_fd,
            (struct sockaddr *) &runtime->local_addr,
            &runtime->local_addr_len
        ) != 0) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to inspect the local HTTP/3 UDP socket binding.",
            function_name
        );
        return FAILURE;
    }

    runtime->config = king_http3_quiche.quiche_config_new_fn(QUICHE_PROTOCOL_VERSION);
    if (runtime->config == NULL) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_system_exception,
            "%s() failed to allocate the active libquiche config.",
            function_name
        );
        return FAILURE;
    }

    if (options->tls_verify_peer && options->tls_default_ca_file != NULL && options->tls_default_ca_file[0] != '\0') {
        if (king_http3_quiche.quiche_config_load_verify_locations_from_file_fn(
                runtime->config,
                options->tls_default_ca_file
            ) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_tls_exception,
                "%s() failed to load the active HTTP/3 CA file '%s'.",
                function_name,
                options->tls_default_ca_file
            );
            return FAILURE;
        }
    }

    if (options->tls_default_cert_file != NULL
        && options->tls_default_cert_file[0] != '\0'
        && options->tls_default_key_file != NULL
        && options->tls_default_key_file[0] != '\0') {
        if (king_http3_quiche.quiche_config_load_cert_chain_from_pem_file_fn(
                runtime->config,
                options->tls_default_cert_file
            ) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_tls_exception,
                "%s() failed to load the active HTTP/3 client certificate '%s'.",
                function_name,
                options->tls_default_cert_file
            );
            return FAILURE;
        }

        if (king_http3_quiche.quiche_config_load_priv_key_from_pem_file_fn(
                runtime->config,
                options->tls_default_key_file
            ) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_tls_exception,
                "%s() failed to load the active HTTP/3 client key '%s'.",
                function_name,
                options->tls_default_key_file
            );
            return FAILURE;
        }
    }

    king_http3_quiche.quiche_config_verify_peer_fn(runtime->config, options->tls_verify_peer);
    king_http3_quiche.quiche_config_grease_fn(runtime->config, options->quic_grease_enable);
    king_http3_quiche.quiche_config_enable_hystart_fn(runtime->config, options->quic_cc_enable_hystart_plus_plus);
    king_http3_quiche.quiche_config_enable_pacing_fn(runtime->config, options->quic_pacing_enable);

    if (king_http3_quiche.quiche_config_set_application_protos_fn(
            runtime->config,
            (const uint8_t *) QUICHE_H3_APPLICATION_PROTOCOL,
            sizeof(QUICHE_H3_APPLICATION_PROTOCOL) - 1
        ) != 0) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() failed to configure the active HTTP/3 ALPN list.",
            function_name
        );
        return FAILURE;
    }

    king_http3_quiche.quiche_config_set_max_idle_timeout_fn(runtime->config, (uint64_t) options->timeout_ms);
    king_http3_quiche.quiche_config_set_max_recv_udp_payload_size_fn(runtime->config, KING_HTTP3_MAX_DATAGRAM_SIZE);
    king_http3_quiche.quiche_config_set_max_send_udp_payload_size_fn(runtime->config, KING_HTTP3_MAX_DATAGRAM_SIZE);
    king_http3_quiche.quiche_config_set_initial_max_data_fn(runtime->config, (uint64_t) options->quic_initial_max_data);
    king_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_local_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_local);
    king_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_remote_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_remote);
    king_http3_quiche.quiche_config_set_initial_max_stream_data_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_uni);
    king_http3_quiche.quiche_config_set_initial_max_streams_bidi_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_bidi);
    king_http3_quiche.quiche_config_set_initial_max_streams_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_uni);
    king_http3_quiche.quiche_config_set_ack_delay_exponent_fn(runtime->config, (uint64_t) options->quic_ack_delay_exponent);
    king_http3_quiche.quiche_config_set_max_ack_delay_fn(runtime->config, (uint64_t) options->quic_max_ack_delay_ms);
    king_http3_quiche.quiche_config_set_disable_active_migration_fn(runtime->config, true);
    king_http3_quiche.quiche_config_set_initial_congestion_window_packets_fn(runtime->config, (size_t) options->quic_cc_initial_cwnd_packets);
    king_http3_quiche.quiche_config_set_active_connection_id_limit_fn(runtime->config, (uint64_t) options->quic_active_connection_id_limit);
    king_http3_quiche.quiche_config_enable_dgram_fn(
        runtime->config,
        options->quic_datagrams_enable,
        (size_t) options->quic_dgram_recv_queue_len,
        (size_t) options->quic_dgram_send_queue_len
    );

    if (options->quic_cc_algorithm != NULL && options->quic_cc_algorithm[0] != '\0') {
        if (king_http3_quiche.quiche_config_set_cc_algorithm_name_fn(runtime->config, options->quic_cc_algorithm) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_validation_exception,
                "%s() received an unsupported QUIC congestion-control algorithm '%s'.",
                function_name,
                options->quic_cc_algorithm
            );
            return FAILURE;
        }
    }

    if (king_http3_fill_random_bytes(scid, sizeof(scid)) != SUCCESS) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_system_exception,
            "%s() failed to initialize a QUIC source connection ID.",
            function_name
        );
        return FAILURE;
    }

    runtime->conn = king_http3_quiche.quiche_connect_fn(
        ZSTR_VAL(target->host),
        scid,
        sizeof(scid),
        (struct sockaddr *) &runtime->local_addr,
        runtime->local_addr_len,
        (struct sockaddr *) &runtime->peer_addr,
        runtime->peer_addr_len,
        runtime->config
    );
    freeaddrinfo(result);

    if (runtime->conn == NULL) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() failed to create the active QUIC connection.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_http3_flush_egress(
    king_http3_request_runtime_t *runtime,
    const char *function_name)
{
    uint8_t out[KING_HTTP3_MAX_DATAGRAM_SIZE];
    quiche_send_info send_info;
    ssize_t written;

    while ((written = king_http3_quiche.quiche_conn_send_fn(
                runtime->conn,
                out,
                sizeof(out),
                &send_info
            )) != QUICHE_ERR_DONE) {
        ssize_t sent;

        if (written < 0) {
            king_http3_throw(
                king_ce_quic_exception,
                "%s() failed to create an outgoing QUIC datagram (%zd).",
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
            if (errno == EAGAIN || errno == EWOULDBLOCK) {
                struct pollfd pfd;
                int poll_result;

                memset(&pfd, 0, sizeof(pfd));
                pfd.fd = runtime->socket_fd;
                pfd.events = POLLOUT;
                poll_result = poll(&pfd, 1, 50);
                if (poll_result >= 0) {
                    continue;
                }
            }

            king_http3_throw(
                king_ce_network_exception,
                "%s() failed while sending an outgoing QUIC datagram (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        if (sent != written) {
            king_http3_throw(
                king_ce_network_exception,
                "%s() sent a short QUIC datagram on the active UDP socket.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http3_prepare_request_headers(
    const king_http3_request_target_t *target,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    quiche_h3_header **headers_out,
    size_t *header_count_out,
    zend_string ***owned_strings_out,
    size_t *owned_string_count_out,
    const char *function_name)
{
    size_t header_count = 4;
    size_t owned_count = 0;
    quiche_h3_header *headers;
    zend_string **owned_strings;
    size_t index = 0;
    zend_string *value_string;

    if (headers_array != NULL && Z_TYPE_P(headers_array) == IS_ARRAY) {
        header_count += zend_hash_num_elements(Z_ARRVAL_P(headers_array));
    }

    headers = safe_emalloc(header_count, sizeof(*headers), 0);
    owned_strings = safe_emalloc(header_count * 2, sizeof(*owned_strings), 0);

    headers[index].name = (const uint8_t *) ":method";
    headers[index].name_len = sizeof(":method") - 1;
    headers[index].value = (const uint8_t *) method_str;
    headers[index].value_len = method_len;
    index++;

    headers[index].name = (const uint8_t *) ":scheme";
    headers[index].name_len = sizeof(":scheme") - 1;
    headers[index].value = (const uint8_t *) "https";
    headers[index].value_len = sizeof("https") - 1;
    index++;

    headers[index].name = (const uint8_t *) ":authority";
    headers[index].name_len = sizeof(":authority") - 1;
    headers[index].value = (const uint8_t *) ZSTR_VAL(target->authority);
    headers[index].value_len = ZSTR_LEN(target->authority);
    index++;

    headers[index].name = (const uint8_t *) ":path";
    headers[index].name_len = sizeof(":path") - 1;
    headers[index].value = (const uint8_t *) ZSTR_VAL(target->path);
    headers[index].value_len = ZSTR_LEN(target->path);
    index++;

    if (headers_array != NULL && Z_TYPE_P(headers_array) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_array), header_name, header_value) {
            zend_string *normalized_name;

            if (header_name == NULL) {
                king_http3_set_error(
                    "%s() requires request headers to use string keys.",
                    function_name
                );
                goto failure;
            }

            normalized_name = zend_string_tolower(header_name);
            if (ZSTR_LEN(normalized_name) == 0) {
                zend_string_release(normalized_name);
                king_http3_set_error(
                    "%s() requires non-empty request header names.",
                    function_name
                );
                goto failure;
            }

            if (ZSTR_VAL(normalized_name)[0] == ':') {
                zend_string_release(normalized_name);
                king_http3_set_error(
                    "%s() does not allow overriding HTTP/3 pseudo headers.",
                    function_name
                );
                goto failure;
            }

            for (size_t i = 0; i < ZSTR_LEN(normalized_name); ++i) {
                if (!king_http3_is_token_char((unsigned char) ZSTR_VAL(normalized_name)[i])) {
                    zend_string_release(normalized_name);
                    king_http3_set_error(
                        "%s() request header '%s' contains invalid HTTP token characters.",
                        function_name,
                        ZSTR_VAL(header_name)
                    );
                    goto failure;
                }
            }

            if (zend_string_equals_literal(normalized_name, "host")) {
                zend_string_release(normalized_name);
                continue;
            }

            value_string = zval_get_string(header_value);
            if (king_http3_string_has_crlf(ZSTR_VAL(value_string), ZSTR_LEN(value_string))) {
                zend_string_release(normalized_name);
                zend_string_release(value_string);
                king_http3_set_error(
                    "%s() request header '%s' contains CRLF characters.",
                    function_name,
                    ZSTR_VAL(header_name)
                );
                goto failure;
            }

            headers[index].name = (const uint8_t *) ZSTR_VAL(normalized_name);
            headers[index].name_len = ZSTR_LEN(normalized_name);
            headers[index].value = (const uint8_t *) ZSTR_VAL(value_string);
            headers[index].value_len = ZSTR_LEN(value_string);
            owned_strings[owned_count++] = normalized_name;
            owned_strings[owned_count++] = value_string;
            index++;
        } ZEND_HASH_FOREACH_END();
    }

    *headers_out = headers;
    *header_count_out = index;
    *owned_strings_out = owned_strings;
    *owned_string_count_out = owned_count;
    return SUCCESS;

failure:
    for (size_t i = 0; i < owned_count; ++i) {
        zend_string_release(owned_strings[i]);
    }
    efree(owned_strings);
    efree(headers);
    return FAILURE;
}

static void king_http3_free_request_headers(
    quiche_h3_header *headers,
    zend_string **owned_strings,
    size_t owned_string_count)
{
    size_t i;

    if (owned_strings != NULL) {
        for (i = 0; i < owned_string_count; ++i) {
            zend_string_release(owned_strings[i]);
        }
        efree(owned_strings);
    }

    if (headers != NULL) {
        efree(headers);
    }
}

static zend_result king_http3_materialize_response(
    zval *return_value,
    king_http3_response_t *response,
    const char *url_str)
{
    char status_line_buffer[64];

    if (response->status_code <= 0) {
        king_http3_throw(
            king_ce_protocol_exception,
            "king_http3_request_send() did not receive a valid HTTP/3 status code."
        );
        return FAILURE;
    }

    smart_str_0(&response->body);

    snprintf(status_line_buffer, sizeof(status_line_buffer), "HTTP/3 %ld", response->status_code);
    response->status_line = zend_string_init(status_line_buffer, strlen(status_line_buffer), 0);

    array_init(return_value);
    add_assoc_long(return_value, "status", response->status_code);
    add_assoc_str(return_value, "status_line", response->status_line);
    response->status_line = NULL;
    add_assoc_zval(return_value, "headers", &response->headers);
    response->headers_initialized = false;
    add_assoc_stringl(
        return_value,
        "body",
        response->body.s != NULL ? ZSTR_VAL(response->body.s) : "",
        response->body.s != NULL ? ZSTR_LEN(response->body.s) : 0
    );
    add_assoc_string(return_value, "protocol", "http/3");
    add_assoc_string(return_value, "transport_backend", "quiche_h3");
    add_assoc_string(return_value, "effective_url", (char *) url_str);
    add_assoc_bool(return_value, "response_complete", response->response_complete ? 1 : 0);
    add_assoc_long(return_value, "body_bytes", (zend_long) response->body_bytes);
    add_assoc_long(return_value, "header_bytes", (zend_long) response->header_bytes);
    add_assoc_string(return_value, "stream_kind", "request");

    return SUCCESS;
}

static zend_result king_http3_execute_request(
    zval *return_value,
    const char *url_str,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zend_string *body_string,
    const king_http3_request_options_t *options,
    const char *function_name)
{
    php_url *parsed_url = NULL;
    king_http3_request_target_t target;
    king_http3_request_runtime_t runtime;
    king_http3_response_t response;
    quiche_h3_header *request_headers = NULL;
    zend_string **owned_strings = NULL;
    size_t request_header_count = 0;
    size_t owned_string_count = 0;
    zend_long started_at_ms;
    zend_long connect_deadline_ms;
    zend_long overall_deadline_ms;
    uint64_t request_stream_id = UINT64_MAX;
    size_t body_offset = 0;
    bool request_headers_sent = false;

    memset(&target, 0, sizeof(target));
    memset(&runtime, 0, sizeof(runtime));
    runtime.socket_fd = -1;
    memset(&response, 0, sizeof(response));
    array_init(&response.headers);
    response.headers_initialized = true;

    if (king_http3_request_target_from_url(
            url_str,
            strlen(url_str),
            &parsed_url,
            &target,
            function_name
        ) != SUCCESS) {
        goto failure;
    }

    if (king_http3_runtime_init(&runtime, &target, options, function_name) != SUCCESS) {
        goto failure;
    }

    if (king_http3_prepare_request_headers(
            &target,
            method_str,
            method_len,
            headers_array,
            &request_headers,
            &request_header_count,
            &owned_strings,
            &owned_string_count,
            function_name
        ) != SUCCESS) {
        if (EG(exception) == NULL && king_get_error()[0] != '\0') {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", king_get_error());
        }
        goto failure;
    }

    started_at_ms = king_http3_now_ms();
    connect_deadline_ms = started_at_ms + options->connect_timeout_ms;
    overall_deadline_ms = started_at_ms + options->timeout_ms;

    if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
        goto failure;
    }

    while (!response.response_complete) {
        struct pollfd pfd;
        zend_long now_ms = king_http3_now_ms();
        zend_long remaining_overall_ms;
        zend_long remaining_connect_ms;
        zend_long poll_timeout_ms;
        int poll_result;
        uint64_t quiche_timeout_ms;

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            goto failure;
        }
        if (king_transport_maybe_throw_cancel(
                options->cancel_token,
                function_name,
                options->cancel_function_name,
                options->cancel_exception_ce,
                "HTTP/3"
            ) != SUCCESS) {
            goto failure;
        }

        if (now_ms >= overall_deadline_ms) {
            king_http3_throw(
                king_ce_timeout_exception,
                "%s() timed out while waiting for the HTTP/3 response.",
                function_name
            );
            goto failure;
        }

        if (!king_http3_quiche.quiche_conn_is_established_fn(runtime.conn)
            && now_ms >= connect_deadline_ms) {
            king_http3_throw(
                king_ce_timeout_exception,
                "%s() timed out while establishing the QUIC connection.",
                function_name
            );
            goto failure;
        }

        if (king_http3_quiche.quiche_conn_is_established_fn(runtime.conn) && runtime.h3_conn == NULL) {
            runtime.h3_config = king_http3_quiche.quiche_h3_config_new_fn();
            if (runtime.h3_config == NULL) {
                king_http3_throw(
                    king_ce_system_exception,
                    "%s() failed to allocate the active HTTP/3 config.",
                    function_name
                );
                goto failure;
            }

            runtime.h3_conn = king_http3_quiche.quiche_h3_conn_new_with_transport_fn(runtime.conn, runtime.h3_config);
            if (runtime.h3_conn == NULL) {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() failed to initialize the HTTP/3 layer on the active QUIC connection.",
                    function_name
                );
                goto failure;
            }
        }

        if (runtime.h3_conn != NULL && !request_headers_sent) {
            int64_t stream_id = king_http3_quiche.quiche_h3_send_request_fn(
                runtime.h3_conn,
                runtime.conn,
                request_headers,
                request_header_count,
                body_string == NULL || ZSTR_LEN(body_string) == 0
            );
            if (stream_id < 0) {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() failed to send the active HTTP/3 request headers (%" PRId64 ").",
                    function_name,
                    stream_id
                );
                goto failure;
            }

            request_stream_id = (uint64_t) stream_id;
            request_headers_sent = true;

            if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
                goto failure;
            }
        }

        if (runtime.h3_conn != NULL
            && request_headers_sent
            && body_string != NULL
            && body_offset < ZSTR_LEN(body_string)) {
            ssize_t wrote = king_http3_quiche.quiche_h3_send_body_fn(
                runtime.h3_conn,
                runtime.conn,
                request_stream_id,
                (const uint8_t *) (ZSTR_VAL(body_string) + body_offset),
                ZSTR_LEN(body_string) - body_offset,
                true
            );

            if (wrote > 0) {
                body_offset += (size_t) wrote;
                if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
                    goto failure;
                }
            } else if (wrote != QUICHE_H3_ERR_DONE && wrote != QUICHE_H3_ERR_STREAM_BLOCKED) {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() failed while sending the active HTTP/3 request body (%zd).",
                    function_name,
                    wrote
                );
                goto failure;
            }
        }

        if (runtime.h3_conn != NULL) {
            for (;;) {
                quiche_h3_event *event = NULL;
                int64_t stream_id = king_http3_quiche.quiche_h3_conn_poll_fn(
                    runtime.h3_conn,
                    runtime.conn,
                    &event
                );

                if (stream_id == QUICHE_H3_ERR_DONE) {
                    break;
                }

                if (stream_id < 0) {
                    king_http3_throw(
                        king_ce_protocol_exception,
                        "%s() failed while polling the active HTTP/3 layer (%" PRId64 ").",
                        function_name,
                        stream_id
                    );
                    if (event != NULL) {
                        king_http3_quiche.quiche_h3_event_free_fn(event);
                    }
                    goto failure;
                }

                if ((uint64_t) stream_id != request_stream_id) {
                    if (event != NULL) {
                        king_http3_quiche.quiche_h3_event_free_fn(event);
                    }
                    continue;
                }

                switch (king_http3_quiche.quiche_h3_event_type_fn(event)) {
                    case QUICHE_H3_EVENT_HEADERS: {
                        king_http3_response_header_context_t context;
                        int rc;

                        context.response = &response;
                        rc = king_http3_quiche.quiche_h3_event_for_each_header_fn(
                            event,
                            king_http3_collect_response_header,
                            &context
                        );
                        if (rc != 0) {
                            king_http3_quiche.quiche_h3_event_free_fn(event);
                            if (response.header_overflowed) {
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() exceeded the active HTTP/3 response-header size limit.",
                                    function_name
                                );
                            } else {
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() failed while decoding the active HTTP/3 response headers.",
                                    function_name
                                );
                            }
                            goto failure;
                        }
                        break;
                    }

                    case QUICHE_H3_EVENT_DATA: {
                        uint8_t buffer[4096];

                        for (;;) {
                            ssize_t read = king_http3_quiche.quiche_h3_recv_body_fn(
                                runtime.h3_conn,
                                runtime.conn,
                                request_stream_id,
                                buffer,
                                sizeof(buffer)
                            );

                            if (read == QUICHE_H3_ERR_DONE || read == 0) {
                                break;
                            }

                            if (read < 0) {
                                king_http3_quiche.quiche_h3_event_free_fn(event);
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() failed while decoding the active HTTP/3 response body (%zd).",
                                    function_name,
                                    read
                                );
                                goto failure;
                            }

                            response.body_bytes += (size_t) read;
                            if (response.body_bytes > KING_HTTP3_MAX_RESPONSE_BYTES) {
                                response.body_overflowed = true;
                                king_http3_quiche.quiche_h3_event_free_fn(event);
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() exceeded the active HTTP/3 response-body size limit.",
                                    function_name
                                );
                                goto failure;
                            }

                            smart_str_appendl(&response.body, (const char *) buffer, (size_t) read);
                        }
                        break;
                    }

                    case QUICHE_H3_EVENT_FINISHED:
                        response.response_complete = true;
                        break;

                    case QUICHE_H3_EVENT_RESET:
                        king_http3_quiche.quiche_h3_event_free_fn(event);
                        king_http3_throw(
                            king_ce_protocol_exception,
                            "%s() received an HTTP/3 stream reset for the active request.",
                            function_name
                        );
                        goto failure;

                    case QUICHE_H3_EVENT_GOAWAY:
                    case QUICHE_H3_EVENT_PRIORITY_UPDATE:
                    default:
                        break;
                }

                if (event != NULL) {
                    king_http3_quiche.quiche_h3_event_free_fn(event);
                }
            }
        }

        if (response.response_complete) {
            break;
        }

        if (king_http3_quiche.quiche_conn_is_closed_fn(runtime.conn)) {
            king_http3_throw(
                king_ce_quic_exception,
                "%s() observed a closed QUIC connection before the HTTP/3 response completed.",
                function_name
            );
            goto failure;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = runtime.socket_fd;
        pfd.events = POLLIN;

        remaining_overall_ms = overall_deadline_ms - now_ms;
        remaining_connect_ms = connect_deadline_ms - now_ms;
        quiche_timeout_ms = king_http3_quiche.quiche_conn_timeout_as_millis_fn(runtime.conn);
        poll_timeout_ms = (zend_long) quiche_timeout_ms;
        if (poll_timeout_ms <= 0 || poll_timeout_ms > remaining_overall_ms) {
            poll_timeout_ms = remaining_overall_ms;
        }
        if (!king_http3_quiche.quiche_conn_is_established_fn(runtime.conn)
            && remaining_connect_ms > 0
            && poll_timeout_ms > remaining_connect_ms) {
            poll_timeout_ms = remaining_connect_ms;
        }
        if (poll_timeout_ms < 0) {
            poll_timeout_ms = 0;
        }
        if (poll_timeout_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS) {
            poll_timeout_ms = KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }

        poll_result = poll(&pfd, 1, (int) poll_timeout_ms);
        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed while polling the active HTTP/3 UDP socket (errno %d).",
                function_name,
                errno
            );
            goto failure;
        }

        if (poll_result == 0) {
            king_http3_quiche.quiche_conn_on_timeout_fn(runtime.conn);
            if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
                goto failure;
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

                    king_http3_throw(
                        king_ce_network_exception,
                        "%s() failed while receiving an HTTP/3 datagram (errno %d).",
                        function_name,
                        errno
                    );
                    goto failure;
                }

                {
                    quiche_recv_info recv_info;
                    ssize_t done;

                    recv_info.from = (struct sockaddr *) &from;
                    recv_info.from_len = from_len;
                    recv_info.to = (struct sockaddr *) &runtime.local_addr;
                    recv_info.to_len = runtime.local_addr_len;

                    done = king_http3_quiche.quiche_conn_recv_fn(
                        runtime.conn,
                        buffer,
                        (size_t) received,
                        &recv_info
                    );
                    if (done < 0 && done != QUICHE_ERR_DONE) {
                        king_http3_throw(
                            king_ce_quic_exception,
                            "%s() failed while processing an incoming QUIC datagram (%zd).",
                            function_name,
                            done
                        );
                        goto failure;
                    }
                }
            }
        }

        if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
            goto failure;
        }
    }

    if (!response.response_complete) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() did not receive a complete HTTP/3 response.",
            function_name
        );
        goto failure;
    }

    if (king_http3_quiche.quiche_conn_close_fn != NULL) {
        king_http3_quiche.quiche_conn_close_fn(runtime.conn, true, 0, NULL, 0);
        (void) king_http3_flush_egress(&runtime, function_name);
    }

    if (king_http3_materialize_response(return_value, &response, url_str) != SUCCESS) {
        goto failure;
    }

    king_http3_free_request_headers(request_headers, owned_strings, owned_string_count);
    king_http3_request_target_destroy(parsed_url, &target);
    king_http3_runtime_destroy(&runtime);
    smart_str_free(&response.body);
    king_set_error("");
    return SUCCESS;

failure:
    king_http3_free_request_headers(request_headers, owned_strings, owned_string_count);
    king_http3_request_target_destroy(parsed_url, &target);
    king_http3_runtime_destroy(&runtime);
    king_http3_response_destroy(&response);
    return FAILURE;
}

zend_result king_http3_request_dispatch(
    zval *return_value,
    const char *url_str,
    size_t url_len,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zval *body_zval,
    zval *options_array,
    const char *function_name)
{
    king_http3_request_options_t options;
    zend_string *body_string = NULL;
    zend_result result;

    if (url_len == 0) {
        king_http3_set_error("%s() requires a non-empty URL.", function_name);
        return FAILURE;
    }

    if (king_http3_validate_method(method_str, method_len, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http3_parse_options(options_array, &options, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http3_ensure_quiche_ready() != SUCCESS) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() HTTP/3 runtime is unavailable: %s",
            function_name,
            king_http3_quiche.load_error
        );
        return FAILURE;
    }

    if (body_zval != NULL && Z_TYPE_P(body_zval) != IS_NULL) {
        body_string = zval_get_string(body_zval);
    }

    result = king_http3_execute_request(
        return_value,
        url_str,
        method_str,
        method_len,
        headers_array,
        body_string,
        &options,
        function_name
    );

    if (body_string != NULL) {
        zend_string_release(body_string);
    }

    return result;
}

PHP_FUNCTION(king_http3_request_send)
{
    char *url_str;
    size_t url_len;
    char *method_str = "GET";
    size_t method_len = sizeof("GET") - 1;
    zval *headers_array = NULL;
    zval *body_zval = NULL;
    zval *options_array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 5)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(method_str, method_len)
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ZVAL_OR_NULL(body_zval)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    if (king_http3_request_dispatch(
            return_value,
            url_str,
            url_len,
            method_str,
            method_len,
            headers_array,
            body_zval,
            options_array,
            "king_http3_request_send"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }
}
