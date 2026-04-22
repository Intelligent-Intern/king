#if defined(KING_HTTP3_BACKEND_LSQUIC)

struct _king_http3_lsquic_request_state {
    king_http3_request_runtime_t *runtime;
    lsquic_stream_t *stream;
    const quiche_h3_header *headers;
    size_t header_count;
    zend_string *body_string;
    size_t body_offset;
    king_http3_response_t *response;
    bool stream_closed;
    bool headers_sent;
    bool request_failed;
    bool hset_claimed;
};

typedef struct _king_http3_lsquic_header_set {
    king_http3_lsquic_request_state_t *request;
    char *buffer;
    size_t buffer_size;
    struct lsxpack_header header;
} king_http3_lsquic_header_set_t;

static socklen_t king_http3_lsquic_sockaddr_len(const struct sockaddr *addr)
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

static king_http3_request_runtime_t *king_http3_lsquic_runtime_from_conn(
    lsquic_conn_t *conn)
{
    if (conn == NULL || king_http3_lsquic.lsquic_conn_get_ctx_fn == NULL) {
        return NULL;
    }

    return (king_http3_request_runtime_t *) king_http3_lsquic.lsquic_conn_get_ctx_fn(conn);
}

static king_http3_lsquic_request_state_t *king_http3_lsquic_request_from_stream_ctx(
    lsquic_stream_ctx_t *stream_ctx)
{
    return (king_http3_lsquic_request_state_t *) stream_ctx;
}

static king_http3_lsquic_request_state_t *king_http3_lsquic_request_from_stream(
    lsquic_stream_t *stream)
{
    if (stream == NULL || king_http3_lsquic.lsquic_stream_get_ctx_fn == NULL) {
        return NULL;
    }

    return (king_http3_lsquic_request_state_t *) king_http3_lsquic.lsquic_stream_get_ctx_fn(stream);
}

static king_http3_lsquic_request_state_t *king_http3_lsquic_runtime_peek_pending_request(
    king_http3_request_runtime_t *runtime)
{
    if (runtime == NULL
        || runtime->lsquic_pending_requests == NULL
        || runtime->lsquic_pending_request_offset >= runtime->lsquic_pending_request_count) {
        return NULL;
    }

    return &runtime->lsquic_pending_requests[runtime->lsquic_pending_request_offset];
}

static king_http3_lsquic_request_state_t *king_http3_lsquic_runtime_take_pending_request(
    king_http3_request_runtime_t *runtime)
{
    king_http3_lsquic_request_state_t *request =
        king_http3_lsquic_runtime_peek_pending_request(runtime);

    if (request == NULL) {
        return NULL;
    }

    runtime->lsquic_pending_request_offset++;
    if (runtime->lsquic_pending_request_offset >= runtime->lsquic_pending_request_count) {
        runtime->lsquic_pending_requests = NULL;
        runtime->lsquic_pending_request_count = 0;
        runtime->lsquic_pending_request_offset = 0;
    }

    return request;
}

static void king_http3_lsquic_header_set_destroy(
    king_http3_lsquic_header_set_t *set)
{
    if (set == NULL) {
        return;
    }

    if (set->buffer != NULL) {
        efree(set->buffer);
    }
    efree(set);
}

static void *king_http3_lsquic_hsi_create_header_set(
    void *hsi_ctx,
    lsquic_stream_t *stream,
    int is_push_promise)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) hsi_ctx;
    king_http3_lsquic_header_set_t *set;
    king_http3_lsquic_request_state_t *request = king_http3_lsquic_request_from_stream(stream);

    (void) is_push_promise;

    if (request == NULL && runtime != NULL) {
        request = king_http3_lsquic_runtime_peek_pending_request(runtime);
    }

    set = ecalloc(1, sizeof(*set));
    set->request = request;
    return set;
}

