static void king_server_admin_api_trim_line(char *line, size_t *line_len)
{
    while (*line_len > 0) {
        char c = line[*line_len - 1];

        if (c != '\r' && c != '\n') {
            break;
        }

        line[--(*line_len)] = '\0';
    }
}

static const char *king_server_admin_api_skip_space(const char *value)
{
    while (*value == ' ' || *value == '\t') {
        value++;
    }

    return value;
}

static size_t king_server_admin_api_trim_value_len(
    const char *value,
    size_t value_len
)
{
    while (value_len > 0) {
        char c = value[value_len - 1];

        if (c != ' ' && c != '\t') {
            break;
        }

        value_len--;
    }

    return value_len;
}

static zend_result king_server_admin_api_copy_header_value(
    char *dest,
    size_t dest_size,
    size_t *dest_len,
    const char *value,
    size_t value_len,
    char *error_message,
    size_t error_message_size,
    const char *header_name
)
{
    if (value_len == 0) {
        snprintf(
            error_message,
            error_message_size,
            "admin header '%s' must not be empty.",
            header_name
        );
        return FAILURE;
    }

    if (value_len >= dest_size) {
        snprintf(
            error_message,
            error_message_size,
            "admin header '%s' is too large.",
            header_name
        );
        return FAILURE;
    }

    memcpy(dest, value, value_len);
    dest[value_len] = '\0';
    *dest_len = value_len;
    return SUCCESS;
}

static void king_server_admin_api_request_init(
    king_server_admin_api_request_t *request
)
{
    memset(request, 0, sizeof(*request));
    request->route = KING_SERVER_ADMIN_API_ROUTE_UNKNOWN;
}

static zend_result king_server_admin_api_parse_request(
    php_stream *client_stream,
    king_server_admin_api_request_t *request,
    char *error_message,
    size_t error_message_size
)
{
    char line[KING_SERVER_ADMIN_API_MAX_REQUEST_LINE_BYTES];
    size_t line_len = 0;
    size_t total_bytes = 0;
    char *method_end;
    char *path_end;

    king_server_admin_api_request_init(request);

    if (php_stream_get_line(client_stream, line, sizeof(line), &line_len) == NULL) {
        snprintf(
            error_message,
            error_message_size,
            "failed to read the admin request line."
        );
        return FAILURE;
    }

    total_bytes += line_len;
    king_server_admin_api_trim_line(line, &line_len);
    if (line_len == 0) {
        snprintf(
            error_message,
            error_message_size,
            "received an empty admin request line."
        );
        return FAILURE;
    }

    method_end = strchr(line, ' ');
    if (method_end == NULL || method_end == line) {
        snprintf(
            error_message,
            error_message_size,
            "received an invalid admin request method."
        );
        return FAILURE;
    }

    path_end = strchr(method_end + 1, ' ');
    if (path_end == NULL || path_end == method_end + 1) {
        snprintf(
            error_message,
            error_message_size,
            "received an invalid admin request target."
        );
        return FAILURE;
    }

    if ((size_t) (method_end - line) >= sizeof(request->method)
        || (size_t) (path_end - (method_end + 1)) >= sizeof(request->path)) {
        snprintf(
            error_message,
            error_message_size,
            "received an oversized admin request line."
        );
        return FAILURE;
    }

    memcpy(request->method, line, (size_t) (method_end - line));
    request->method[method_end - line] = '\0';
    memcpy(
        request->path,
        method_end + 1,
        (size_t) (path_end - (method_end + 1))
    );
    request->path[path_end - (method_end + 1)] = '\0';

    if (strcmp(request->method, "GET") == 0
        && (strcmp(request->path, "/") == 0
            || strcmp(request->path, "/health") == 0)) {
        request->route = KING_SERVER_ADMIN_API_ROUTE_HEALTH;
    } else if (strcmp(request->method, "POST") == 0
        && strcmp(request->path, "/reload-tls") == 0) {
        request->route = KING_SERVER_ADMIN_API_ROUTE_RELOAD_TLS;
    }

    for (;;) {
        char *colon;
        const char *value;
        size_t value_len;

        if (php_stream_get_line(client_stream, line, sizeof(line), &line_len) == NULL) {
            snprintf(
                error_message,
                error_message_size,
                "failed while reading the admin request headers."
            );
            return FAILURE;
        }

        total_bytes += line_len;
        if (total_bytes > KING_SERVER_ADMIN_API_MAX_REQUEST_HEAD_BYTES) {
            snprintf(
                error_message,
                error_message_size,
                "received an admin request head larger than %d bytes.",
                KING_SERVER_ADMIN_API_MAX_REQUEST_HEAD_BYTES
            );
            return FAILURE;
        }

        king_server_admin_api_trim_line(line, &line_len);
        if (line_len == 0) {
            break;
        }

        colon = strchr(line, ':');
        if (colon == NULL || colon == line) {
            snprintf(
                error_message,
                error_message_size,
                "received an invalid admin header line."
            );
            return FAILURE;
        }

        value = king_server_admin_api_skip_space(colon + 1);
        value_len = king_server_admin_api_trim_value_len(
            value,
            strlen(value)
        );

        if (strncasecmp(line, "X-King-TLS-Cert-File", sizeof("X-King-TLS-Cert-File") - 1) == 0
            && (size_t) (colon - line) == sizeof("X-King-TLS-Cert-File") - 1) {
            if (king_server_admin_api_copy_header_value(
                    request->reload_cert_file,
                    sizeof(request->reload_cert_file),
                    &request->reload_cert_file_len,
                    value,
                    value_len,
                    error_message,
                    error_message_size,
                    "X-King-TLS-Cert-File"
                ) != SUCCESS) {
                return FAILURE;
            }
        } else if (strncasecmp(line, "X-King-TLS-Key-File", sizeof("X-King-TLS-Key-File") - 1) == 0
            && (size_t) (colon - line) == sizeof("X-King-TLS-Key-File") - 1) {
            if (king_server_admin_api_copy_header_value(
                    request->reload_key_file,
                    sizeof(request->reload_key_file),
                    &request->reload_key_file_len,
                    value,
                    value_len,
                    error_message,
                    error_message_size,
                    "X-King-TLS-Key-File"
                ) != SUCCESS) {
                return FAILURE;
            }
        }
    }

    return SUCCESS;
}

