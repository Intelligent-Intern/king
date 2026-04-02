/*
 * Shared local-listener helpers for the server runtime. Opens and normalizes
 * the process-local server session snapshot, applies config/transport state
 * and builds the normalized request path used by the local protocol leaves.
 */

#include "include/server/cors.h"
#include "include/server/open_telemetry.h"

static void king_server_local_set_errorf(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static void king_server_local_set_string_bytes(
    zend_string **slot,
    const char *value,
    size_t value_len
)
{
    if (*slot != NULL) {
        zend_string_release(*slot);
    }

    *slot = zend_string_init(
        value != NULL ? value : "",
        value != NULL ? value_len : 0,
        0
    );
}

static void king_server_local_set_string(
    zend_string **slot,
    const char *value
)
{
    king_server_local_set_string_bytes(
        slot,
        value,
        value != NULL ? strlen(value) : 0
    );
}

static zend_result king_server_local_apply_config(
    king_client_session_t *session,
    zval *config,
    uint32_t config_arg_num
)
{
    king_cfg_t *cfg = NULL;

    if (config == NULL || Z_TYPE_P(config) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(config) == IS_ARRAY) {
        cfg = king_config_new_from_options(config);
        if (cfg == NULL) {
            return FAILURE;
        }

        king_client_session_apply_config_resource(session, cfg);
        session->config_binding = "inline_array";
        session->config_option_count = zend_hash_num_elements(Z_ARRVAL_P(config));
        king_config_free(cfg);
        return SUCCESS;
    }

    cfg = (king_cfg_t *) king_fetch_config(config);
    if (cfg == NULL) {
        zend_argument_type_error(
            config_arg_num,
            "must be null, array, a King\\Config resource, or a King\\Config object"
        );
        return FAILURE;
    }

    king_client_session_apply_config_resource(session, cfg);
    return SUCCESS;
}

static void king_server_local_apply_transport_snapshot(
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port,
    const char *alpn,
    const char *transport_backend
)
{
    bool is_http3 = (
        alpn != NULL
        && alpn[0] == 'h'
        && alpn[1] == '3'
        && alpn[2] == '\0'
    );

    king_server_local_set_string(&session->negotiated_alpn, alpn);
    king_server_local_set_string(&session->transport_backend, transport_backend);
    king_server_local_set_string_bytes(&session->transport_local_address, host, host_len);
    king_server_local_set_string(&session->transport_peer_address, "local-client");
    king_server_local_set_string(
        &session->transport_socket_family,
        is_http3 ? "udp" : "virtual"
    );
    king_server_local_set_string(&session->transport_error_scope, "none");

    session->transport_local_port = port;
    session->transport_peer_port = 0;
    session->transport_last_errno = 0;
    session->transport_has_socket = false;
    session->transport_datagrams_enable = is_http3;
}

static king_client_session_t *king_server_local_open_session(
    const char *function_name,
    const char *host,
    size_t host_len,
    zend_long port,
    zval *config,
    uint32_t config_arg_num,
    const char *alpn,
    const char *transport_backend
)
{
    king_client_session_t *session;
    time_t now;

    if (host_len == 0) {
        king_server_local_set_errorf("%s() requires a non-empty host.", function_name);
        return NULL;
    }

    if (port <= 0 || port > 65535) {
        king_server_local_set_errorf(
            "%s() port must be between 1 and 65535.",
            function_name
        );
        return NULL;
    }

    session = ecalloc(1, sizeof(*session));
    session->host = zend_string_init(host, host_len, 0);
    session->port = port;

    king_client_session_init_cancel_state(session);
    king_client_session_init_transport_state(session);
    king_client_session_init_tls_state(session);
    king_client_session_init_config_state(session);
    king_server_session_prepare(session);

    if (king_server_local_apply_config(session, config, config_arg_num) != SUCCESS) {
        king_client_session_free(session);
        return NULL;
    }

    king_server_local_apply_transport_snapshot(
        session,
        host,
        host_len,
        port,
        alpn,
        transport_backend
    );

    now = time(NULL);
    session->connected_at = now;
    session->last_activity_at = now;

    return session;
}

static void king_server_local_close_session(king_client_session_t *session)
{
    if (session == NULL || session->is_closed) {
        return;
    }

    king_client_session_close_socket(session);
    session->is_closed = true;
    session->last_activity_at = time(NULL);
}

static zend_string *king_server_local_build_normalized_path(zend_string *uri)
{
    const char *value;
    size_t value_len;
    const char *query;
    const char *scheme;
    const char *authority;
    const char *slash;
    size_t path_len;

    if (uri == NULL) {
        return zend_string_init("/", sizeof("/") - 1, 0);
    }

    value = ZSTR_VAL(uri);
    value_len = ZSTR_LEN(uri);
    if (value_len == 0) {
        return zend_string_init("/", sizeof("/") - 1, 0);
    }

    query = memchr(value, '?', value_len);

    if (value[0] == '/') {
        path_len = query != NULL
            ? (size_t) (query - value)
            : value_len;
        if (path_len == 0) {
            path_len = 1;
        }
        return zend_string_init(value, path_len, 0);
    }

    if (value[0] == '*') {
        return zend_string_init(value, 1, 0);
    }

    scheme = strstr(value, "://");
    if (scheme != NULL) {
        authority = scheme + 3;
        slash = memchr(authority, '/', value_len - (size_t) (authority - value));
        if (slash != NULL) {
            const char *path_end = query != NULL && query > slash
                ? query
                : value + value_len;

            path_len = (size_t) (path_end - slash);
            if (path_len == 0) {
                path_len = 1;
            }
            return zend_string_init(slash, path_len, 0);
        }

        return zend_string_init("/", sizeof("/") - 1, 0);
    }

    path_len = query != NULL
        ? (size_t) (query - value)
        : value_len;
    if (path_len == 0) {
        return zend_string_init("/", sizeof("/") - 1, 0);
    }

    return zend_string_init(value, path_len, 0);
}

static void king_server_local_add_normalized_target_fields(zval *request)
{
    zval *uri_zv;
    zend_string *path;

    uri_zv = zend_hash_str_find(
        Z_ARRVAL_P(request),
        "uri",
        sizeof("uri") - 1
    );
    if (uri_zv == NULL || Z_TYPE_P(uri_zv) != IS_STRING) {
        return;
    }

    path = king_server_local_build_normalized_path(Z_STR_P(uri_zv));
    add_assoc_str(request, "path", path);
}

static void king_server_local_add_common_request_fields(
    zval *request,
    king_client_session_t *session,
    const char *protocol,
    const char *scheme,
    zend_long stream_id
)
{
    zval zsession;

    ZVAL_RES(&zsession, zend_register_resource(session, le_king_session));

    add_assoc_zval(request, "session", &zsession);
    add_assoc_long(
        request,
        "server_session_capability",
        king_server_session_capability(session)
    );
    add_assoc_string(request, "protocol", (char *) protocol);
    add_assoc_string(request, "scheme", (char *) scheme);
    add_assoc_long(request, "stream_id", stream_id);
    king_server_local_add_normalized_target_fields(request);
    add_assoc_str(request, "local_address", zend_string_copy(session->transport_local_address));
    add_assoc_long(request, "local_port", session->transport_local_port);
    add_assoc_str(request, "remote_address", zend_string_copy(session->transport_peer_address));
    add_assoc_long(request, "remote_port", session->transport_peer_port);
    king_server_cors_add_request_metadata(request, session);
    king_server_open_telemetry_add_request_metadata(request, session);
}

static zend_result king_server_local_invoke_handler(
    zval *handler,
    zval *request,
    zval *retval,
    const char *function_name
)
{
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    if (zend_fcall_info_init(handler, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        zend_argument_type_error(4, "must be a valid callback");
        return FAILURE;
    }

    fci.retval = retval;
    fci.param_count = 1;
    fci.params = request;

    if (zend_call_function(&fci, &fcc) != SUCCESS) {
        if (EG(exception) == NULL) {
            king_server_local_set_errorf(
                "%s() failed to invoke the request handler.",
                function_name
            );
        }

        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_local_validate_response(
    zval *retval,
    king_client_session_t *session,
    const char *protocol,
    const char *function_name
)
{
    zval *status;
    zval *headers;
    zval *body;
    zend_long code;

    if (Z_TYPE_P(retval) != IS_ARRAY) {
        king_server_local_set_errorf(
            "%s() handler must return an array response.",
            function_name
        );
        return FAILURE;
    }

    headers = zend_hash_str_find(Z_ARRVAL_P(retval), "headers", sizeof("headers") - 1);
    if (headers != NULL
        && Z_TYPE_P(headers) != IS_ARRAY
        && Z_TYPE_P(headers) != IS_NULL) {
        king_server_local_set_errorf(
            "%s() handler response 'headers' must be an array when present.",
            function_name
        );
        return FAILURE;
    }

    body = zend_hash_str_find(Z_ARRVAL_P(retval), "body", sizeof("body") - 1);
    if (body != NULL
        && Z_TYPE_P(body) != IS_STRING
        && Z_TYPE_P(body) != IS_NULL) {
        king_server_local_set_errorf(
            "%s() handler response 'body' must be a string when present.",
            function_name
        );
        return FAILURE;
    }

    status = zend_hash_str_find(Z_ARRVAL_P(retval), "status", sizeof("status") - 1);
    if (status == NULL || Z_TYPE_P(status) == IS_NULL) {
        return SUCCESS;
    }

    switch (Z_TYPE_P(status)) {
        case IS_LONG:
        case IS_DOUBLE:
        case IS_TRUE:
        case IS_FALSE:
        case IS_STRING:
            code = zval_get_long(status);
            break;
        default:
            king_server_local_set_errorf(
                "%s() handler response 'status' must resolve to an HTTP status code between 100 and 599.",
                function_name
            );
            return FAILURE;
    }

    if (code < 100 || code > 599) {
        king_server_local_set_errorf(
            "%s() handler response 'status' must resolve to an HTTP status code between 100 and 599.",
            function_name
        );
        return FAILURE;
    }

    king_server_cors_apply_response(retval, session);
    king_server_open_telemetry_record_response(session, protocol, retval);

    return SUCCESS;
}

static bool king_server_local_errno_is_peer_disconnect(int err)
{
    switch (err) {
#ifdef EPIPE
        case EPIPE:
#endif
#ifdef ECONNRESET
        case ECONNRESET:
#endif
#ifdef ECONNREFUSED
        case ECONNREFUSED:
#endif
#ifdef ECONNABORTED
        case ECONNABORTED:
#endif
#ifdef ENOTCONN
        case ENOTCONN:
#endif
            return true;
        default:
            return false;
    }
}

static void king_server_local_mark_stream_cancelled_if_registered(
    king_client_session_t *session,
    zend_long stream_id,
    const char *function_name
)
{
    if (session == NULL || session->is_closed || stream_id < 0) {
        return;
    }

    if (!zend_hash_index_exists(&session->server_cancel_handlers, (zend_ulong) stream_id)) {
        return;
    }

    if (zend_hash_index_exists(&session->cancelled_streams, (zend_ulong) stream_id)) {
        return;
    }

    (void) king_client_session_mark_cancelled(
        session,
        stream_id,
        "both",
        sizeof("both") - 1,
        function_name
    );
}
