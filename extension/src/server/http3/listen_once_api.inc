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

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_system_require_admission(
            "king_http3_server_listen_once",
            "http_listener_accepts"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

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

#if defined(KING_HTTP3_BACKEND_LSQUIC)
    rc = king_server_http3_listen_once_lsquic(
        host,
        host_len,
        port,
        handler,
        session,
        &options,
        &runtime,
        &request_state,
        &response_state,
        &request,
        &retval,
        &request_complete,
        &handler_invoked
    ) == SUCCESS;
    goto cleanup;
#else
    king_server_local_set_errorf(
        "king_http3_server_listen_once() requires an LSQUIC-enabled HTTP/3 server build."
    );
    goto cleanup;
#endif

cleanup:
    if (session != NULL) {
        bool session_owns_runtime_socket = runtime.socket_fd >= 0
            && session->transport_socket_fd == runtime.socket_fd;

        king_server_local_close_session(session);
        if (session_owns_runtime_socket) {
            runtime.socket_fd = -1;
        }
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
