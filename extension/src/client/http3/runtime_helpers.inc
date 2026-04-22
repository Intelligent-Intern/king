static void king_http3_response_destroy(king_http3_response_t *response)
{
    if (response->headers_initialized) {
        zval_ptr_dtor(&response->headers);
    }
    if (response->status_line != NULL) {
        zend_string_release(response->status_line);
    }
    smart_str_free(&response->body);
}

static void king_http3_multi_request_destroy(king_http3_multi_request_t *request)
{
    if (request == NULL) {
        return;
    }

    if (request->url != NULL) {
        zend_string_release(request->url);
    }
    if (request->method != NULL) {
        zend_string_release(request->method);
    }
    if (request->body_string != NULL) {
        zend_string_release(request->body_string);
    }
    if (request->effective_headers_initialized) {
        zval_ptr_dtor(&request->effective_headers);
    }

    king_http3_free_request_headers(
        request->request_headers,
        request->owned_strings,
        request->owned_string_count
    );
    king_http3_request_target_destroy(request->parsed_url, &request->target);
    king_http3_response_destroy(&request->response);
}

static void king_http3_multi_requests_destroy(
    king_http3_multi_request_t *requests,
    size_t request_count)
{
    size_t i;

    if (requests == NULL) {
        return;
    }

    for (i = 0; i < request_count; ++i) {
        king_http3_multi_request_destroy(&requests[i]);
    }

    efree(requests);
}

static void king_http3_runtime_destroy(king_http3_request_runtime_t *runtime)
{
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    if (runtime->lsquic_backend_active) {
        king_http3_lsquic_runtime_destroy(runtime);
    }
#endif

    if (runtime->h3_conn != NULL && king_http3_quiche.quiche_h3_conn_free_fn != NULL) {
        king_http3_quiche.quiche_h3_conn_free_fn(runtime->h3_conn);
    }
    if (runtime->h3_config != NULL && king_http3_quiche.quiche_h3_config_free_fn != NULL) {
        king_http3_quiche.quiche_h3_config_free_fn(runtime->h3_config);
    }
    if (runtime->conn != NULL && king_http3_quiche.quiche_conn_free_fn != NULL) {
        king_http3_quiche.quiche_conn_free_fn(runtime->conn);
    }
    if (runtime->config != NULL && king_http3_quiche.quiche_config_free_fn != NULL) {
        king_http3_quiche.quiche_config_free_fn(runtime->config);
    }
    if (runtime->socket_fd >= 0) {
        close(runtime->socket_fd);
    }
}

static zend_result king_http3_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    ssize_t got;

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    got = read(fd, target, target_len);
    close(fd);

    return got == (ssize_t) target_len ? SUCCESS : FAILURE;
}

static zend_long king_http3_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static zend_result king_http3_make_socket_nonblocking(int socket_fd)
{
    int flags = fcntl(socket_fd, F_GETFL, 0);

    if (flags < 0) {
        return FAILURE;
    }

    return fcntl(socket_fd, F_SETFL, flags | O_NONBLOCK) == 0
        ? SUCCESS
        : FAILURE;
}

static void king_http3_seed_ticket_from_ring(king_http3_request_runtime_t *runtime)
{
    uint8_t ticket_buffer[KING_MAX_TICKET_SIZE];
    size_t ticket_len = sizeof(ticket_buffer);

#if defined(KING_HTTP3_BACKEND_LSQUIC)
    if (runtime != NULL && runtime->lsquic_backend_active) {
        king_http3_lsquic_seed_ticket_from_ring(runtime);
        return;
    }
#endif

    if (runtime == NULL
        || runtime->conn == NULL
        || king_http3_quiche.quiche_conn_set_session_fn == NULL) {
        return;
    }

    if (king_ticket_ring_get(ticket_buffer, &ticket_len) == 1
        && king_http3_quiche.quiche_conn_set_session_fn(
            runtime->conn,
            ticket_buffer,
            ticket_len
        ) == 0) {
        runtime->tls_ticket_source = "ring";
    }

    king_secure_zero(ticket_buffer, sizeof(ticket_buffer));
}