static struct lsxpack_header *king_http3_lsquic_hsi_prepare_decode(
    void *hdr_set,
    struct lsxpack_header *hdr,
    size_t space)
{
    king_http3_lsquic_header_set_t *set = (king_http3_lsquic_header_set_t *) hdr_set;

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

static int king_http3_lsquic_hsi_process_header(
    void *hdr_set,
    struct lsxpack_header *hdr)
{
    king_http3_lsquic_header_set_t *set = (king_http3_lsquic_header_set_t *) hdr_set;
    king_http3_response_header_context_t context;
    const char *name;
    const char *value;

    if (hdr == NULL) {
        return 0;
    }

    if (set == NULL
        || set->request == NULL
        || set->request->response == NULL) {
        return -1;
    }

    name = lsxpack_header_get_name(hdr);
    value = lsxpack_header_get_value(hdr);
    if (name == NULL || value == NULL) {
        return -1;
    }

    context.response = set->request->response;
    return king_http3_collect_response_header(
        (uint8_t *) name,
        hdr->name_len,
        (uint8_t *) value,
        hdr->val_len,
        &context
    );
}

static void king_http3_lsquic_hsi_discard_header_set(void *hdr_set)
{
    king_http3_lsquic_header_set_destroy((king_http3_lsquic_header_set_t *) hdr_set);
}

static const struct lsquic_hset_if king_http3_lsquic_hset_if = {
    .hsi_create_header_set = king_http3_lsquic_hsi_create_header_set,
    .hsi_prepare_decode = king_http3_lsquic_hsi_prepare_decode,
    .hsi_process_header = king_http3_lsquic_hsi_process_header,
    .hsi_discard_header_set = king_http3_lsquic_hsi_discard_header_set,
};

static int king_http3_lsquic_packets_out(
    void *packets_out_ctx,
    const struct lsquic_out_spec *out_spec,
    unsigned n_packets_out)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) packets_out_ctx;
    unsigned sent_count = 0;

    if (runtime == NULL || runtime->socket_fd < 0 || out_spec == NULL) {
        errno = EINVAL;
        return -1;
    }

    for (unsigned i = 0; i < n_packets_out; ++i) {
        struct msghdr message;
        socklen_t dest_len = king_http3_lsquic_sockaddr_len(out_spec[i].dest_sa);
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

        runtime->quic_packets_sent++;
        sent_count++;
    }

    return (int) sent_count;
}

static zend_result king_http3_lsquic_send_headers(
    king_http3_lsquic_request_state_t *request,
    lsquic_stream_t *stream)
{
    struct lsxpack_header *headers;
    char **buffers;
    lsquic_http_headers_t header_list;
    int result;

    if (request == NULL
        || stream == NULL
        || request->headers == NULL
        || request->header_count == 0) {
        return FAILURE;
    }

    headers = safe_emalloc(request->header_count, sizeof(*headers), 0);
    buffers = safe_emalloc(request->header_count, sizeof(*buffers), 0);

    for (size_t i = 0; i < request->header_count; ++i) {
        const quiche_h3_header *source = &request->headers[i];
        size_t buffer_len = source->name_len + source->value_len;

        buffers[i] = safe_emalloc(buffer_len, sizeof(char), 0);
        memcpy(buffers[i], source->name, source->name_len);
        memcpy(buffers[i] + source->name_len, source->value, source->value_len);
        lsxpack_header_set_offset2(
            &headers[i],
            buffers[i],
            0,
            source->name_len,
            source->name_len,
            source->value_len
        );
    }

    header_list.count = (int) request->header_count;
    header_list.headers = headers;
    result = king_http3_lsquic.lsquic_stream_send_headers_fn(
        stream,
        &header_list,
        request->body_string == NULL || ZSTR_LEN(request->body_string) == 0
    );

    for (size_t i = 0; i < request->header_count; ++i) {
        efree(buffers[i]);
    }
    efree(buffers);
    efree(headers);

    if (result != 0) {
        request->request_failed = true;
        return FAILURE;
    }

    request->headers_sent = true;
    return SUCCESS;
}

static zend_result king_http3_lsquic_write_body(
    king_http3_lsquic_request_state_t *request,
    lsquic_stream_t *stream)
{
    if (request == NULL || stream == NULL) {
        return FAILURE;
    }

    if (request->body_string != NULL) {
        while (request->body_offset < ZSTR_LEN(request->body_string)) {
            ssize_t written = king_http3_lsquic.lsquic_stream_write_fn(
                stream,
                ZSTR_VAL(request->body_string) + request->body_offset,
                ZSTR_LEN(request->body_string) - request->body_offset
            );

            if (written < 0) {
                request->request_failed = true;
                return FAILURE;
            }
            if (written == 0) {
                break;
            }

            request->body_offset += (size_t) written;
        }

        if (request->body_offset < ZSTR_LEN(request->body_string)) {
            return SUCCESS;
        }
    }

    if (king_http3_lsquic.lsquic_stream_shutdown_fn(stream, 1) != 0) {
        request->request_failed = true;
        return FAILURE;
    }

    king_http3_lsquic.lsquic_stream_wantwrite_fn(stream, 0);
    king_http3_lsquic.lsquic_stream_wantread_fn(stream, 1);
    return king_http3_lsquic.lsquic_stream_flush_fn(stream) == 0 ? SUCCESS : FAILURE;
}

