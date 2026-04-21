static zend_result king_server_http1_wait_fd(
    int fd,
    short events,
    zend_long deadline_ms,
    const char *function_name,
    const char *scope
)
{
    for (;;) {
        struct pollfd pfd;
        zend_long remaining_ms;
        int poll_result;

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            return FAILURE;
        }

        remaining_ms = deadline_ms - king_server_http1_now_ms();
        if (remaining_ms <= 0) {
            king_server_local_set_errorf(
                "%s() timed out while waiting for the HTTP/1 %s.",
                function_name,
                scope
            );
            return FAILURE;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = fd;
        pfd.events = events;

        poll_result = poll(&pfd, 1, (int) remaining_ms);
        if (poll_result == 0) {
            continue;
        }

        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }

            king_server_local_set_errorf(
                "%s() failed while polling the HTTP/1 %s (errno %d).",
                function_name,
                scope,
                errno
            );
            return FAILURE;
        }

        if ((pfd.revents & events) != 0) {
            return SUCCESS;
        }

        if ((pfd.revents & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            king_server_local_set_errorf(
                "%s() saw the HTTP/1 %s close before it became ready.",
                function_name,
                scope
            );
            return FAILURE;
        }
    }
}

static zend_result king_server_http1_accept_once(
    int listener_fd,
    zend_long deadline_ms,
    int *accepted_fd_out,
    const char *function_name
)
{
    for (;;) {
        if (
            king_server_http1_wait_fd(
                listener_fd,
                POLLIN,
                deadline_ms,
                function_name,
                "accept phase"
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        *accepted_fd_out = accept(listener_fd, NULL, NULL);
        if (*accepted_fd_out >= 0) {
            return SUCCESS;
        }

        if (errno == EINTR) {
            continue;
        }

#ifdef EAGAIN
        if (errno == EAGAIN || errno == EWOULDBLOCK) {
            continue;
        }
#endif

        king_server_local_set_errorf(
            "%s() failed to accept the on-wire HTTP/1 connection (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }
}

static zend_result king_server_http1_write_all_fd(
    int fd,
    const char *buffer,
    size_t buffer_len,
    zend_long deadline_ms,
    const char *function_name
)
{
    size_t written = 0;

    while (written < buffer_len) {
        if (
            king_server_http1_wait_fd(
                fd,
                POLLOUT,
                deadline_ms,
                function_name,
                "response write phase"
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        ssize_t chunk = send(fd, buffer + written, buffer_len - written, 0);

        if (chunk < 0 && errno == EINTR) {
            continue;
        }

#ifdef EAGAIN
        if (chunk < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
            continue;
        }
#endif

        if (chunk <= 0) {
            king_server_local_set_errorf(
                "%s() failed to write the HTTP/1 listener response (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_result king_server_http1_read_exact_fd(
    int fd,
    unsigned char *buffer,
    size_t buffer_len,
    zend_long deadline_ms,
    const char *function_name
)
{
    size_t total = 0;

    while (total < buffer_len) {
        if (
            king_server_http1_wait_fd(
                fd,
                POLLIN,
                deadline_ms,
                function_name,
                "request body phase"
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        ssize_t chunk = recv(fd, buffer + total, buffer_len - total, 0);

        if (chunk < 0 && errno == EINTR) {
            continue;
        }

#ifdef EAGAIN
        if (chunk < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
            continue;
        }
#endif

        if (chunk <= 0) {
            king_server_local_set_errorf(
                "%s() failed to read the active HTTP/1 request body (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        total += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_result king_server_http1_open_listener_socket(
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

    snprintf(port_buffer, sizeof(port_buffer), "%ld", port);
    gai_status = getaddrinfo(host, port_buffer, &hints, &results);
    if (gai_status != 0) {
        king_server_local_set_errorf(
            "%s() failed to resolve the HTTP/1 listen target '%s:%ld' (getaddrinfo code %d).",
            function_name,
            host,
            port,
            gai_status
        );
        return FAILURE;
    }

    for (cursor = results; cursor != NULL; cursor = cursor->ai_next) {
        int reuse_addr = 1;
#ifdef SO_REUSEPORT
        int reuse_port = 1;
#endif

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

#ifdef SO_REUSEPORT
        (void) setsockopt(
            listener_fd,
            SOL_SOCKET,
            SO_REUSEPORT,
            &reuse_port,
            sizeof(reuse_port)
        );
#endif

        if (bind(listener_fd, cursor->ai_addr, cursor->ai_addrlen) == 0) {
            if (listen(listener_fd, SOMAXCONN) == 0) {
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
        "%s() failed to bind the on-wire HTTP/1 listener on '%s:%ld' (errno %d).",
        function_name,
        host,
        port,
        errno
    );
    return FAILURE;
}
