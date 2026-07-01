static zend_result king_server_http1_send_early_hints(
    king_client_session_t *session,
    zend_long stream_id,
    zend_long deadline_ms,
    const char *function_name
)
{
    smart_str response = {0};
    zval *pair;
    zend_result rc;

    if (session->transport_socket_fd < 0 || !session->http_enable_early_hints) {
        return SUCCESS;
    }

    if (
        Z_ISUNDEF(session->server_last_early_hints)
        || Z_TYPE(session->server_last_early_hints) != IS_ARRAY
        || stream_id != session->server_last_early_hints_stream_id
        || zend_hash_num_elements(Z_ARRVAL(session->server_last_early_hints)) == 0
    ) {
        return SUCCESS;
    }

    smart_str_appends(&response, "HTTP/1.1 103 Early Hints\r\n");

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(session->server_last_early_hints), pair)
    {
        zval *name_zv;
        zval *value_zv;
        zend_string *name;
        zend_string *value;

        if (pair == NULL || Z_TYPE_P(pair) != IS_ARRAY) {
            smart_str_free(&response);
            king_server_local_set_errorf(
                "%s() failed to materialize the normalized HTTP/1 Early Hints batch.",
                function_name
            );
            return FAILURE;
        }

        name_zv = zend_hash_str_find(
            Z_ARRVAL_P(pair),
            "name",
            sizeof("name") - 1
        );
        value_zv = zend_hash_str_find(
            Z_ARRVAL_P(pair),
            "value",
            sizeof("value") - 1
        );
        if (name_zv == NULL || value_zv == NULL) {
            smart_str_free(&response);
            king_server_local_set_errorf(
                "%s() failed to materialize the normalized HTTP/1 Early Hints batch.",
                function_name
            );
            return FAILURE;
        }

        name = zval_get_string(name_zv);
        value = zval_get_string(value_zv);
        smart_str_append(&response, name);
        smart_str_appends(&response, ": ");
        smart_str_append(&response, value);
        smart_str_appends(&response, "\r\n");
        zend_string_release(name);
        zend_string_release(value);
    }
    ZEND_HASH_FOREACH_END();

    smart_str_appends(&response, "\r\n");
    smart_str_0(&response);

    rc = king_server_http1_write_all_fd(
        session->transport_socket_fd,
        ZSTR_VAL(response.s),
        ZSTR_LEN(response.s),
        deadline_ms,
        function_name
    );

    smart_str_free(&response);
    return rc;
}

static zval *king_server_http1_response_body_stream(zval *retval)
{
    zval *body_stream = zend_hash_str_find(
        Z_ARRVAL_P(retval),
        "body_stream",
        sizeof("body_stream") - 1
    );

    if (body_stream == NULL || Z_TYPE_P(body_stream) == IS_NULL) {
        return NULL;
    }

    return body_stream;
}

static int king_server_http1_persistent_listener_fd = -1;
static zend_string *king_server_http1_persistent_listener_host = NULL;
static zend_long king_server_http1_persistent_listener_port = 0;

static bool king_server_http1_config_bool(
    zval *config,
    const char *key,
    size_t key_len
)
{
    zval *value;

    if (config == NULL || Z_TYPE_P(config) != IS_ARRAY) {
        return false;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(config), key, key_len);
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return false;
    }

    return zend_is_true(value);
}

static void king_server_http1_close_persistent_listener(void)
{
    if (king_server_http1_persistent_listener_fd >= 0) {
        close(king_server_http1_persistent_listener_fd);
        king_server_http1_persistent_listener_fd = -1;
    }

    if (king_server_http1_persistent_listener_host != NULL) {
        zend_string_release(king_server_http1_persistent_listener_host);
        king_server_http1_persistent_listener_host = NULL;
    }

    king_server_http1_persistent_listener_port = 0;
}

