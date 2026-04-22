#if defined(KING_HTTP3_BACKEND_LSQUIC)
struct _king_server_http3_lsquic_stream_state {
    king_server_http3_runtime_t *runtime;
    lsquic_stream_t *stream;
    bool headers_claimed;
    bool stream_closed;
};

typedef struct _king_server_http3_lsquic_header_set {
    king_server_http3_request_state_t *request;
    const char *function_name;
    char *buffer;
    size_t buffer_size;
    struct lsxpack_header header;
} king_server_http3_lsquic_header_set_t;

static socklen_t king_server_http3_lsquic_sockaddr_len(const struct sockaddr *addr)
{
    if (addr == NULL) {
        return 0;
    }

    switch (addr->sa_family) {
        case AF_INET:
            return sizeof(struct sockaddr_in);
        case AF_INET6:
            return sizeof(struct sockaddr_in6);
        default:
            return 0;
    }
}
static king_server_http3_runtime_t *king_server_http3_lsquic_runtime_from_conn(
    lsquic_conn_t *conn)
{
    if (conn == NULL || king_server_http3_lsquic.lsquic_conn_get_ctx_fn == NULL) {
        return NULL;
    }

    return (king_server_http3_runtime_t *) king_server_http3_lsquic.lsquic_conn_get_ctx_fn(conn);
}

static king_server_http3_lsquic_stream_state_t *king_server_http3_lsquic_stream_state_from_ctx(
    lsquic_stream_ctx_t *stream_ctx)
{
    return (king_server_http3_lsquic_stream_state_t *) stream_ctx;
}

static king_server_http3_lsquic_stream_state_t *king_server_http3_lsquic_stream_state_from_stream(
    lsquic_stream_t *stream)
{
    if (stream == NULL || king_server_http3_lsquic.lsquic_stream_get_ctx_fn == NULL) {
        return NULL;
    }

    return (king_server_http3_lsquic_stream_state_t *)
        king_server_http3_lsquic.lsquic_stream_get_ctx_fn(stream);
}

static void king_server_http3_lsquic_header_set_destroy(
    king_server_http3_lsquic_header_set_t *set)
{
    if (set == NULL) {
        return;
    }

    if (set->buffer != NULL) {
        efree(set->buffer);
    }
    efree(set);
}

static void *king_server_http3_lsquic_hsi_create_header_set(
    void *hsi_ctx,
    lsquic_stream_t *stream,
    int is_push_promise)
{
    king_server_http3_runtime_t *runtime = (king_server_http3_runtime_t *) hsi_ctx;
    king_server_http3_lsquic_header_set_t *set;
    king_server_http3_lsquic_stream_state_t *stream_state =
        king_server_http3_lsquic_stream_state_from_stream(stream);

    (void) is_push_promise;

    set = ecalloc(1, sizeof(*set));
    if (stream_state != NULL && stream_state->runtime != NULL) {
        set->request = stream_state->runtime->lsquic_request_state;
        set->function_name = stream_state->runtime->lsquic_function_name;
    } else if (runtime != NULL) {
        set->request = runtime->lsquic_request_state;
        set->function_name = runtime->lsquic_function_name;
    }

    return set;
}

static struct lsxpack_header *king_server_http3_lsquic_hsi_prepare_decode(
    void *hdr_set,
    struct lsxpack_header *hdr,
    size_t space)
{
    king_server_http3_lsquic_header_set_t *set =
        (king_server_http3_lsquic_header_set_t *) hdr_set;

    (void) hdr;

    if (set == NULL) {
        return NULL;
    }

    if (space == 0) {
        space = 1;
    }

    if (space > set->buffer_size) {
        set->buffer = set->buffer == NULL
            ? emalloc(space)
            : erealloc(set->buffer, space);
        set->buffer_size = space;
    }

    lsxpack_header_prepare_decode(&set->header, set->buffer, 0, space);
    return &set->header;
}

static int king_server_http3_lsquic_hsi_process_header(
    void *hdr_set,
    struct lsxpack_header *hdr)
{
    king_server_http3_lsquic_header_set_t *set =
        (king_server_http3_lsquic_header_set_t *) hdr_set;
    king_server_http3_request_header_context_t context;
    const char *name;
    const char *value;

    if (hdr == NULL || set == NULL || set->request == NULL) {
        return -1;
    }

