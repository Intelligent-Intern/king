static zend_result king_http1_set_socket_options(
    int fd,
    const king_http1_request_options_t *options)
{
    int enabled;

    enabled = options->tcp_keepalive_enable ? 1 : 0;
    if (setsockopt(fd, SOL_SOCKET, SO_KEEPALIVE, &enabled, sizeof(enabled)) != 0) {
        return FAILURE;
    }

    enabled = options->tcp_nodelay_enable ? 1 : 0;
    if (setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &enabled, sizeof(enabled)) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static void king_http1_pool_entry_destroy(king_http1_pool_entry_t *entry)
{
    if (entry == NULL) {
        return;
    }

    if (entry->fd >= 0) {
        close(entry->fd);
    }
    if (entry->origin != NULL) {
        efree(entry->origin);
    }
    efree(entry);
}

static void king_http1_pool_remove_entry(
    king_http1_pool_entry_t *entry,
    king_http1_pool_entry_t *prev)
{
    if (prev == NULL) {
        king_http1_pool = entry->next;
    } else {
        prev->next = entry->next;
    }

    if (king_http1_pool_size > 0) {
        king_http1_pool_size--;
    }
}

static bool king_http1_socket_is_idle_reusable(int fd)
{
    struct pollfd poll_fd;
    int poll_result;

    memset(&poll_fd, 0, sizeof(poll_fd));
    poll_fd.fd = fd;
    poll_fd.events = POLLIN | POLLERR | POLLHUP;

    poll_result = poll(&poll_fd, 1, 0);
    if (poll_result < 0) {
        return false;
    }

    if (poll_result == 0) {
        return true;
    }

    if ((poll_fd.revents & (POLLERR | POLLHUP | POLLNVAL | POLLIN)) != 0) {
        return false;
    }

    return true;
}

static int king_http1_pool_take_socket(
    const char *origin,
    const king_http1_request_options_t *options)
{
    king_http1_pool_entry_t *entry = king_http1_pool;
    king_http1_pool_entry_t *prev = NULL;

    while (entry != NULL) {
        if (strcmp(entry->origin, origin) == 0) {
            int fd = entry->fd;

            king_http1_pool_remove_entry(entry, prev);
            entry->fd = -1;
            king_http1_pool_entry_destroy(entry);

            if (!king_http1_socket_is_idle_reusable(fd)) {
                close(fd);
                return -1;
            }

            if (king_http1_set_socket_options(fd, options) != SUCCESS) {
                close(fd);
                return -1;
            }

            return fd;
        }

        prev = entry;
        entry = entry->next;
    }

    return -1;
}

static void king_http1_pool_evict_tail(void)
{
    king_http1_pool_entry_t *entry = king_http1_pool;
    king_http1_pool_entry_t *prev = NULL;

    if (entry == NULL) {
        return;
    }

    while (entry->next != NULL) {
        prev = entry;
        entry = entry->next;
    }

    king_http1_pool_remove_entry(entry, prev);
    king_http1_pool_entry_destroy(entry);
}

static void king_http1_pool_store_socket(
    const char *origin,
    int fd)
{
    king_http1_pool_entry_t *entry;
    king_http1_pool_entry_t *cursor = king_http1_pool;
    king_http1_pool_entry_t *prev = NULL;

    while (cursor != NULL) {
        if (strcmp(cursor->origin, origin) == 0) {
            king_http1_pool_remove_entry(cursor, prev);
            king_http1_pool_entry_destroy(cursor);
            break;
        }

        prev = cursor;
        cursor = cursor->next;
    }

    entry = ecalloc(1, sizeof(*entry));
    entry->origin = estrdup(origin);
    entry->fd = fd;
    entry->next = king_http1_pool;
    king_http1_pool = entry;
    king_http1_pool_size++;

    while (king_http1_pool_size > KING_HTTP1_MAX_IDLE_CONNECTIONS) {
        king_http1_pool_evict_tail();
    }
}

static void king_http1_pool_cleanup(void)
{
    king_http1_pool_entry_t *entry = king_http1_pool;

    while (entry != NULL) {
        king_http1_pool_entry_t *next = entry->next;
        king_http1_pool_entry_destroy(entry);
        entry = next;
    }

    king_http1_pool = NULL;
    king_http1_pool_size = 0;
}

static void king_http1_request_context_release_socket(
    king_http1_request_context *context)
{
    bool keep_alive_reusable;

    if (context == NULL || context->fd < 0) {
        return;
    }

    keep_alive_reusable = context->request_allows_keep_alive
        && king_http1_response_allows_keep_alive(&context->meta)
        && king_http1_response_is_self_delimited(&context->meta, context->expect_no_body);

    if (context->response_complete && keep_alive_reusable && context->origin != NULL) {
        king_http1_pool_store_socket(context->origin, context->fd);
    } else {
        close(context->fd);
    }

    context->fd = -1;
}

void king_http1_request_context_free(king_http1_request_context *context)
{
    if (context == NULL) {
        return;
    }

    if (context->fd >= 0) {
        close(context->fd);
        context->fd = -1;
    }
    if (context->origin != NULL) {
        efree(context->origin);
        context->origin = NULL;
    }
    if (context->effective_url != NULL) {
        zend_string_release(context->effective_url);
        context->effective_url = NULL;
    }

    king_http1_response_meta_destroy(&context->meta);
    smart_str_free(&context->header_buffer);
    smart_str_free(&context->chunk_buffer);
    smart_str_free(&context->body_buffer);
    if (!Z_ISUNDEF(context->pending_early_hints)) {
        zval_ptr_dtor(&context->pending_early_hints);
        ZVAL_UNDEF(&context->pending_early_hints);
    }
    efree(context);
}

void king_http1_pool_request_shutdown(void)
{
    king_http1_pool_cleanup();
}

void king_http1_pool_module_shutdown(void)
{
    king_http1_pool_cleanup();
}

static zend_long king_http1_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static zend_result king_http1_wait_fd(
    int fd,
    short events,
    zend_long timeout_ms,
    zval *cancel_token,
    const char *cancel_function_name,
    zend_class_entry *cancel_exception_ce,
    const char *function_name,
    const char *scope)
{
    struct pollfd poll_fd;
    zend_long deadline_ms = king_http1_now_ms() + timeout_ms;

    for (;;) {
        zend_long remaining_ms;
        zend_long poll_timeout_ms;
        int poll_result;

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            return FAILURE;
        }
        if (king_transport_maybe_throw_cancel(
                cancel_token,
                function_name,
                cancel_function_name,
                cancel_exception_ce,
                "HTTP/1"
            ) != SUCCESS) {
            return FAILURE;
        }

        remaining_ms = deadline_ms - king_http1_now_ms();
        if (remaining_ms <= 0) {
            king_http1_throw(
                king_ce_timeout_exception,
                "%s() timed out while waiting for the HTTP/1 %s phase.",
                function_name,
                scope
            );
            return FAILURE;
        }

        poll_timeout_ms = remaining_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS
            ? KING_TRANSPORT_INTERRUPT_SLICE_MS
            : remaining_ms;

        memset(&poll_fd, 0, sizeof(poll_fd));
        poll_fd.fd = fd;
        poll_fd.events = events;

        poll_result = poll(&poll_fd, 1, (int) poll_timeout_ms);
        if (poll_result == 0) {
            continue;
        }

        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }

            king_http1_throw(
                king_ce_network_exception,
                "%s() poll() failed during the HTTP/1 %s phase (errno %d).",
                function_name,
                scope,
                errno
            );
            return FAILURE;
        }

        if ((poll_fd.revents & (POLLERR | POLLNVAL)) != 0) {
            int socket_error = 0;
            socklen_t socket_error_len = sizeof(socket_error);

            if (getsockopt(fd, SOL_SOCKET, SO_ERROR, &socket_error, &socket_error_len) != 0) {
                socket_error = errno;
            }

            king_http1_throw(
                king_ce_network_exception,
                "%s() the HTTP/1 socket entered an error state during the %s phase (errno %d).",
                function_name,
                scope,
                socket_error
            );
            return FAILURE;
        }

        if ((poll_fd.revents & events) == 0 && (poll_fd.revents & POLLHUP) != 0) {
            king_http1_throw(
                king_ce_network_exception,
                "%s() the HTTP/1 peer closed the socket during the %s phase.",
                function_name,
                scope
            );
            return FAILURE;
        }

        return SUCCESS;
    }
}