static void king_http3_refresh_tls_state(king_http3_request_runtime_t *runtime)
{
    const uint8_t *session_bytes = NULL;
    size_t session_len = 0;

    if (runtime == NULL) {
        return;
    }

    king_http3_refresh_transport_stats(runtime);

#if defined(KING_HTTP3_BACKEND_LSQUIC)
    if (runtime->lsquic_backend_active) {
        return;
    }
#endif

    if (runtime->conn == NULL) {
        return;
    }

    if (king_http3_quiche.quiche_conn_is_resumed_fn != NULL) {
        runtime->tls_session_resumed =
            king_http3_quiche.quiche_conn_is_resumed_fn(runtime->conn);
    }

    if (king_http3_quiche.quiche_conn_session_fn == NULL) {
        return;
    }

    king_http3_quiche.quiche_conn_session_fn(
        runtime->conn,
        &session_bytes,
        &session_len
    );

    if (session_bytes == NULL || session_len == 0 || session_len > KING_MAX_TICKET_SIZE) {
        return;
    }

    runtime->tls_has_session_ticket = true;
    runtime->tls_session_ticket_length = (zend_long) session_len;

    if (!runtime->tls_ticket_published) {
        king_ticket_ring_put(session_bytes, session_len);
        runtime->tls_ticket_published = true;
    }
}

static void king_http3_flush_egress_best_effort(king_http3_request_runtime_t *runtime)
{
    uint8_t out[KING_HTTP3_MAX_DATAGRAM_SIZE];
    quiche_send_info send_info;
    ssize_t written;

    if (runtime == NULL || runtime->conn == NULL || runtime->socket_fd < 0) {
        return;
    }

    while ((written = king_http3_quiche.quiche_conn_send_fn(
                runtime->conn,
                out,
                sizeof(out),
                &send_info
            )) != QUICHE_ERR_DONE) {
        ssize_t sent;

        if (written <= 0) {
            break;
        }

        sent = sendto(
            runtime->socket_fd,
            out,
            (size_t) written,
            0,
            (struct sockaddr *) &send_info.to,
            send_info.to_len
        );
        if (sent < 0) {
            if (errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR) {
                continue;
            }
            break;
        }

        if (sent != written) {
            break;
        }
    }
}

static void king_http3_propagate_cancel_close(king_http3_request_runtime_t *runtime)
{
    bool use_application_close;

#if defined(KING_HTTP3_BACKEND_LSQUIC)
    if (runtime != NULL && runtime->lsquic_backend_active) {
        if (runtime->lsquic_conn != NULL
            && !runtime->lsquic_connection_closed
            && king_http3_lsquic.lsquic_conn_close_fn != NULL) {
            king_http3_lsquic.lsquic_conn_close_fn(runtime->lsquic_conn);
            (void) king_http3_lsquic_runtime_process_egress(runtime, "king_http3_propagate_cancel_close");
        }
        return;
    }
#endif

    if (runtime == NULL
        || runtime->conn == NULL
        || king_http3_quiche.quiche_conn_close_fn == NULL) {
        return;
    }

    if (king_http3_quiche.quiche_conn_is_closed_fn != NULL
        && king_http3_quiche.quiche_conn_is_closed_fn(runtime->conn)) {
        return;
    }

    use_application_close =
        king_http3_quiche.quiche_conn_is_established_fn != NULL
        && king_http3_quiche.quiche_conn_is_established_fn(runtime->conn);

    if (king_http3_quiche.quiche_conn_close_fn(
            runtime->conn,
            use_application_close,
            KING_HTTP3_CANCEL_CLOSE_CODE,
            (const uint8_t *) KING_HTTP3_CANCEL_CLOSE_REASON,
            KING_HTTP3_CANCEL_CLOSE_REASON_LEN
        ) == 0) {
        king_http3_flush_egress_best_effort(runtime);
    }
}