    name = lsxpack_header_get_name(hdr);
    value = lsxpack_header_get_value(hdr);
    if (name == NULL || value == NULL) {
        return -1;
    }

    context.state = set->request;
    context.function_name = set->function_name != NULL
        ? set->function_name
        : "king_http3_server_listen_once";
    return king_server_http3_collect_request_header(
        (uint8_t *) name,
        hdr->name_len,
        (uint8_t *) value,
        hdr->val_len,
        &context
    );
}

static void king_server_http3_lsquic_hsi_discard_header_set(void *hdr_set)
{
    king_server_http3_lsquic_header_set_destroy(
        (king_server_http3_lsquic_header_set_t *) hdr_set
    );
}

static const struct lsquic_hset_if king_server_http3_lsquic_hset_if = {
    .hsi_create_header_set = king_server_http3_lsquic_hsi_create_header_set,
    .hsi_prepare_decode = king_server_http3_lsquic_hsi_prepare_decode,
    .hsi_process_header = king_server_http3_lsquic_hsi_process_header,
    .hsi_discard_header_set = king_server_http3_lsquic_hsi_discard_header_set,
};

static int king_server_http3_lsquic_packets_out(
    void *packets_out_ctx,
    const struct lsquic_out_spec *out_spec,
    unsigned n_packets_out)
{
    king_server_http3_runtime_t *runtime = (king_server_http3_runtime_t *) packets_out_ctx;
    unsigned sent_count = 0;

    if (runtime == NULL || runtime->socket_fd < 0 || out_spec == NULL) {
        errno = EINVAL;
        return -1;
    }

    for (unsigned i = 0; i < n_packets_out; ++i) {
        struct msghdr message;
        socklen_t dest_len = king_server_http3_lsquic_sockaddr_len(out_spec[i].dest_sa);
        ssize_t sent;

        if (dest_len == 0) {
            errno = EINVAL;
            return sent_count == 0 ? -1 : (int) sent_count;
        }

        memset(&message, 0, sizeof(message));
        message.msg_name = (void *) out_spec[i].dest_sa;
        message.msg_namelen = dest_len;
        message.msg_iov = out_spec[i].iov;
        message.msg_iovlen = out_spec[i].iovlen;

        sent = sendmsg(runtime->socket_fd, &message, 0);
        if (sent < 0) {
            return sent_count == 0 ? -1 : (int) sent_count;
        }

        sent_count++;
    }

    return (int) sent_count;
}

static zend_result king_server_http3_lsquic_send_response_headers(
    king_server_http3_lsquic_stream_state_t *stream_state)
{
    king_server_http3_response_state_t *response;
    struct lsxpack_header *headers;
    char **buffers;
    lsquic_http_headers_t header_list;
    int rc;

    if (stream_state == NULL
        || stream_state->runtime == NULL
        || stream_state->stream == NULL
        || stream_state->runtime->lsquic_response_state == NULL) {
        return FAILURE;
    }

    response = stream_state->runtime->lsquic_response_state;
    if (!response->initialized || response->headers_sent) {
        return SUCCESS;
    }

    headers = safe_emalloc(response->headers_len, sizeof(*headers), 0);
    buffers = safe_emalloc(response->headers_len, sizeof(*buffers), 0);

    for (size_t i = 0; i < response->headers_len; ++i) {
        size_t buffer_len = response->headers[i].name_len + response->headers[i].value_len;

        buffers[i] = safe_emalloc(buffer_len > 0 ? buffer_len : 1, sizeof(char), 0);
        memcpy(buffers[i], response->headers[i].name, response->headers[i].name_len);
        memcpy(
            buffers[i] + response->headers[i].name_len,
            response->headers[i].value,
            response->headers[i].value_len
        );
        lsxpack_header_set_offset2(
            &headers[i],
            buffers[i],
            0,
            response->headers[i].name_len,
            response->headers[i].name_len,
            response->headers[i].value_len
        );
    }

    header_list.count = (int) response->headers_len;
    header_list.headers = headers;
    rc = king_server_http3_lsquic.lsquic_stream_send_headers_fn(
        stream_state->stream,
        &header_list,
        response->body == NULL || ZSTR_LEN(response->body) == 0
    );

    for (size_t i = 0; i < response->headers_len; ++i) {
        efree(buffers[i]);
    }
    efree(buffers);
    efree(headers);

    if (rc != 0) {
        stream_state->runtime->lsquic_response_failed = true;
        return FAILURE;
    }

    response->headers_sent = true;
    return SUCCESS;
}

