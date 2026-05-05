static zend_result king_server_admin_api_context_set_string(
    php_stream_context *context,
    const char *wrapper,
    const char *option,
    const char *value
)
{
    zval zv;

    ZVAL_STRING(&zv, value != NULL ? value : "");
    php_stream_context_set_option(context, wrapper, option, &zv);
    zval_ptr_dtor(&zv);
    return SUCCESS;
}

static zend_result king_server_admin_api_context_set_bool(
    php_stream_context *context,
    const char *wrapper,
    const char *option,
    bool value
)
{
    zval zv;

    ZVAL_BOOL(&zv, value);
    php_stream_context_set_option(context, wrapper, option, &zv);
    zval_ptr_dtor(&zv);
    return SUCCESS;
}

static zend_result king_server_admin_api_enable_tls(
    zval *zstream,
    const char *function_name
)
{
    zval function_name_zv;
    zval retval;
    zval params[3];
    zend_result status = FAILURE;

    ZVAL_STRING(&function_name_zv, "stream_socket_enable_crypto");
    ZVAL_COPY(&params[0], zstream);
    ZVAL_TRUE(&params[1]);
    ZVAL_LONG(&params[2], STREAM_CRYPTO_METHOD_TLS_SERVER);
    ZVAL_UNDEF(&retval);

    if (call_user_function(
            EG(function_table),
            NULL,
            &function_name_zv,
            &retval,
            3,
            params
        ) == SUCCESS
        && zend_is_true(&retval)) {
        status = SUCCESS;
    } else {
        king_server_control_set_errorf(
            "%s() failed the admin TLS/mTLS handshake.",
            function_name
        );
    }

    zval_ptr_dtor(&params[0]);
    zval_ptr_dtor(&params[1]);
    zval_ptr_dtor(&params[2]);
    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }
    zval_ptr_dtor(&function_name_zv);

    return status;
}

static zend_result king_server_admin_api_capture_peer_subject(
    king_client_session_t *session,
    php_stream *client_stream
)
{
    php_stream_context *context;
    zval *peer_certificate;
    zval function_name_zv;
    zval retval;
    zval params[1];
    zval *subject;
    zval *common_name;

    context = PHP_STREAM_CONTEXT(client_stream);
    if (context == NULL) {
        return SUCCESS;
    }

    peer_certificate = php_stream_context_get_option(
        context,
        "ssl",
        "peer_certificate"
    );
    if (peer_certificate == NULL) {
        return SUCCESS;
    }

    ZVAL_STRING(&function_name_zv, "openssl_x509_parse");
    ZVAL_COPY(&params[0], peer_certificate);
    ZVAL_UNDEF(&retval);

    if (call_user_function(
            EG(function_table),
            NULL,
            &function_name_zv,
            &retval,
            1,
            params
        ) != SUCCESS
        || Z_TYPE(retval) != IS_ARRAY) {
        goto cleanup;
    }

    subject = zend_hash_str_find(
        Z_ARRVAL(retval),
        "subject",
        sizeof("subject") - 1
    );
    if (subject == NULL || Z_TYPE_P(subject) != IS_ARRAY) {
        goto cleanup;
    }

    common_name = zend_hash_str_find(
        Z_ARRVAL_P(subject),
        "CN",
        sizeof("CN") - 1
    );
    if (common_name != NULL && Z_TYPE_P(common_name) == IS_STRING) {
        zend_string *rendered = strpprintf(0, "CN=%s", Z_STRVAL_P(common_name));

        if (rendered != NULL) {
            king_server_session_set_peer_cert_subject(
                session,
                ZSTR_VAL(rendered),
                ZSTR_LEN(rendered)
            );
            zend_string_release(rendered);
        }
    }

cleanup:
    zval_ptr_dtor(&params[0]);
    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }
    zval_ptr_dtor(&function_name_zv);
    return SUCCESS;
}

static zend_result king_server_admin_api_open_listener_stream(
    const king_server_admin_api_config_t *config,
    php_stream **listener_stream_out,
    const char *function_name
)
{
    php_stream_context *context = NULL;
    zend_string *endpoint = NULL;
    zend_string *error_text = NULL;
    int error_code = 0;

    context = php_stream_context_alloc();
    if (context == NULL) {
        king_server_control_set_errorf(
            "%s() failed to allocate the admin listener TLS context.",
            function_name
        );
        return FAILURE;
    }

    king_server_admin_api_context_set_string(context, "ssl", "local_cert", config->cert_file);
    king_server_admin_api_context_set_string(context, "ssl", "local_pk", config->key_file);
    king_server_admin_api_context_set_string(context, "ssl", "cafile", config->ca_file);
    king_server_admin_api_context_set_bool(context, "ssl", "verify_peer", true);
    king_server_admin_api_context_set_bool(context, "ssl", "verify_peer_name", false);
    /* Admin mTLS must stay anchored to the configured CA trust root. */
    king_server_admin_api_context_set_bool(context, "ssl", "allow_self_signed", false);
    king_server_admin_api_context_set_bool(context, "ssl", "capture_peer_cert", true);

    endpoint = strpprintf(
        0,
        "tls://%.*s:" ZEND_LONG_FMT,
        (int) config->bind_host_len,
        config->bind_host,
        config->port
    );
    if (endpoint == NULL) {
        php_stream_context_free(context);
        king_server_control_set_errorf(
            "%s() failed to build the admin listener endpoint string.",
            function_name
        );
        return FAILURE;
    }

    *listener_stream_out = php_stream_xport_create(
        ZSTR_VAL(endpoint),
        ZSTR_LEN(endpoint),
        REPORT_ERRORS,
        STREAM_XPORT_SERVER | STREAM_XPORT_BIND | STREAM_XPORT_LISTEN,
        NULL,
        NULL,
        context,
        &error_text,
        &error_code
    );
    zend_string_release(endpoint);

    if (*listener_stream_out == NULL) {
        if (error_text != NULL && ZSTR_LEN(error_text) > 0) {
            king_server_control_set_errorf(
                "%s() failed to start the admin listener: %s",
                function_name,
                ZSTR_VAL(error_text)
            );
            zend_string_release(error_text);
        } else {
            king_server_control_set_errorf(
                "%s() failed to start the admin listener (error code %d).",
                function_name,
                error_code
            );
        }
        php_stream_context_free(context);
        return FAILURE;
    }

    return SUCCESS;
}

static king_server_admin_api_wait_result_t king_server_admin_api_accept_client_stream(
    php_stream *listener_stream,
    const king_server_admin_api_config_t *config,
    php_stream **client_stream_out,
    const char *function_name
)
{
    struct timeval timeout;
    zend_string *error_text = NULL;
    int accept_result;

    timeout.tv_sec = (time_t) (config->accept_timeout_ms / 1000);
    timeout.tv_usec = (suseconds_t) ((config->accept_timeout_ms % 1000) * 1000);

    accept_result = php_stream_xport_accept(
        listener_stream,
        client_stream_out,
        NULL,
        NULL,
        NULL,
        &timeout,
        &error_text
    );
    if (accept_result == 0 && *client_stream_out != NULL) {
        return KING_SERVER_ADMIN_API_WAIT_READY;
    }

    if (error_text != NULL && ZSTR_LEN(error_text) > 0) {
        king_server_control_set_errorf(
            "%s() failed the admin TLS/mTLS handshake: %s",
            function_name,
            ZSTR_VAL(error_text)
        );
        zend_string_release(error_text);
        return KING_SERVER_ADMIN_API_WAIT_ERROR;
    }

    return KING_SERVER_ADMIN_API_WAIT_TIMEOUT;
}