static zend_result king_http3_lsquic_read_response(
    king_http3_lsquic_request_state_t *request,
    lsquic_stream_t *stream)
{
    uint8_t buffer[4096];

    if (request == NULL || stream == NULL || request->response == NULL) {
        return FAILURE;
    }

    if (!request->hset_claimed) {
        king_http3_lsquic_header_set_t *set =
            (king_http3_lsquic_header_set_t *) king_http3_lsquic.lsquic_stream_get_hset_fn(stream);
        king_http3_lsquic_header_set_destroy(set);
        request->hset_claimed = true;
    }

    for (;;) {
        ssize_t read = king_http3_lsquic.lsquic_stream_read_fn(stream, buffer, sizeof(buffer));

        if (read < 0) {
            if (errno == EWOULDBLOCK || errno == EAGAIN) {
                return SUCCESS;
            }
            request->request_failed = true;
            return FAILURE;
        }

        if (read == 0) {
            request->response->response_complete = true;
            king_http3_lsquic.lsquic_stream_wantread_fn(stream, 0);
            return SUCCESS;
        }

        request->response->body_bytes += (size_t) read;
        if (request->response->body_bytes > KING_HTTP3_MAX_RESPONSE_BYTES) {
            request->response->body_overflowed = true;
            request->request_failed = true;
            return FAILURE;
        }

        smart_str_appendl(&request->response->body, (const char *) buffer, (size_t) read);
    }
}

static lsquic_conn_ctx_t *king_http3_lsquic_on_new_conn(
    void *stream_if_ctx,
    lsquic_conn_t *conn)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) stream_if_ctx;

    if (runtime != NULL) {
        runtime->lsquic_conn = conn;
    }

    return (lsquic_conn_ctx_t *) runtime;
}

static void king_http3_lsquic_on_conn_closed(lsquic_conn_t *conn)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_conn(conn);

    if (runtime != NULL) {
        runtime->lsquic_connection_closed = true;
    }
}

static lsquic_stream_ctx_t *king_http3_lsquic_on_new_stream(
    void *stream_if_ctx,
    lsquic_stream_t *stream)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) stream_if_ctx;
    king_http3_lsquic_request_state_t *request = NULL;

    if (runtime != NULL) {
        request = king_http3_lsquic_runtime_take_pending_request(runtime);
    }

    if (request != NULL) {
        request->stream = stream;
        request->stream_closed = stream == NULL;
        request->request_failed = stream == NULL;
        if (stream != NULL && king_http3_lsquic.lsquic_stream_wantwrite_fn != NULL) {
            if (king_http3_lsquic.lsquic_stream_set_ctx_fn != NULL) {
                king_http3_lsquic.lsquic_stream_set_ctx_fn(stream, request);
            }
            king_http3_lsquic.lsquic_stream_wantwrite_fn(stream, 1);
        }
    }

    return (lsquic_stream_ctx_t *) request;
}

static void king_http3_lsquic_on_read(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_http3_lsquic_request_state_t *request = king_http3_lsquic_request_from_stream_ctx(stream_ctx);

    if (king_http3_lsquic_read_response(request, stream) != SUCCESS && request != NULL) {
        request->request_failed = true;
    }
}

static void king_http3_lsquic_on_write(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_http3_lsquic_request_state_t *request = king_http3_lsquic_request_from_stream_ctx(stream_ctx);

    if (request == NULL || stream == NULL || request->request_failed) {
        return;
    }

    if (!request->headers_sent
        && king_http3_lsquic_send_headers(request, stream) != SUCCESS) {
        request->request_failed = true;
        return;
    }

    if (king_http3_lsquic_write_body(request, stream) != SUCCESS) {
        request->request_failed = true;
    }
}

static void king_http3_lsquic_on_close(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_http3_lsquic_request_state_t *request = king_http3_lsquic_request_from_stream_ctx(stream_ctx);

    (void) stream;

    if (request != NULL) {
        request->stream_closed = true;
        if (request->stream == stream) {
            request->stream = NULL;
        }
    }
}

static void king_http3_lsquic_on_hsk_done(
    lsquic_conn_t *conn,
    enum lsquic_hsk_status status)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_conn(conn);

    if (runtime == NULL) {
        return;
    }

    runtime->tls_session_resumed = status == LSQ_HSK_RESUMED_OK;
}

static void king_http3_lsquic_on_sess_resume_info(
    lsquic_conn_t *conn,
    const unsigned char *session,
    size_t session_len)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_conn(conn);

    if (runtime == NULL
        || session == NULL
        || session_len == 0
        || session_len > KING_MAX_TICKET_SIZE) {
        return;
    }

    runtime->tls_has_session_ticket = true;
    runtime->tls_session_ticket_length = (zend_long) session_len;

    if (!runtime->tls_ticket_published) {
        king_ticket_ring_put(session, session_len);
        runtime->tls_ticket_published = true;
    }
}

static const struct lsquic_stream_if king_http3_lsquic_stream_if = {
    .on_new_conn = king_http3_lsquic_on_new_conn,
    .on_conn_closed = king_http3_lsquic_on_conn_closed,
    .on_new_stream = king_http3_lsquic_on_new_stream,
    .on_read = king_http3_lsquic_on_read,
    .on_write = king_http3_lsquic_on_write,
    .on_close = king_http3_lsquic_on_close,
    .on_hsk_done = king_http3_lsquic_on_hsk_done,
    .on_sess_resume_info = king_http3_lsquic_on_sess_resume_info,
};

#endif