static zend_result king_server_http3_lsquic_write_response_body(
    king_server_http3_lsquic_stream_state_t *stream_state)
{
    king_server_http3_response_state_t *response;

    if (stream_state == NULL
        || stream_state->runtime == NULL
        || stream_state->stream == NULL
        || stream_state->runtime->lsquic_response_state == NULL) {
        return FAILURE;
    }

    response = stream_state->runtime->lsquic_response_state;
    if (!response->initialized || response->close_sent) {
        return SUCCESS;
    }

    while (response->body != NULL && response->body_offset < ZSTR_LEN(response->body)) {
        ssize_t written = king_server_http3_lsquic.lsquic_stream_write_fn(
            stream_state->stream,
            ZSTR_VAL(response->body) + response->body_offset,
            ZSTR_LEN(response->body) - response->body_offset
        );

        if (written < 0) {
            if (errno == EAGAIN || errno == EWOULDBLOCK) {
                return SUCCESS;
            }
            stream_state->runtime->lsquic_response_failed = true;
            return FAILURE;
        }

        if (written == 0) {
            return SUCCESS;
        }

        response->body_offset += (size_t) written;
    }

    if (king_server_http3_lsquic.lsquic_stream_shutdown_fn(stream_state->stream, 1) != 0) {
        stream_state->runtime->lsquic_response_failed = true;
        return FAILURE;
    }

    king_server_http3_lsquic.lsquic_stream_wantwrite_fn(stream_state->stream, 0);
    response->goaway_sent = true;
    response->close_sent = true;
    response->close_grace_deadline_ms = king_server_http3_now_ms() + KING_SERVER_HTTP3_CLOSE_GRACE_MS;

    return king_server_http3_lsquic.lsquic_stream_flush_fn(stream_state->stream) == 0
        ? SUCCESS
        : FAILURE;
}

static zend_result king_server_http3_lsquic_claim_request_headers(
    king_server_http3_lsquic_stream_state_t *stream_state)
{
    king_server_http3_lsquic_header_set_t *set;

    if (stream_state == NULL || stream_state->stream == NULL || stream_state->headers_claimed) {
        return SUCCESS;
    }

    set = (king_server_http3_lsquic_header_set_t *)
        king_server_http3_lsquic.lsquic_stream_get_hset_fn(stream_state->stream);
    king_server_http3_lsquic_header_set_destroy(set);
    stream_state->headers_claimed = true;
    return SUCCESS;
}

static zend_result king_server_http3_lsquic_read_request(
    king_server_http3_lsquic_stream_state_t *stream_state)
{
    uint8_t buffer[4096];
    king_server_http3_request_state_t *request;

    if (stream_state == NULL
        || stream_state->runtime == NULL
        || stream_state->stream == NULL
        || stream_state->runtime->lsquic_request_state == NULL) {
        return FAILURE;
    }

    request = stream_state->runtime->lsquic_request_state;
    if (king_server_http3_lsquic_claim_request_headers(stream_state) != SUCCESS) {
        stream_state->runtime->lsquic_request_failed = true;
        return FAILURE;
    }

    for (;;) {
        ssize_t read = king_server_http3_lsquic.lsquic_stream_read_fn(
            stream_state->stream,
            buffer,
            sizeof(buffer)
        );

        if (read < 0) {
            if (errno == EAGAIN || errno == EWOULDBLOCK) {
                return SUCCESS;
            }
            stream_state->runtime->lsquic_request_failed = true;
            return FAILURE;
        }

        if (read == 0) {
            if (stream_state->runtime->lsquic_request_complete != NULL) {
                *stream_state->runtime->lsquic_request_complete = true;
            }
            king_server_http3_lsquic.lsquic_stream_wantread_fn(stream_state->stream, 0);
            return SUCCESS;
        }

        request->body_bytes += (size_t) read;
        if (request->body_bytes > KING_SERVER_HTTP3_MAX_REQUEST_BODY_BYTES) {
            stream_state->runtime->lsquic_request_failed = true;
            king_server_local_set_errorf(
                "%s() received an HTTP/3 request body that exceeds the active one-shot limit.",
                stream_state->runtime->lsquic_function_name
            );
            return FAILURE;
        }

        smart_str_appendl(&request->body, (const char *) buffer, (size_t) read);
    }
}