static void king_http3_capture_post_response_tls_state(king_http3_request_runtime_t *runtime)
{
    zend_long deadline_ms;

    if (runtime == NULL || runtime->conn == NULL || runtime->socket_fd < 0) {
        return;
    }

    king_http3_refresh_tls_state(runtime);

    if (runtime->tls_has_session_ticket) {
        return;
    }

    deadline_ms = king_http3_now_ms() + 2000;

    while (!runtime->tls_has_session_ticket && king_http3_now_ms() < deadline_ms) {
        struct pollfd pfd;
        zend_long timeout_ms = deadline_ms - king_http3_now_ms();

        if (king_http3_quiche.quiche_conn_is_closed_fn(runtime->conn)) {
            king_http3_refresh_tls_state(runtime);
            break;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = runtime->socket_fd;
        pfd.events = POLLIN;

        if (timeout_ms > 25) {
            timeout_ms = 25;
        }
        if (timeout_ms < 0) {
            timeout_ms = 0;
        }

        switch (poll(&pfd, 1, (int) timeout_ms)) {
            case -1:
                if (errno == EINTR) {
                    continue;
                }
                return;

            case 0:
                king_http3_quiche.quiche_conn_on_timeout_fn(runtime->conn);
                king_http3_flush_egress_best_effort(runtime);
                king_http3_refresh_tls_state(runtime);
                continue;

            default:
                break;
        }

        if ((pfd.revents & POLLIN) == 0) {
            continue;
        }

        for (;;) {
            uint8_t buffer[65535];
            struct sockaddr_storage from;
            socklen_t from_len = sizeof(from);
            ssize_t received = recvfrom(
                runtime->socket_fd,
                buffer,
                sizeof(buffer),
                0,
                (struct sockaddr *) &from,
                &from_len
            );

            if (received < 0) {
                if (errno == EAGAIN || errno == EWOULDBLOCK) {
                    break;
                }
                return;
            }

            {
                quiche_recv_info recv_info;
                ssize_t done;

                recv_info.from = (struct sockaddr *) &from;
                recv_info.from_len = from_len;
                recv_info.to = (struct sockaddr *) &runtime->local_addr;
                recv_info.to_len = runtime->local_addr_len;

                done = king_http3_quiche.quiche_conn_recv_fn(
                    runtime->conn,
                    buffer,
                    (size_t) received,
                    &recv_info
                );
                if (done < 0 && done != QUICHE_ERR_DONE) {
                    break;
                }
            }
        }

        king_http3_flush_egress_best_effort(runtime);
        king_http3_refresh_tls_state(runtime);
    }
}

static zend_result king_http3_add_header_value(
    zval *headers,
    const char *name,
    size_t name_len,
    const char *value,
    size_t value_len)
{
    zend_string *normalized_name;
    zval *existing;
    zval array_value;
    zval old_value;

    normalized_name = zend_string_alloc(name_len, 0);
    for (size_t i = 0; i < name_len; ++i) {
        ZSTR_VAL(normalized_name)[i] = (char) tolower((unsigned char) name[i]);
    }
    ZSTR_VAL(normalized_name)[name_len] = '\0';

    existing = zend_hash_find(Z_ARRVAL_P(headers), normalized_name);
    if (existing == NULL) {
        add_assoc_stringl_ex(headers, ZSTR_VAL(normalized_name), ZSTR_LEN(normalized_name), (char *) value, value_len);
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    if (Z_TYPE_P(existing) == IS_ARRAY) {
        add_next_index_stringl(existing, (char *) value, value_len);
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    array_init(&array_value);
    ZVAL_COPY(&old_value, existing);
    add_next_index_zval(&array_value, &old_value);
    add_next_index_stringl(&array_value, (char *) value, value_len);
    zend_hash_update(Z_ARRVAL_P(headers), normalized_name, &array_value);
    zend_string_release(normalized_name);
    return SUCCESS;
}

static int king_http3_collect_response_header(
    uint8_t *name,
    size_t name_len,
    uint8_t *value,
    size_t value_len,
    void *argp)
{
    king_http3_response_header_context_t *context = (king_http3_response_header_context_t *) argp;
    king_http3_response_t *response = context->response;

    response->header_bytes += name_len + value_len;
    if (response->header_bytes > KING_HTTP3_MAX_HEADER_BYTES) {
        response->header_overflowed = true;
        return -1;
    }

    if (name_len == sizeof(":status") - 1
        && memcmp(name, ":status", sizeof(":status") - 1) == 0) {
        char buffer[16];

        if (value_len >= sizeof(buffer)) {
            response->header_overflowed = true;
            return -1;
        }

        memcpy(buffer, value, value_len);
        buffer[value_len] = '\0';
        response->status_code = strtol(buffer, NULL, 10);
        return 0;
    }

    if (king_http3_add_header_value(
            &response->headers,
            (const char *) name,
            name_len,
            (const char *) value,
            value_len
        ) != SUCCESS) {
        response->header_overflowed = true;
        return -1;
    }

    return 0;
}

static zend_result king_http3_request_target_from_url(
    const char *url_str,
    size_t url_len,
    php_url **parsed_url_out,
    king_http3_request_target_t *target,
    const char *function_name)
{
    php_url *parsed_url;
    smart_str authority = {0};
    smart_str path = {0};
    bool bracket_host = false;

    memset(target, 0, sizeof(*target));

    parsed_url = php_url_parse_ex(url_str, url_len);
    if (parsed_url == NULL) {
        king_http3_set_error("%s() requires a valid absolute HTTPS URL.", function_name);
        return FAILURE;
    }

    if (parsed_url->scheme == NULL
        || strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") != 0) {
        php_url_free(parsed_url);
        king_http3_set_error("%s() requires an absolute https:// URL for the active HTTP/3 runtime.", function_name);
        return FAILURE;
    }

    if (parsed_url->host == NULL || ZSTR_LEN(parsed_url->host) == 0) {
        php_url_free(parsed_url);
        king_http3_set_error("%s() requires a host component in the HTTPS URL.", function_name);
        return FAILURE;
    }

    target->port = parsed_url->port > 0 ? parsed_url->port : 443;
    target->secure_transport = true;

    target->host = zend_string_copy(parsed_url->host);

    bracket_host = strchr(ZSTR_VAL(parsed_url->host), ':') != NULL;
    if (bracket_host) {
        smart_str_appends(&authority, "[");
    }
    smart_str_appends(&authority, ZSTR_VAL(parsed_url->host));
    if (bracket_host) {
        smart_str_appends(&authority, "]");
    }
    if (target->port != 443) {
        smart_str_appendc(&authority, ':');
        smart_str_append_long(&authority, target->port);
    }
    smart_str_0(&authority);
    target->authority = authority.s;

    if (parsed_url->path != NULL && ZSTR_LEN(parsed_url->path) > 0) {
        smart_str_appends(&path, ZSTR_VAL(parsed_url->path));
    } else {
        smart_str_appends(&path, "/");
    }
    if (parsed_url->query != NULL && ZSTR_LEN(parsed_url->query) > 0) {
        smart_str_appendc(&path, '?');
        smart_str_appends(&path, ZSTR_VAL(parsed_url->query));
    }
    smart_str_0(&path);
    target->path = path.s;

    *parsed_url_out = parsed_url;
    return SUCCESS;
}

static void king_http3_request_target_destroy(
    php_url *parsed_url,
    king_http3_request_target_t *target)
{
    if (parsed_url != NULL) {
        php_url_free(parsed_url);
    }
    if (target->authority != NULL) {
        zend_string_release(target->authority);
    }
    if (target->host != NULL) {
        zend_string_release(target->host);
    }
    if (target->path != NULL) {
        zend_string_release(target->path);
    }
}