static const char *king_server_admin_api_status_text(zend_long status)
{
    switch (status) {
        case 200:
            return "OK";
        case 400:
            return "Bad Request";
        case 404:
            return "Not Found";
        case 500:
            return "Internal Server Error";
        default:
            return "OK";
    }
}

static zend_result king_server_admin_api_write_response(
    php_stream *client_stream,
    zend_long status,
    const char *body,
    const char *function_name
)
{
    zend_string *response;
    size_t total = 0;
    size_t response_len;

    response = strpprintf(
        0,
        "HTTP/1.1 %ld %s\r\n"
        "Content-Type: text/plain\r\n"
        "Content-Length: %zu\r\n"
        "Connection: close\r\n\r\n"
        "%s",
        status,
        king_server_admin_api_status_text(status),
        body != NULL ? strlen(body) : 0,
        body != NULL ? body : ""
    );
    if (response == NULL) {
        king_server_control_set_errorf(
            "%s() failed to build the admin response payload.",
            function_name
        );
        return FAILURE;
    }

    response_len = ZSTR_LEN(response);
    while (total < response_len) {
        ssize_t written = php_stream_write(
            client_stream,
            ZSTR_VAL(response) + total,
            response_len - total
        );

        if (written <= 0) {
            zend_string_release(response);
            king_server_control_set_errorf(
                "%s() failed while writing the admin response.",
                function_name
            );
            return FAILURE;
        }

        total += (size_t) written;
    }

    (void) php_stream_flush(client_stream);
    zend_string_release(response);
    return SUCCESS;
}