static lsquic_conn_ctx_t *king_server_http3_lsquic_on_new_conn(
    void *stream_if_ctx,
    lsquic_conn_t *conn)
{
    king_server_http3_runtime_t *runtime = (king_server_http3_runtime_t *) stream_if_ctx;

    if (runtime != NULL) {
        runtime->lsquic_conn = conn;
        if (king_server_http3_lsquic.lsquic_conn_set_ctx_fn != NULL) {
            king_server_http3_lsquic.lsquic_conn_set_ctx_fn(conn, runtime);
        }
    }

    return (lsquic_conn_ctx_t *) runtime;
}

static void king_server_http3_lsquic_on_conn_closed(lsquic_conn_t *conn)
{
    king_server_http3_runtime_t *runtime = king_server_http3_lsquic_runtime_from_conn(conn);

    if (runtime != NULL) {
        runtime->lsquic_connection_closed = true;
    }
}

static lsquic_stream_ctx_t *king_server_http3_lsquic_on_new_stream(
    void *stream_if_ctx,
    lsquic_stream_t *stream)
{
    king_server_http3_runtime_t *runtime = (king_server_http3_runtime_t *) stream_if_ctx;
    king_server_http3_lsquic_stream_state_t *stream_state;

    if (runtime == NULL || stream == NULL || runtime->lsquic_stream_state != NULL) {
        if (runtime != NULL) {
            runtime->lsquic_request_failed = true;
        }
        return NULL;
    }

    stream_state = ecalloc(1, sizeof(*stream_state));
    stream_state->runtime = runtime;
    stream_state->stream = stream;
    runtime->lsquic_stream_state = stream_state;

    if (runtime->lsquic_request_state != NULL) {
        runtime->lsquic_request_state->request_stream_seen = true;
        runtime->lsquic_request_state->stream_id =
            king_server_http3_lsquic.lsquic_stream_id_fn(stream);
    }

    if (king_server_http3_lsquic.lsquic_stream_set_ctx_fn != NULL) {
        king_server_http3_lsquic.lsquic_stream_set_ctx_fn(stream, stream_state);
    }
    king_server_http3_lsquic.lsquic_stream_wantread_fn(stream, 1);
    return (lsquic_stream_ctx_t *) stream_state;
}

static void king_server_http3_lsquic_on_read(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_server_http3_lsquic_stream_state_t *stream_state =
        king_server_http3_lsquic_stream_state_from_ctx(stream_ctx);

    (void) stream;

    if (king_server_http3_lsquic_read_request(stream_state) != SUCCESS
        && stream_state != NULL
        && stream_state->runtime != NULL) {
        stream_state->runtime->lsquic_request_failed = true;
    }
}

static void king_server_http3_lsquic_on_write(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_server_http3_lsquic_stream_state_t *stream_state =
        king_server_http3_lsquic_stream_state_from_ctx(stream_ctx);

    (void) stream;

    if (stream_state == NULL || stream_state->runtime == NULL) {
        return;
    }

    if (king_server_http3_lsquic_send_response_headers(stream_state) != SUCCESS
        || king_server_http3_lsquic_write_response_body(stream_state) != SUCCESS) {
        stream_state->runtime->lsquic_response_failed = true;
    }
}

static void king_server_http3_lsquic_on_close(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_server_http3_lsquic_stream_state_t *stream_state =
        king_server_http3_lsquic_stream_state_from_ctx(stream_ctx);

    if (stream_state != NULL) {
        stream_state->stream_closed = true;
        if (stream_state->runtime != NULL && stream_state->runtime->lsquic_stream_state == stream_state) {
            stream_state->runtime->lsquic_stream_state = NULL;
        }
        if (stream_state->stream == stream) {
            stream_state->stream = NULL;
        }
        efree(stream_state);
    }
}

static const struct lsquic_stream_if king_server_http3_lsquic_stream_if = {
    .on_new_conn = king_server_http3_lsquic_on_new_conn,
    .on_conn_closed = king_server_http3_lsquic_on_conn_closed,
    .on_new_stream = king_server_http3_lsquic_on_new_stream,
    .on_read = king_server_http3_lsquic_on_read,
    .on_write = king_server_http3_lsquic_on_write,
    .on_close = king_server_http3_lsquic_on_close,
};

#endif
