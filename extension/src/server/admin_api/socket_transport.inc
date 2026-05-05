static void king_server_admin_api_apply_session_snapshot(
    king_client_session_t *session,
    const king_server_admin_api_config_t *config
)
{
    bool was_active = session->server_admin_api_active;

    session->server_admin_api_active = true;
    session->server_admin_api_listen_count++;
    if (was_active) {
        session->server_admin_api_reload_count++;
    }

    session->server_last_admin_api_port = config->port;
    session->server_last_admin_api_mtls_ready = true;
    session->last_activity_at = time(NULL);

    king_server_control_set_string_bytes(
        &session->server_last_admin_api_bind_host,
        config->bind_host,
        config->bind_host_len
    );
    king_server_control_set_string_bytes(
        &session->server_last_admin_api_auth_mode,
        config->auth_mode,
        config->auth_mode_len
    );
}

static zend_long king_server_admin_api_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static king_server_admin_api_wait_result_t king_server_admin_api_wait_fd(
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
            return KING_SERVER_ADMIN_API_WAIT_ERROR;
        }

        remaining_ms = deadline_ms - king_server_admin_api_now_ms();
        if (remaining_ms <= 0) {
            return KING_SERVER_ADMIN_API_WAIT_TIMEOUT;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = fd;
        pfd.events = events;

        poll_result = poll(&pfd, 1, (int) remaining_ms);
        if (poll_result == 0) {
            return KING_SERVER_ADMIN_API_WAIT_TIMEOUT;
        }

        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }

            king_server_control_set_errorf(
                "%s() failed while polling the admin listener %s (errno %d).",
                function_name,
                scope,
                errno
            );
            return KING_SERVER_ADMIN_API_WAIT_ERROR;
        }

        if ((pfd.revents & events) != 0) {
            return KING_SERVER_ADMIN_API_WAIT_READY;
        }

        if ((pfd.revents & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            king_server_control_set_errorf(
                "%s() saw the admin listener %s close before it became ready.",
                function_name,
                scope
            );
            return KING_SERVER_ADMIN_API_WAIT_ERROR;
        }
    }
}

static zend_result king_server_admin_api_open_listener_socket(
    const king_server_admin_api_config_t *config,
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

    snprintf(port_buffer, sizeof(port_buffer), ZEND_LONG_FMT, config->port);
    gai_status = getaddrinfo(config->bind_host, port_buffer, &hints, &results);
    if (gai_status != 0) {
        king_server_control_set_errorf(
            "%s() failed to resolve the admin listen target '%s:" ZEND_LONG_FMT "' (getaddrinfo code %d).",
            function_name,
            config->bind_host,
            config->port,
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
    king_server_control_set_errorf(
        "%s() failed to bind the admin listener on '%s:" ZEND_LONG_FMT "' (errno %d).",
        function_name,
        config->bind_host,
        config->port,
        errno
    );
    return FAILURE;
}

static king_server_admin_api_wait_result_t king_server_admin_api_accept_once(
    int listener_fd,
    zend_long deadline_ms,
    int *accepted_fd_out,
    const char *function_name
)
{
    for (;;) {
        king_server_admin_api_wait_result_t wait_result =
            king_server_admin_api_wait_fd(
                listener_fd,
                POLLIN,
                deadline_ms,
                function_name,
                "accept phase"
            );

        if (wait_result != KING_SERVER_ADMIN_API_WAIT_READY) {
            return wait_result;
        }

        *accepted_fd_out = accept(listener_fd, NULL, NULL);
        if (*accepted_fd_out >= 0) {
            return KING_SERVER_ADMIN_API_WAIT_READY;
        }

        if (errno == EINTR) {
            continue;
        }

#ifdef EAGAIN
        if (errno == EAGAIN || errno == EWOULDBLOCK) {
            continue;
        }
#endif

        king_server_control_set_errorf(
            "%s() failed to accept the admin connection (errno %d).",
            function_name,
            errno
        );
        return KING_SERVER_ADMIN_API_WAIT_ERROR;
    }
}