static zend_result king_server_admin_api_handle_request(
    php_stream *client_stream,
    king_client_session_t *session,
    const char *function_name
)
{
    king_server_admin_api_request_t request;
    char request_error[KING_ERR_LEN];

    request_error[0] = '\0';
    if (king_server_admin_api_parse_request(
            client_stream,
            &request,
            request_error,
            sizeof(request_error)
        ) != SUCCESS) {
        return king_server_admin_api_write_response(
            client_stream,
            400,
            request_error,
            function_name
        );
    }

    if (request.route == KING_SERVER_ADMIN_API_ROUTE_HEALTH) {
        session->last_activity_at = time(NULL);
        king_set_error("");
        return king_server_admin_api_write_response(
            client_stream,
            200,
            "admin listener ready\n",
            function_name
        );
    }

    if (request.route == KING_SERVER_ADMIN_API_ROUTE_RELOAD_TLS) {
        const char *reload_error;

        if (request.reload_cert_file_len == 0 || request.reload_key_file_len == 0) {
            return king_server_admin_api_write_response(
                client_stream,
                400,
                "reload-tls requires X-King-TLS-Cert-File and X-King-TLS-Key-File.\n",
                function_name
            );
        }

        if (king_server_tls_reload_paths(
                session,
                request.reload_cert_file,
                request.reload_cert_file_len,
                request.reload_key_file,
                request.reload_key_file_len,
                "king_admin_api_listen"
            ) != SUCCESS) {
            reload_error = king_get_error();
            if (reload_error == NULL || reload_error[0] == '\0') {
                reload_error = "admin-triggered TLS reload failed.\n";
            }
            if (king_server_admin_api_write_response(
                    client_stream,
                    400,
                    reload_error,
                    function_name
                ) != SUCCESS) {
                return FAILURE;
            }

            king_set_error("");
            return SUCCESS;
        }

        session->last_activity_at = time(NULL);
        king_set_error("");
        return king_server_admin_api_write_response(
            client_stream,
            200,
            "tls reloaded\n",
            function_name
        );
    }

    return king_server_admin_api_write_response(
        client_stream,
        404,
        "unknown admin route\n",
        function_name
    );
}

static zend_result king_server_admin_api_maybe_serve_request(
    king_client_session_t *session,
    const king_server_admin_api_config_t *config,
    const char *function_name
)
{
    struct timeval read_timeout;
    php_stream *listener_stream = NULL;
    php_stream *client_stream = NULL;
    zend_result status = FAILURE;
    king_server_admin_api_wait_result_t accept_result;
    zend_long io_timeout_ms;

    if (config->accept_timeout_ms <= 0) {
        return SUCCESS;
    }

    if (king_server_admin_api_open_listener_stream(
            config,
            &listener_stream,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    accept_result = king_server_admin_api_accept_client_stream(
        listener_stream,
        config,
        &client_stream,
        function_name
    );
    if (accept_result == KING_SERVER_ADMIN_API_WAIT_TIMEOUT) {
        status = SUCCESS;
        goto cleanup;
    }
    if (accept_result != KING_SERVER_ADMIN_API_WAIT_READY) {
        goto cleanup;
    }

    io_timeout_ms = config->accept_timeout_ms > KING_SERVER_ADMIN_API_DEFAULT_IO_TIMEOUT_MS
        ? config->accept_timeout_ms
        : KING_SERVER_ADMIN_API_DEFAULT_IO_TIMEOUT_MS;
    read_timeout.tv_sec = (time_t) (io_timeout_ms / 1000);
    read_timeout.tv_usec = (suseconds_t) ((io_timeout_ms % 1000) * 1000);
    (void) php_stream_set_option(
        client_stream,
        PHP_STREAM_OPTION_BLOCKING,
        1,
        NULL
    );
    (void) php_stream_set_option(
        client_stream,
        PHP_STREAM_OPTION_READ_TIMEOUT,
        0,
        &read_timeout
    );

    (void) king_server_admin_api_capture_peer_subject(session, client_stream);
    if (king_server_admin_api_handle_request(
            client_stream,
            session,
            function_name
        ) != SUCCESS) {
        goto cleanup;
    }

    status = SUCCESS;

cleanup:
    if (client_stream != NULL) {
        php_stream_close(client_stream);
    }
    if (listener_stream != NULL) {
        php_stream_close(listener_stream);
    }
    return status;
}

