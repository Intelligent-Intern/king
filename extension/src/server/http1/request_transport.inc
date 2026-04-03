static void king_server_http1_build_request(
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
    add_assoc_string(request, "version", "HTTP/1.1");
    add_assoc_str(request, "host", zend_string_copy(authority));

    array_init(&headers);
    add_assoc_str(&headers, "Host", authority);
    add_assoc_string(&headers, "Connection", "close");
    add_assoc_zval(request, "headers", &headers);
    add_assoc_string(request, "body", "");

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/1.1",
        "http",
        0
    );
}

static const char *king_server_http1_status_text(zend_long status)
{
    switch (status) {
        case 101:
            return "Switching Protocols";
        case 200:
            return "OK";
        case 201:
            return "Created";
        case 202:
            return "Accepted";
        case 204:
            return "No Content";
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

static const char *king_server_http1_skip_space(const char *value)
{
    while (*value == ' ' || *value == '\t') {
        value++;
    }

    return value;
}

static size_t king_server_http1_trim_len(const char *value, size_t value_len)
{
    while (value_len > 0) {
        char c = value[value_len - 1];

        if (c != ' ' && c != '\t' && c != '\r' && c != '\n') {
            break;
        }

        value_len--;
    }

    return value_len;
}

static zend_bool king_server_http1_header_value_has_token(
    const char *value,
    size_t value_len,
    const char *token
)
{
    const char *cursor = value;
    size_t token_len = strlen(token);

    while ((size_t) (cursor - value) < value_len) {
        const char *segment_start = cursor;
        size_t segment_len;

        while (
            (size_t) (cursor - value) < value_len
            && *cursor != ','
        ) {
            cursor++;
        }

        while (
            segment_start < cursor
            && (*segment_start == ' ' || *segment_start == '\t')
        ) {
            segment_start++;
        }

        segment_len = king_server_http1_trim_len(
            segment_start,
            (size_t) (cursor - segment_start)
        );
        if (
            segment_len == token_len
            && strncasecmp(segment_start, token, token_len) == 0
        ) {
            return 1;
        }

        if ((size_t) (cursor - value) < value_len && *cursor == ',') {
            cursor++;
        }
    }

    return 0;
}

static zend_result king_server_http1_set_socket_endpoint(
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
            "%s() failed to capture the accepted HTTP/1 socket endpoints (errno %d).",
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
            "%s() failed to normalize the accepted HTTP/1 socket endpoints.",
            function_name
        );
        return FAILURE;
    }

    king_server_local_set_string(slot, host);
    *port_slot = strtol(service, NULL, 10);
    return SUCCESS;
}

static zend_result king_server_http1_apply_transport_snapshot_from_socket(
    king_client_session_t *session,
    int socket_fd,
    const char *function_name
)
{
    struct sockaddr_storage address;
    socklen_t address_len = sizeof(address);

    session->transport_socket_fd = socket_fd;
    session->transport_has_socket = true;
    session->transport_last_errno = 0;
    session->transport_datagrams_enable = false;
    king_server_local_set_string(&session->negotiated_alpn, "http/1.1");
    king_server_local_set_string(&session->transport_backend, "server_http1_socket");
    king_server_local_set_string(&session->transport_error_scope, "none");

    if (
        king_server_http1_set_socket_endpoint(
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
        king_server_http1_set_socket_endpoint(
            &session->transport_peer_address,
            &session->transport_peer_port,
            socket_fd,
            1,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (getsockname(socket_fd, (struct sockaddr *) &address, &address_len) == 0) {
        switch (address.ss_family) {
            case AF_INET:
                king_server_local_set_string(&session->transport_socket_family, "ipv4");
                break;
            case AF_INET6:
                king_server_local_set_string(&session->transport_socket_family, "ipv6");
                break;
            default:
                king_server_local_set_string(&session->transport_socket_family, "tcp");
                break;
        }
    }

    return SUCCESS;
}

static zend_long king_server_http1_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static zend_long king_server_http1_resolve_timeout_ms(king_client_session_t *session)
{
    if (session != NULL && session->transport_connect_timeout_ms > 0) {
        return session->transport_connect_timeout_ms;
    }

    if (king_tcp_transport_config.connect_timeout_ms > 0) {
        return king_tcp_transport_config.connect_timeout_ms;
    }

    return KING_SERVER_HTTP1_DEFAULT_TIMEOUT_MS;
}

