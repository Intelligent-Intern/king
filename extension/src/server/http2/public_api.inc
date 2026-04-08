PHP_FUNCTION(king_http2_server_listen)
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
        "king_http2_server_listen",
        host,
        host_len,
        port,
        config,
        3,
        "h2",
        "server_http2_local"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    king_server_http2_build_request(&request, session, host, host_len, port);

    if (king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http2_server_listen"
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
            "http/2",
            "king_http2_server_listen"
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

PHP_FUNCTION(king_http2_server_listen_once)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session = NULL;
    zval request;
    zval retval;
    int listener_fd = -1;
    int accepted_fd = -1;
    zval *stream_id_zv = NULL;
    zend_long stream_id = 1;
    zend_bool rc = 0;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_system_require_admission(
            "king_http2_server_listen_once",
            "http_listener_accepts"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    session = king_server_local_open_session(
        "king_http2_server_listen_once",
        host,
        host_len,
        port,
        config,
        3,
        "h2c",
        "server_http2_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_http2_open_listener_socket(
            host,
            port,
            &listener_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    accepted_fd = accept(listener_fd, NULL, NULL);
    if (accepted_fd < 0) {
        king_server_local_set_errorf(
            "king_http2_server_listen_once() failed to accept the on-wire HTTP/2 connection (errno %d).",
            errno
        );
        goto cleanup;
    }

    close(listener_fd);
    listener_fd = -1;

    if (
        king_server_http2_apply_transport_snapshot_from_socket(
            session,
            accepted_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http2_expect_client_preface(
            accepted_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http2_send_settings(
            accepted_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http2_handle_wire_request(
            session,
            &request,
            host,
            host_len,
            port,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http2_server_listen_once"
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
            "http/2",
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    stream_id_zv = zend_hash_str_find(
        Z_ARRVAL(request),
        "stream_id",
        sizeof("stream_id") - 1
    );
    if (stream_id_zv != NULL) {
        stream_id = zval_get_long(stream_id_zv);
    }

    if (
        king_server_http2_send_response(
            session,
            &retval,
            (uint32_t) stream_id,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        if (king_server_local_errno_is_peer_disconnect(errno)) {
            king_server_local_mark_stream_cancelled_if_registered(
                session,
                stream_id,
                "king_http2_server_listen_once"
            );
        }
        goto cleanup;
    }

    if (
        king_server_http2_send_goaway(
            accepted_fd,
            (uint32_t) stream_id,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    king_server_http2_drain_after_goaway(accepted_fd);
    rc = 1;

cleanup:
    if (listener_fd >= 0) {
        close(listener_fd);
    }

    if (session != NULL) {
        king_server_local_close_session(session);
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
