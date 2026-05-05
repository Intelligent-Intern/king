static void king_server_http2_build_request(
    zval *request,
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port
)
{
    zval headers;
    zend_string *authority;

    authority = strpprintf(0, "%.*s:" ZEND_LONG_FMT, (int) host_len, host, port);

    array_init(request);
    add_assoc_string(request, "method", "GET");
    add_assoc_string(request, "uri", "/");
    add_assoc_string(request, "version", "HTTP/2");
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
        "http/2",
        "https",
        1
    );
}

static zend_string *king_server_http2_build_authority(
    const char *host,
    size_t host_len,
    zend_long port
)
{
    if (
        memchr(host, ':', host_len) != NULL
        && !(host_len >= 2 && host[0] == '[' && host[host_len - 1] == ']')
    ) {
        return strpprintf(0, "[%.*s]:" ZEND_LONG_FMT, (int) host_len, host, port);
    }

    return strpprintf(0, "%.*s:" ZEND_LONG_FMT, (int) host_len, host, port);
}

static zend_result king_server_http2_set_socket_endpoint(
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
            "%s() failed to capture the accepted HTTP/2 socket endpoints (errno %d).",
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
            "%s() failed to normalize the accepted HTTP/2 socket endpoints.",
            function_name
        );
        return FAILURE;
    }

    king_server_local_set_string(slot, host);
    *port_slot = strtol(service, NULL, 10);
    return SUCCESS;
}

static zend_result king_server_http2_apply_transport_snapshot_from_socket(
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
    king_server_local_set_string(&session->negotiated_alpn, "h2c");
    king_server_local_set_string(&session->transport_backend, "server_http2_socket");
    king_server_local_set_string(&session->transport_error_scope, "none");

    if (
        king_server_http2_set_socket_endpoint(
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
        king_server_http2_set_socket_endpoint(
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

static zend_result king_server_http2_write_all_fd(
    int fd,
    const unsigned char *buffer,
    size_t buffer_len,
    const char *function_name
)
{
    size_t written = 0;

    while (written < buffer_len) {
        ssize_t chunk = send(fd, buffer + written, buffer_len - written, 0);

        if (chunk <= 0) {
            king_server_local_set_errorf(
                "%s() failed to write the HTTP/2 listener response (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_result king_server_http2_read_exact_fd(
    int fd,
    unsigned char *buffer,
    size_t buffer_len,
    const char *function_name
)
{
    size_t total = 0;

    while (total < buffer_len) {
        ssize_t chunk = recv(fd, buffer + total, buffer_len - total, 0);

        if (chunk <= 0) {
            king_server_local_set_errorf(
                "%s() failed to read the active HTTP/2 wire bytes (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        total += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_result king_server_http2_open_listener_socket(
    const char *host,
    zend_long port,
    int *listener_fd_out,
    const char *function_name
)
{
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *cursor;
    char port_buffer[16];
    int listener_fd = -1;
    int gai_status;

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    hints.ai_flags = AI_ADDRCONFIG | AI_NUMERICSERV;

    snprintf(port_buffer, sizeof(port_buffer), ZEND_LONG_FMT, port);
    gai_status = getaddrinfo(host, port_buffer, &hints, &results);
    if (gai_status != 0) {
        king_server_local_set_errorf(
            "%s() failed to resolve the HTTP/2 listen target '%s:" ZEND_LONG_FMT "' (getaddrinfo code %d).",
            function_name,
            host,
            port,
            gai_status
        );
        return FAILURE;
    }

    for (cursor = results; cursor != NULL; cursor = cursor->ai_next) {
        int reuse_addr = 1;

        listener_fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (listener_fd < 0) {
            continue;
        }

        (void) setsockopt(
            listener_fd,
            SOL_SOCKET,
            SO_REUSEADDR,
            &reuse_addr,
            sizeof(reuse_addr)
        );

        if (bind(listener_fd, cursor->ai_addr, cursor->ai_addrlen) == 0) {
            if (listen(listener_fd, 1) == 0) {
                *listener_fd_out = listener_fd;
                freeaddrinfo(results);
                return SUCCESS;
            }
        }

        close(listener_fd);
        listener_fd = -1;
    }

    freeaddrinfo(results);
    king_server_local_set_errorf(
        "%s() failed to bind the on-wire HTTP/2 listener on '%s:" ZEND_LONG_FMT "' (errno %d).",
        function_name,
        host,
        port,
        errno
    );
    return FAILURE;
}

static void king_server_http2_request_state_init(
    king_server_http2_request_state *state
)
{
    memset(state, 0, sizeof(*state));
    ZVAL_UNDEF(&state->headers);
}

static void king_server_http2_request_state_dtor(
    king_server_http2_request_state *state
)
{
    if (state->headers_initialized) {
        zval_ptr_dtor(&state->headers);
        state->headers_initialized = 0;
    }

    if (state->body.s != NULL) {
        smart_str_free(&state->body);
    }

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
}
