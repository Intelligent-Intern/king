ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_WebSocket_Server___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\Config, 1, "null")
ZEND_END_ARG_INFO()

#include "include/client/websocket.h"
#include "include/iibin/iibin_internal.h"

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_WebSocket_Server_accept, 0, 0, King\WebSocket\Connection, 0)
ZEND_END_ARG_INFO()

static void king_websocket_server_set_errorf(
    const char *format,
    ...
)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static void king_websocket_server_throw_last_error(
    zend_class_entry *exception_ce,
    const char *fallback
)
{
    zend_throw_exception_ex(
        exception_ce,
        0,
        "%s",
        king_get_error()[0] != '\0' ? king_get_error() : fallback
    );
}

static zend_bool king_websocket_server_error_is_timeout(void)
{
    return strstr(king_get_error(), "timed out") != NULL;
}

static zend_result king_websocket_server_open_listener(
    king_ws_server_object *intern,
    const char *function_name
)
{
    int listener_fd = -1;

    if (intern->closed) {
        king_set_error("WebSocket\\Server::accept() cannot run on a stopped WebSocket server.");
        return FAILURE;
    }

    if (intern->listener_fd >= 0) {
        return SUCCESS;
    }

    if (intern->host == NULL || ZSTR_LEN(intern->host) == 0) {
        king_set_error("WebSocket\\Server is not initialized.");
        return FAILURE;
    }

    if (
        king_server_http1_open_listener_socket(
            ZSTR_VAL(intern->host),
            intern->port,
            &listener_fd,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    intern->listener_fd = listener_fd;
    return SUCCESS;
}

static zend_result king_websocket_server_init_connection_object(
    zval *target,
    zval *resource
)
{
    king_ws_object *connection;

    if (object_init_ex(target, king_ce_ws_connection) != SUCCESS) {
        return FAILURE;
    }

    connection = php_king_ws_obj_from_zend(Z_OBJ_P(target));
    if (!Z_ISUNDEF(connection->resource)) {
        zval_ptr_dtor(&connection->resource);
    }
    ZVAL_COPY(&connection->resource, resource);
    return SUCCESS;
}

static zend_string *king_websocket_server_next_connection_id(
    king_ws_server_object *intern,
    king_ws_state *state
)
{
    zend_ulong sequence = intern->next_connection_sequence++;

    return strpprintf(
        0,
        "%s#%lu",
        ZSTR_VAL(state->url),
        (unsigned long) sequence
    );
}

void king_ws_server_registry_detach(
    king_ws_server_object *server,
    king_ws_state *state
)
{
    zval *entry;

    if (
        server == NULL
        || state == NULL
        || !server->registry_initialized
        || state->connection_id == NULL
    ) {
        return;
    }

    entry = zend_hash_find(&server->connections, state->connection_id);
    if (
        entry != NULL
        && Z_TYPE_P(entry) == IS_PTR
        && (king_ws_state *) Z_PTR_P(entry) == state
    ) {
        zend_hash_del(&server->connections, state->connection_id);
    }

    if (state->server_owner == server) {
        state->server_owner = NULL;
    }
}

static void king_websocket_server_registry_prune_closed(
    king_ws_server_object *intern
)
{
    uint32_t capacity;
    uint32_t remove_count = 0;
    zend_string **remove_keys;
    zend_string *key;
    zval *entry;

    if (!intern->registry_initialized) {
        return;
    }

    capacity = zend_hash_num_elements(&intern->connections);
    if (capacity == 0) {
        return;
    }

    remove_keys = safe_emalloc(capacity, sizeof(zend_string *), 0);

    ZEND_HASH_FOREACH_STR_KEY_VAL(&intern->connections, key, entry)
    {
        king_ws_state *state = Z_TYPE_P(entry) == IS_PTR
            ? (king_ws_state *) Z_PTR_P(entry)
            : NULL;

        if (key == NULL) {
            continue;
        }

        if (state == NULL || state->closed || state->state == KING_WS_STATE_CLOSED) {
            remove_keys[remove_count++] = zend_string_copy(key);
            if (state != NULL && state->server_owner == intern) {
                state->server_owner = NULL;
            }
        }
    }
    ZEND_HASH_FOREACH_END();

    while (remove_count > 0) {
        remove_count--;
        zend_hash_del(&intern->connections, remove_keys[remove_count]);
        zend_string_release(remove_keys[remove_count]);
    }

    efree(remove_keys);
}

static zend_result king_websocket_server_registry_attach(
    king_ws_server_object *intern,
    zval *resource,
    const char *function_name
)
{
    king_ws_state *state;
    zend_string *connection_id;
    zval stored_state;

    state = zend_fetch_resource(
        Z_RES_P(resource),
        "King\\WebSocket",
        le_king_ws
    );
    if (state == NULL) {
        king_websocket_server_set_errorf(
            "%s() failed to fetch the accepted native WebSocket state.",
            function_name
        );
        return FAILURE;
    }

    connection_id = king_websocket_server_next_connection_id(intern, state);
    if (state->connection_id != NULL) {
        zend_string_release(state->connection_id);
    }
    state->connection_id = connection_id;
    state->server_owner = intern;

    ZVAL_PTR(&stored_state, state);
    if (zend_hash_add_new(&intern->connections, connection_id, &stored_state) == NULL) {
        state->server_owner = NULL;
        king_websocket_server_set_errorf(
            "%s() failed to register the accepted WebSocket connection.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static king_ws_state *king_websocket_server_registry_fetch_state(
    king_ws_server_object *intern,
    zend_string *connection_id,
    const char *function_name
)
{
    zval *entry;
    king_ws_state *state;

    king_websocket_server_registry_prune_closed(intern);
    entry = zend_hash_find(&intern->connections, connection_id);
    if (entry == NULL || Z_TYPE_P(entry) != IS_PTR) {
        king_websocket_server_set_errorf(
            "%s() target connection '%s' is not active on this WebSocket server.",
            function_name,
            ZSTR_VAL(connection_id)
        );
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        return NULL;
    }

    state = (king_ws_state *) Z_PTR_P(entry);
    if (state == NULL || state->closed || state->state == KING_WS_STATE_CLOSED) {
        if (state != NULL && state->server_owner == intern) {
            state->server_owner = NULL;
        }
        zend_hash_del(&intern->connections, connection_id);
        king_websocket_server_set_errorf(
            "%s() target connection '%s' is not active on this WebSocket server.",
            function_name,
            ZSTR_VAL(connection_id)
        );
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        return NULL;
    }

    return state;
}

static zend_result king_websocket_server_send_internal(
    king_ws_server_object *intern,
    zend_string *connection_id,
    zend_string *payload,
    bool is_binary,
    const char *function_name
)
{
    king_ws_state *state;

    if (intern->closed) {
        king_websocket_server_set_errorf(
            "%s() cannot run on a stopped WebSocket server.",
            function_name
        );
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        return FAILURE;
    }

    if (ZSTR_LEN(connection_id) == 0) {
        king_websocket_server_set_errorf(
            "%s() requires a non-empty connection id.",
            function_name
        );
        zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", king_get_error());
        return FAILURE;
    }

    state = king_websocket_server_registry_fetch_state(
        intern,
        connection_id,
        function_name
    );
    if (state == NULL) {
        return FAILURE;
    }

    if (
        king_websocket_state_send(
            state,
            payload,
            is_binary,
            function_name
        ) != SUCCESS
    ) {
        king_websocket_server_registry_prune_closed(intern);
        return FAILURE;
    }

    king_websocket_server_registry_prune_closed(intern);
    return SUCCESS;
}

static zend_result king_websocket_server_broadcast_internal(
    king_ws_server_object *intern,
    zend_string *payload,
    bool is_binary,
    const char *function_name
)
{
    zval *entry;

    if (intern->closed) {
        king_websocket_server_set_errorf(
            "%s() cannot run on a stopped WebSocket server.",
            function_name
        );
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        return FAILURE;
    }

    king_websocket_server_registry_prune_closed(intern);

    ZEND_HASH_FOREACH_VAL(&intern->connections, entry)
    {
        king_ws_state *state = Z_TYPE_P(entry) == IS_PTR
            ? (king_ws_state *) Z_PTR_P(entry)
            : NULL;

        if (state == NULL || state->closed || state->state == KING_WS_STATE_CLOSED) {
            continue;
        }

        if (
            king_websocket_state_send(
                state,
                payload,
                is_binary,
                function_name
            ) != SUCCESS
        ) {
            /* An aborted peer should be pruned, not allowed to poison the remaining fanout. */
            if (state->closed || state->state == KING_WS_STATE_CLOSED) {
                if (EG(exception) != NULL) {
                    zend_clear_exception();
                }
                continue;
            }

            king_websocket_server_registry_prune_closed(intern);
            return FAILURE;
        }
    }
    ZEND_HASH_FOREACH_END();

    king_websocket_server_registry_prune_closed(intern);
    king_set_error("");
    return SUCCESS;
}

static void king_websocket_server_close_active_connections(
    king_ws_server_object *intern,
    const char *function_name
)
{
    zend_string *reason;
    zval *entry;

    reason = zend_string_init(
        "server-shutdown",
        sizeof("server-shutdown") - 1,
        0
    );

    ZEND_HASH_FOREACH_VAL(&intern->connections, entry)
    {
        king_ws_state *state = Z_TYPE_P(entry) == IS_PTR
            ? (king_ws_state *) Z_PTR_P(entry)
            : NULL;

        if (state == NULL || state->closed || state->state == KING_WS_STATE_CLOSED) {
            continue;
        }

        (void) king_websocket_state_close(
            state,
            1001,
            reason,
            function_name
        );
    }
    ZEND_HASH_FOREACH_END();

    zend_string_release(reason);
    king_websocket_server_registry_prune_closed(intern);
}

PHP_METHOD(King_WebSocket_Server, __construct)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config = NULL;
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(config, king_ce_config)
    ZEND_PARSE_PARAMETERS_END();

    if (host_len == 0) {
        king_set_error("WebSocket\\Server::__construct() requires a non-empty host.");
        zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", king_get_error());
        RETURN_THROWS();
    }

    if (port <= 0 || port > 65535) {
        king_set_error("WebSocket\\Server::__construct() port must be between 1 and 65535.");
        zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", king_get_error());
        RETURN_THROWS();
    }

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }
    if (intern->host != NULL) {
        zend_string_release(intern->host);
        intern->host = NULL;
    }
    if (intern->listener_fd >= 0) {
        close(intern->listener_fd);
        intern->listener_fd = -1;
    }

    intern->host = zend_string_init(host, host_len, 0);
    intern->port = port;
    intern->closed = false;
    if (config != NULL) {
        ZVAL_COPY(&intern->config, config);
    }

    king_set_error("");
}

PHP_METHOD(King_WebSocket_Server, accept)
{
    king_ws_server_object *intern;
    king_client_session_t *session = NULL;
    zval request;
    zval websocket_resource;
    zval *request_headers = NULL;
    smart_str request_head = {0};
    zend_long content_length = 0;
    zend_long accept_deadline_ms;
    zend_long read_deadline_ms;
    int accepted_fd = -1;
    zend_bool success = 0;
    zend_bool request_owns_session = 0;

    ZEND_PARSE_PARAMETERS_NONE();

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&websocket_resource);

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (intern->closed) {
        king_set_error("WebSocket\\Server::accept() cannot run on a stopped WebSocket server.");
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        RETURN_THROWS();
    }

    if (
        king_system_require_admission(
            "WebSocket\\Server::accept",
            "websocket_peer_accepts"
        ) != SUCCESS
    ) {
        king_websocket_server_throw_last_error(
            king_ce_runtime_exception,
            "WebSocket\\Server::accept() cannot admit new websocket peers."
        );
        RETURN_THROWS();
    }

    if (
        king_websocket_server_open_listener(
            intern,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        king_websocket_server_throw_last_error(
            king_ce_ws_connection_error,
            "WebSocket\\Server::accept() failed to open the active listener."
        );
        RETURN_THROWS();
    }

    session = king_server_local_open_session(
        "WebSocket\\Server::accept",
        ZSTR_VAL(intern->host),
        ZSTR_LEN(intern->host),
        intern->port,
        Z_ISUNDEF(intern->config) ? NULL : &intern->config,
        0,
        "http/1.1",
        "server_http1_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        king_websocket_server_throw_last_error(
            king_ce_ws_connection_error,
            "WebSocket\\Server::accept() failed to open the active server session."
        );
        RETURN_THROWS();
    }

    accept_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_accept_once(
            intern->listener_fd,
            accept_deadline_ms,
            &accepted_fd,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_server_http1_apply_transport_snapshot_from_socket(
            session,
            accepted_fd,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }
    accepted_fd = -1;

    read_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_read_request_head(
            session->transport_socket_fd,
            &request_head,
            read_deadline_ms,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_server_http1_parse_request_head(
            ZSTR_VAL(request_head.s),
            ZSTR_LEN(request_head.s),
            session,
            &request,
            &content_length,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_server_http1_read_request_body(
            session->transport_socket_fd,
            &request,
            content_length,
            read_deadline_ms,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (!session->server_pending_websocket_upgrade) {
        king_set_error(
            "WebSocket\\Server::accept() received an HTTP/1 request that did not request a websocket upgrade."
        );
        king_websocket_server_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket\\Server::accept() requires a websocket upgrade request."
        );
        goto fail_cleanup;
    }

    if (
        king_system_require_admission(
            "WebSocket\\Server::accept",
            "websocket_upgrades"
        ) != SUCCESS
    ) {
        king_websocket_server_throw_last_error(
            king_ce_runtime_exception,
            "WebSocket\\Server::accept() cannot admit websocket upgrades."
        );
        goto fail_cleanup;
    }

    request_headers = zend_hash_str_find(
        Z_ARRVAL(request),
        "headers",
        sizeof("headers") - 1
    );
    if (
        king_server_websocket_upgrade_session(
            session,
            0,
            request_headers,
            &websocket_resource,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_websocket_server_registry_attach(
            intern,
            &websocket_resource,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        king_websocket_server_throw_last_error(
            king_ce_runtime_exception,
            "WebSocket\\Server::accept() failed to register the accepted WebSocket connection."
        );
        goto fail_cleanup;
    }
    king_server_websocket_release_session_upgrade(session, 0);

    if (
        king_websocket_server_init_connection_object(
            return_value,
            &websocket_resource
        ) != SUCCESS
    ) {
        king_set_error("WebSocket\\Server::accept() failed to initialize the accepted Connection object.");
        king_websocket_server_throw_last_error(
            king_ce_runtime_exception,
            "WebSocket\\Server::accept() failed to initialize the accepted Connection object."
        );
        goto fail_cleanup;
    }

    success = 1;
    goto fail_cleanup;

fail_accept:
    if (king_websocket_server_error_is_timeout()) {
        king_websocket_server_throw_last_error(
            king_ce_ws_timeout,
            "WebSocket\\Server::accept() timed out while waiting for a websocket peer."
        );
    } else {
        king_websocket_server_throw_last_error(
            king_ce_ws_connection_error,
            "WebSocket\\Server::accept() failed to accept the active websocket peer."
        );
    }

fail_cleanup:
    request_owns_session =
        !Z_ISUNDEF(request)
        && Z_TYPE(request) == IS_ARRAY
        && zend_hash_str_exists(
            Z_ARRVAL(request),
            "session",
            sizeof("session") - 1
        );

    if (accepted_fd >= 0) {
        close(accepted_fd);
    }
    if (!Z_ISUNDEF(websocket_resource)) {
        zval_ptr_dtor(&websocket_resource);
        ZVAL_UNDEF(&websocket_resource);
    }
    if (!Z_ISUNDEF(request)) {
        zval_ptr_dtor(&request);
        ZVAL_UNDEF(&request);
        if (request_owns_session) {
            session = NULL;
        }
    }
    if (request_head.s != NULL) {
        smart_str_free(&request_head);
    }
    if (session != NULL) {
        king_server_local_close_session(session);
        king_client_session_free(session);
    }

    if (success) {
        king_set_error("");
        return;
    }

    RETURN_THROWS();
}

/*
 * Build one WebSocket frame header (server→client, no masking) into buf.
 * Returns header length (2, 4, or 10 bytes).
 */
static size_t king_ws_build_server_frame_header(
    unsigned char *buf,
    unsigned char opcode,
    size_t payload_len
)
{
    size_t hlen = 0;

    buf[hlen++] = (unsigned char)(0x80 | (opcode & 0x0F)); /* FIN + opcode */

    if (payload_len <= 125) {
        buf[hlen++] = (unsigned char)payload_len;
    } else if (payload_len <= 0xFFFF) {
        buf[hlen++] = 126;
        buf[hlen++] = (unsigned char)((payload_len >> 8) & 0xFF);
        buf[hlen++] = (unsigned char)(payload_len & 0xFF);
    } else {
        uint64_t ext = (uint64_t)payload_len;
        size_t i;
        buf[hlen++] = 127;
        for (i = 0; i < 8; i++) {
            buf[hlen++] = (unsigned char)((ext >> ((7 - i) * 8)) & 0xFF);
        }
    }

    return hlen;
}

/*
 * Pre-frame broadcast: build all WebSocket frames into a single buffer once,
 * then write that buffer to each connection with one write() per peer.
 *
 * Phase 1 — Pre-frame: walk the varint-length-prefixed batch, emit
 *   [ws_header][payload][ws_header][payload]... into a smart_str.
 *   Server→client frames carry no masking, so the bytes are identical for
 *   every connection.
 *
 * Phase 2 — Scatter: write the pre-framed buffer to each live connection.
 *   One king_websocket_write_all() call per peer instead of M calls.
 */
static zend_result king_websocket_server_broadcast_batch(
    king_ws_server_object *intern,
    zend_string *encoded_batch,
    const char *function_name
)
{
    const unsigned char *data;
    const unsigned char *data_end;
    const unsigned char *p;
    uint64_t rec_len;
    smart_str wire_buf = {0};
    zval *entry;

    if (intern->closed) {
        king_websocket_server_set_errorf(
            "%s() cannot run on a stopped WebSocket server.",
            function_name
        );
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        return FAILURE;
    }

    data = (const unsigned char *)ZSTR_VAL(encoded_batch);
    data_end = data + ZSTR_LEN(encoded_batch);

    if (data >= data_end) {
        king_websocket_server_set_errorf(
            "%s() empty batch.",
            function_name
        );
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        return FAILURE;
    }

    /* ---- Phase 1: pre-frame ------------------------------------------- */
    /* Pre-size the buffer: worst case = batch_len + 10 bytes header per record.
     * Typical overhead is 2 bytes per record (payloads < 126 bytes). */
    smart_str_alloc(&wire_buf, ZSTR_LEN(encoded_batch) + 256, 0);

    p = data;
    while (p < data_end) {
        unsigned char hdr[10];
        size_t hdr_len;

        if (!king_proto_decode_varint(&p, data_end, &rec_len)) {
            break;
        }
        if (rec_len == 0 || p + rec_len > data_end) {
            break;
        }

        hdr_len = king_ws_build_server_frame_header(
            hdr, KING_WS_OPCODE_BINARY, (size_t)rec_len);
        smart_str_appendl(&wire_buf, (const char *)hdr, hdr_len);
        smart_str_appendl(&wire_buf, (const char *)p, (size_t)rec_len);

        p += rec_len;
    }

    if (wire_buf.s == NULL || ZSTR_LEN(wire_buf.s) == 0) {
        smart_str_free(&wire_buf);
        king_set_error("");
        return SUCCESS;
    }
    smart_str_0(&wire_buf);

    /* ---- Phase 2: scatter --------------------------------------------- */
    king_websocket_server_registry_prune_closed(intern);

    ZEND_HASH_FOREACH_VAL(&intern->connections, entry)
    {
        king_ws_state *state = Z_TYPE_P(entry) == IS_PTR
            ? (king_ws_state *)Z_PTR_P(entry)
            : NULL;

        if (state == NULL || state->closed
            || state->state == KING_WS_STATE_CLOSED
            || state->transport_stream == NULL) {
            continue;
        }

        {
            const char *buf = ZSTR_VAL(wire_buf.s);
            size_t remaining = ZSTR_LEN(wire_buf.s);
            size_t written = 0;
            bool write_failed = false;

            while (written < remaining) {
                ssize_t chunk = php_stream_write(
                    state->transport_stream,
                    buf + written,
                    remaining - written
                );
                if (chunk <= 0) {
                    write_failed = true;
                    break;
                }
                written += (size_t)chunk;
            }

            if (write_failed) {
                if (state->closed || state->state == KING_WS_STATE_CLOSED) {
                    if (EG(exception) != NULL) {
                        zend_clear_exception();
                    }
                    continue; /* dead peer, move to next */
                }
                smart_str_free(&wire_buf);
                king_websocket_server_registry_prune_closed(intern);
                king_websocket_server_set_errorf(
                    "%s() failed to write pre-framed batch to a WebSocket peer.",
                    function_name
                );
                return FAILURE;
            }
        }
    }
    ZEND_HASH_FOREACH_END();

    smart_str_free(&wire_buf);
    king_websocket_server_registry_prune_closed(intern);
    king_set_error("");
    return SUCCESS;
}

#include "websocket_server_object/dispatch.inc"