static zend_result king_server_http1_resolve_listener_socket(
    char *host,
    size_t host_len,
    zend_long port,
    bool persistent,
    int *listener_fd_out,
    const char *function_name
)
{
    int listener_fd = -1;

    if (!persistent) {
        return king_server_http1_open_listener_socket(
            host,
            port,
            listener_fd_out,
            function_name
        );
    }

    if (
        king_server_http1_persistent_listener_fd >= 0
        && king_server_http1_persistent_listener_host != NULL
        && king_server_http1_persistent_listener_port == port
        && ZSTR_LEN(king_server_http1_persistent_listener_host) == host_len
        && memcmp(ZSTR_VAL(king_server_http1_persistent_listener_host), host, host_len) == 0
    ) {
        *listener_fd_out = king_server_http1_persistent_listener_fd;
        return SUCCESS;
    }

    king_server_http1_close_persistent_listener();

    if (
        king_server_http1_open_listener_socket(
            host,
            port,
            &listener_fd,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    king_server_http1_persistent_listener_fd = listener_fd;
    king_server_http1_persistent_listener_host = zend_string_init(host, host_len, 0);
    king_server_http1_persistent_listener_port = port;
    *listener_fd_out = listener_fd;

    return SUCCESS;
}

static zend_long king_server_http1_write_deadline(king_client_session_t *session)
{
    return king_server_http1_now_ms() + king_server_http1_resolve_timeout_ms(session);
}

static zend_result king_server_http1_call_body_stream(
    zval *body_stream,
    zval *chunk,
    const char *function_name
)
{
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    if (zend_fcall_info_init(body_stream, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        king_server_local_set_errorf(
            "%s() failed to initialize the HTTP body stream callback.",
            function_name
        );
        return FAILURE;
    }

    fci.retval = chunk;
    fci.param_count = 0;
    fci.params = NULL;

    if (zend_call_function(&fci, &fcc) != SUCCESS) {
        if (EG(exception) == NULL) {
            king_server_local_set_errorf(
                "%s() failed to invoke the HTTP body stream callback.",
                function_name
            );
        }
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http1_write_chunk(
    king_client_session_t *session,
    const char *bytes,
    size_t bytes_len,
    const char *function_name
)
{
    char prefix[32];
    int prefix_len;

    if (bytes_len == 0) {
        return SUCCESS;
    }

    prefix_len = snprintf(prefix, sizeof(prefix), "%zx\r\n", bytes_len);
    if (prefix_len <= 0 || (size_t) prefix_len >= sizeof(prefix)) {
        king_server_local_set_errorf(
            "%s() failed to materialize an HTTP chunk header.",
            function_name
        );
        return FAILURE;
    }

    if (king_server_http1_write_all_fd(
            session->transport_socket_fd,
            prefix,
            (size_t) prefix_len,
            king_server_http1_write_deadline(session),
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_server_http1_write_all_fd(
            session->transport_socket_fd,
            bytes,
            bytes_len,
            king_server_http1_write_deadline(session),
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }
    return king_server_http1_write_all_fd(
        session->transport_socket_fd,
        "\r\n",
        sizeof("\r\n") - 1,
        king_server_http1_write_deadline(session),
        function_name
    );
}

static zend_result king_server_http1_finish_chunked_response(
    king_client_session_t *session,
    const char *function_name
)
{
    return king_server_http1_write_all_fd(
        session->transport_socket_fd,
        "0\r\n\r\n",
        sizeof("0\r\n\r\n") - 1,
        king_server_http1_write_deadline(session),
        function_name
    );
}

static zend_result king_server_http1_send_chunked_response(
    king_client_session_t *session,
    zval *body_stream,
    zend_long status,
    zval *headers_zv,
    zend_long stream_id,
    zend_long deadline_ms,
    const char *function_name
)
{
    smart_str response = {0};
    zend_result rc;

    if (
        king_server_http1_send_early_hints(
            session,
            stream_id,
            deadline_ms,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    smart_str_appends(&response, "HTTP/1.1 ");
    smart_str_append_long(&response, status);
    smart_str_appendc(&response, ' ');
    smart_str_appends(&response, king_server_http1_status_text(status));
    smart_str_appends(&response, "\r\n");

    if (king_server_http1_append_response_headers(&response, headers_zv) != SUCCESS) {
        smart_str_free(&response);
        return FAILURE;
    }

    smart_str_appends(&response, "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
    smart_str_0(&response);

    rc = king_server_http1_write_all_fd(
        session->transport_socket_fd,
        ZSTR_VAL(response.s),
        ZSTR_LEN(response.s),
        deadline_ms,
        function_name
    );
    smart_str_free(&response);
    if (rc != SUCCESS) {
        return FAILURE;
    }

    while (true) {
        zval chunk;

        ZVAL_UNDEF(&chunk);
        if (king_server_http1_call_body_stream(body_stream, &chunk, function_name) != SUCCESS) {
            if (!Z_ISUNDEF(chunk)) {
                zval_ptr_dtor(&chunk);
            }
            return FAILURE;
        }

        if (Z_TYPE(chunk) == IS_NULL || Z_TYPE(chunk) == IS_FALSE) {
            zval_ptr_dtor(&chunk);
            return king_server_http1_finish_chunked_response(session, function_name);
        }

        if (Z_TYPE(chunk) != IS_STRING) {
            zval_ptr_dtor(&chunk);
            king_server_local_set_errorf(
                "%s() HTTP body stream callback must return a string chunk, null, or false.",
                function_name
            );
            return FAILURE;
        }

        rc = king_server_http1_write_chunk(
            session,
            Z_STRVAL(chunk),
            Z_STRLEN(chunk),
            function_name
        );
        zval_ptr_dtor(&chunk);
        if (rc != SUCCESS) {
            return FAILURE;
        }
    }
}

static zend_result king_server_http1_send_response(
    king_client_session_t *session,
    zval *retval,
    zend_long stream_id,
    zend_long deadline_ms,
    const char *function_name
)
{
    zval *status_zv;
    zval *headers_zv;
    zval *body_zv;
    zval *body_stream;
    zend_long status = 200;
    zend_string *body = NULL;
    smart_str response = {0};
    zend_result rc;

    if (session->transport_socket_fd < 0) {
        return SUCCESS;
    }

    status_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "status", sizeof("status") - 1);
    headers_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "headers", sizeof("headers") - 1);
    body_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "body", sizeof("body") - 1);

    if (status_zv != NULL && Z_TYPE_P(status_zv) != IS_NULL) {
        status = zval_get_long(status_zv);
    }

    body_stream = king_server_http1_response_body_stream(retval);
    if (body_stream != NULL) {
        return king_server_http1_send_chunked_response(
            session,
            body_stream,
            status,
            headers_zv,
            stream_id,
            deadline_ms,
            function_name
        );
    }

    if (body_zv != NULL && Z_TYPE_P(body_zv) != IS_NULL) {
        body = zval_get_string(body_zv);
    } else {
        body = zend_string_init("", 0, 0);
    }

    if (
        king_server_http1_send_early_hints(
            session,
            stream_id,
            deadline_ms,
            function_name
        ) != SUCCESS
    ) {
        zend_string_release(body);
        return FAILURE;
    }

    smart_str_appends(&response, "HTTP/1.1 ");
    smart_str_append_long(&response, status);
    smart_str_appendc(&response, ' ');
    smart_str_appends(&response, king_server_http1_status_text(status));
    smart_str_appends(&response, "\r\n");

    if (king_server_http1_append_response_headers(&response, headers_zv) != SUCCESS) {
        zend_string_release(body);
        smart_str_free(&response);
        return FAILURE;
    }

    smart_str_appends(&response, "Content-Length: ");
    smart_str_append_unsigned(&response, (zend_ulong) ZSTR_LEN(body));
    smart_str_appends(&response, "\r\nConnection: close\r\n\r\n");
    if (ZSTR_LEN(body) > 0) {
        smart_str_appendl(&response, ZSTR_VAL(body), ZSTR_LEN(body));
    }
    smart_str_0(&response);

    rc = king_server_http1_write_all_fd(
        session->transport_socket_fd,
        ZSTR_VAL(response.s),
        ZSTR_LEN(response.s),
        deadline_ms,
        function_name
    );

    smart_str_free(&response);
    zend_string_release(body);
    return rc;
}

PHP_FUNCTION(king_http1_server_listen)
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
        "king_http1_server_listen",
        host,
        host_len,
        port,
        config,
        3,
        "http/1.1",
        "server_http1_local"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    king_server_http1_build_request(&request, session, host, host_len, port);

    if (king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http1_server_listen"
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
            "http/1.1",
            "king_http1_server_listen"
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

PHP_FUNCTION(king_http1_server_listen_once)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session = NULL;
    zval request;
    zval retval;
    smart_str request_head = {0};
    zend_long content_length = 0;
    zend_long accept_deadline_ms;
    zend_long read_deadline_ms;
    zend_long write_deadline_ms;
    zend_long stream_id = 0;
    int listener_fd = -1;
    int accepted_fd = -1;
    bool persistent_listener = false;
    zend_bool rc = 0;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_system_require_admission(
            "king_http1_server_listen_once",
            "http_listener_accepts"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);
    persistent_listener = king_server_http1_config_bool(
        config,
        "persistent_listener",
        sizeof("persistent_listener") - 1
    ) || king_server_http1_config_bool(
        config,
        "tcp.persistent_listener",
        sizeof("tcp.persistent_listener") - 1
    ) || king_server_http1_config_bool(
        config,
        "tcp_persistent_listener",
        sizeof("tcp_persistent_listener") - 1
    );

    session = king_server_local_open_session(
        "king_http1_server_listen_once",
        host,
        host_len,
        port,
        config,
        3,
        "http/1.1",
        "server_http1_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_http1_resolve_listener_socket(
            host,
            host_len,
            port,
            persistent_listener,
            &listener_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    accept_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_accept_once(
            listener_fd,
            accept_deadline_ms,
            &accepted_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (!persistent_listener) {
        close(listener_fd);
        listener_fd = -1;
    }

    if (
        king_server_http1_apply_transport_snapshot_from_socket(
            session,
            accepted_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    read_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_read_request_head(
            accepted_fd,
            &request_head,
            read_deadline_ms,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http1_parse_request_head(
            ZSTR_VAL(request_head.s),
            ZSTR_LEN(request_head.s),
            session,
            &request,
            &content_length,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http1_read_request_body(
            accepted_fd,
            &request,
            content_length,
            read_deadline_ms,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        if (EG(exception) != NULL) {
            goto cleanup;
        }

        goto cleanup;
    }

    if (
        king_server_local_validate_response(
            &retval,
            session,
            "http/1.1",
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (!zend_hash_num_elements(&session->server_upgraded_streams) && !session->is_closed) {
        zval *stream_id_zv = zend_hash_str_find(
            Z_ARRVAL(request),
            "stream_id",
            sizeof("stream_id") - 1
        );

        if (stream_id_zv != NULL) {
            stream_id = zval_get_long(stream_id_zv);
        }

        write_deadline_ms = king_server_http1_now_ms()
            + king_server_http1_resolve_timeout_ms(session);
        if (
            king_server_http1_send_response(
                session,
                &retval,
                stream_id,
                write_deadline_ms,
                "king_http1_server_listen_once"
            ) != SUCCESS
        ) {
            if (king_server_local_errno_is_peer_disconnect(errno)) {
                king_server_local_mark_stream_cancelled_if_registered(
                    session,
                    stream_id,
                    "king_http1_server_listen_once"
                );
            }
            goto cleanup;
        }
    }

    rc = 1;

cleanup:
    if (listener_fd >= 0 && !persistent_listener) {
        close(listener_fd);
    }

    if (session != NULL) {
        king_server_local_close_session(session);
    }

    if (request_head.s != NULL) {
        smart_str_free(&request_head);
    }

    if (!Z_ISUNDEF(request)) {
        zval_ptr_dtor(&request);
    }

    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }

    if (!rc) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}