static zend_result king_http1_open_socket(
    const char *host,
    zend_long port,
    const king_http1_request_options_t *options,
    const char *function_name,
    int *fd_out)
{
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *cursor;
    char port_buffer[6];
    int gai_status;
    int last_errno = 0;

    *fd_out = -1;

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    hints.ai_flags = AI_ADDRCONFIG;

    snprintf(port_buffer, sizeof(port_buffer), "%ld", port);
    gai_status = getaddrinfo(host, port_buffer, &hints, &results);
    if (gai_status != 0) {
        king_http1_throw(
            king_ce_network_exception,
            "%s() failed to resolve HTTP/1 target host '%s' (getaddrinfo code %d).",
            function_name,
            host,
            gai_status
        );
        return FAILURE;
    }

    for (cursor = results; cursor != NULL; cursor = cursor->ai_next) {
        int fd;
        int flags;
        int connect_result;

        fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (fd < 0) {
            last_errno = errno;
            continue;
        }

        flags = fcntl(fd, F_GETFL, 0);
        if (flags < 0 || fcntl(fd, F_SETFL, flags | O_NONBLOCK) != 0) {
            last_errno = errno;
            close(fd);
            continue;
        }

        if (king_http1_set_socket_options(fd, options) != SUCCESS) {
            last_errno = errno;
            close(fd);
            continue;
        }

        connect_result = connect(fd, cursor->ai_addr, cursor->ai_addrlen);
        if (connect_result == 0) {
            *fd_out = fd;
            freeaddrinfo(results);
            return SUCCESS;
        }

        if (connect_result != 0 && errno == EINPROGRESS) {
            int socket_error = 0;
            socklen_t socket_error_len = sizeof(socket_error);

                if (king_http1_wait_fd(
                        fd,
                        POLLOUT,
                        options->connect_timeout_ms,
                        options->cancel_token,
                        options->cancel_function_name,
                        options->cancel_exception_ce,
                        function_name,
                        "connect"
                    ) == SUCCESS
                && getsockopt(fd, SOL_SOCKET, SO_ERROR, &socket_error, &socket_error_len) == 0
                && socket_error == 0) {
                *fd_out = fd;
                freeaddrinfo(results);
                return SUCCESS;
            }

            last_errno = socket_error != 0 ? socket_error : errno;
            close(fd);

            if (EG(exception) != NULL) {
                freeaddrinfo(results);
                return FAILURE;
            }

            continue;
        }

        last_errno = errno;
        close(fd);
    }

    freeaddrinfo(results);

    king_http1_throw(
        king_ce_network_exception,
        "%s() failed to connect a TCP socket to '%s:%ld' for the HTTP/1 runtime (errno %d).",
        function_name,
        host,
        port,
        last_errno
    );
    return FAILURE;
}
