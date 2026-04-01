/*
 * =========================================================================
 * FILENAME:   src/server/http1.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the original local HTTP/1 listener leaf and adds a narrow one-shot
 * on-wire HTTP/1 listener slice so v1 can verify real server-side websocket
 * upgrades without pretending the whole listener stack is long-lived yet.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/server/http1.h"
#include "include/server/session.h"

#include "Zend/zend_smart_str.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <netdb.h>
#include <poll.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "local_listener.inc"

#define KING_SERVER_HTTP1_MAX_REQUEST_HEAD_BYTES 32768
#define KING_SERVER_HTTP1_MAX_REQUEST_BODY_BYTES 1048576
#define KING_SERVER_HTTP1_DEFAULT_TIMEOUT_MS 5000L

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
        "%s() failed to bind the on-wire HTTP/1 listener on '%s:%ld' (errno %d).",
        function_name,
        host,
        port,
        errno
    );
    return FAILURE;
}

static zend_result king_server_http1_read_request_head(
    int socket_fd,
    smart_str *request_head,
    zend_long deadline_ms,
    const char *function_name
)
{
    char ch;
    size_t total = 0;

    while (1) {
        if (
            king_server_http1_wait_fd(
                socket_fd,
                POLLIN,
                deadline_ms,
                function_name,
                "request head phase"
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        ssize_t chunk = recv(socket_fd, &ch, 1, 0);

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
                "%s() failed before a complete HTTP/1 request head was received (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        smart_str_appendc(request_head, ch);
        total++;

        if (total > KING_SERVER_HTTP1_MAX_REQUEST_HEAD_BYTES) {
            king_server_local_set_errorf(
                "%s() received an HTTP/1 request head larger than %d bytes.",
                function_name,
                KING_SERVER_HTTP1_MAX_REQUEST_HEAD_BYTES
            );
            return FAILURE;
        }

        if (
            total >= 4
            && ZSTR_VAL(request_head->s)[total - 4] == '\r'
            && ZSTR_VAL(request_head->s)[total - 3] == '\n'
            && ZSTR_VAL(request_head->s)[total - 2] == '\r'
            && ZSTR_VAL(request_head->s)[total - 1] == '\n'
        ) {
            break;
        }
    }

    smart_str_0(request_head);
    return SUCCESS;
}

static zend_result king_server_http1_parse_request_head(
    const char *raw_request,
    size_t raw_request_len,
    king_client_session_t *session,
    zval *request,
    zend_long *content_length_out,
    const char *function_name
)
{
    const char *cursor = raw_request;
    const char *end = raw_request + raw_request_len;
    const char *line_end;
    const char *method_end;
    const char *target_end;
    const char *body_cursor;
    zval headers;
    zend_string *host_value = NULL;
    zend_bool saw_ws_connection = false;
    zend_bool saw_ws_upgrade = false;

    *content_length_out = 0;

    line_end = strstr(cursor, "\r\n");
    if (line_end == NULL) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/1 request line.",
            function_name
        );
        return FAILURE;
    }

    method_end = memchr(cursor, ' ', (size_t) (line_end - cursor));
    if (method_end == NULL || method_end == cursor) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/1 method token.",
            function_name
        );
        return FAILURE;
    }

    target_end = memchr(method_end + 1, ' ', (size_t) (line_end - (method_end + 1)));
    if (target_end == NULL || target_end == method_end + 1) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/1 request target.",
            function_name
        );
        return FAILURE;
    }

    array_init(request);
    add_assoc_stringl(request, "method", cursor, (size_t) (method_end - cursor));
    add_assoc_stringl(
        request,
        "uri",
        method_end + 1,
        (size_t) (target_end - (method_end + 1))
    );
    add_assoc_stringl(
        request,
        "version",
        target_end + 1,
        (size_t) (line_end - (target_end + 1))
    );

    if (session->server_pending_request_target != NULL) {
        zend_string_release(session->server_pending_request_target);
    }
    session->server_pending_request_target = zend_string_init(
        method_end + 1,
        (size_t) (target_end - (method_end + 1)),
        0
    );

    if (session->server_pending_websocket_key != NULL) {
        zend_string_release(session->server_pending_websocket_key);
        session->server_pending_websocket_key = NULL;
    }
    session->server_pending_websocket_upgrade = false;

    array_init(&headers);
    cursor = line_end + 2;
    while (cursor < end) {
        const char *colon;
        const char *value_start;
        size_t name_len;
        size_t value_len;
        zval *existing;

        line_end = strstr(cursor, "\r\n");
        if (line_end == NULL) {
            zval_ptr_dtor(&headers);
            zval_ptr_dtor(request);
            ZVAL_UNDEF(request);
            king_server_local_set_errorf(
                "%s() received an invalid HTTP/1 header block.",
                function_name
            );
            return FAILURE;
        }

        if (line_end == cursor) {
            cursor = line_end + 2;
            break;
        }

        colon = memchr(cursor, ':', (size_t) (line_end - cursor));
        if (colon == NULL || colon == cursor) {
            zval_ptr_dtor(&headers);
            zval_ptr_dtor(request);
            ZVAL_UNDEF(request);
            king_server_local_set_errorf(
                "%s() received an invalid HTTP/1 header line.",
                function_name
            );
            return FAILURE;
        }

        name_len = king_server_http1_trim_len(cursor, (size_t) (colon - cursor));
        value_start = king_server_http1_skip_space(colon + 1);
        value_len = king_server_http1_trim_len(
            value_start,
            (size_t) (line_end - value_start)
        );

        existing = zend_hash_str_find(Z_ARRVAL(headers), cursor, name_len);
        if (existing == NULL) {
            add_assoc_stringl(&headers, cursor, value_start, value_len);
        } else if (Z_TYPE_P(existing) == IS_ARRAY) {
            add_next_index_stringl(existing, value_start, value_len);
        } else {
            zval values;
            zend_string *first_value = zval_get_string(existing);

            array_init(&values);
            add_next_index_str(&values, first_value);
            add_next_index_stringl(&values, value_start, value_len);
            zend_hash_str_update(Z_ARRVAL(headers), cursor, name_len, &values);
        }

        if (name_len == sizeof("Host") - 1 && strncasecmp(cursor, "Host", name_len) == 0) {
            if (host_value != NULL) {
                zend_string_release(host_value);
            }
            host_value = zend_string_init(value_start, value_len, 0);
        } else if (
            name_len == sizeof("Content-Length") - 1
            && strncasecmp(cursor, "Content-Length", name_len) == 0
        ) {
            char *parse_end = NULL;
            unsigned long parsed = strtoul(value_start, &parse_end, 10);

            if (
                parse_end == value_start
                || king_server_http1_skip_space(parse_end)[0] != '\0'
                || parsed > KING_SERVER_HTTP1_MAX_REQUEST_BODY_BYTES
            ) {
                if (host_value != NULL) {
                    zend_string_release(host_value);
                }
                zval_ptr_dtor(&headers);
                zval_ptr_dtor(request);
                ZVAL_UNDEF(request);
                king_server_local_set_errorf(
                    "%s() received an invalid HTTP/1 Content-Length header.",
                    function_name
                );
                return FAILURE;
            }

            *content_length_out = (zend_long) parsed;
        } else if (
            name_len == sizeof("Connection") - 1
            && strncasecmp(cursor, "Connection", name_len) == 0
        ) {
            saw_ws_connection = king_server_http1_header_value_has_token(
                value_start,
                value_len,
                "Upgrade"
            );
        } else if (
            name_len == sizeof("Upgrade") - 1
            && strncasecmp(cursor, "Upgrade", name_len) == 0
        ) {
            saw_ws_upgrade = (
                value_len == sizeof("websocket") - 1
                && strncasecmp(value_start, "websocket", value_len) == 0
            );
        } else if (
            name_len == sizeof("Sec-WebSocket-Key") - 1
            && strncasecmp(cursor, "Sec-WebSocket-Key", name_len) == 0
            && value_len > 0
        ) {
            session->server_pending_websocket_key = zend_string_init(
                value_start,
                value_len,
                0
            );
        }

        cursor = line_end + 2;
    }

    if (host_value == NULL) {
        host_value = strpprintf(
            0,
            "%s:%ld",
            ZSTR_VAL(session->host),
            session->port
        );
    }

    body_cursor = cursor;
    add_assoc_str(request, "host", host_value);
    add_assoc_zval(request, "headers", &headers);
    add_assoc_string(request, "body", "");

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/1.1",
        "http",
        0
    );

    session->server_pending_websocket_upgrade =
        saw_ws_connection
        && saw_ws_upgrade
        && session->server_pending_websocket_key != NULL;

    if (body_cursor != end && (size_t) (end - body_cursor) > 0) {
        king_server_local_set_errorf(
            "%s() received unread HTTP/1 request bytes before the request body stage.",
            function_name
        );
        zval_ptr_dtor(request);
        ZVAL_UNDEF(request);
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http1_read_request_body(
    int socket_fd,
    zval *request,
    zend_long content_length,
    zend_long deadline_ms,
    const char *function_name
)
{
    zend_string *body;
    zval *body_zv;

    body_zv = zend_hash_str_find(
        Z_ARRVAL_P(request),
        "body",
        sizeof("body") - 1
    );
    if (body_zv == NULL || Z_TYPE_P(body_zv) != IS_STRING) {
        king_server_local_set_errorf(
            "%s() failed to attach the parsed HTTP/1 request body.",
            function_name
        );
        return FAILURE;
    }

    if (content_length == 0) {
        return SUCCESS;
    }

    body = zend_string_alloc((size_t) content_length, 0);
    if (
        king_server_http1_read_exact_fd(
            socket_fd,
            (unsigned char *) ZSTR_VAL(body),
            (size_t) content_length,
            deadline_ms,
            function_name
        ) != SUCCESS
    ) {
        zend_string_release(body);
        return FAILURE;
    }

    ZSTR_VAL(body)[content_length] = '\0';
    zval_ptr_dtor(body_zv);
    ZVAL_STR(body_zv, body);
    return SUCCESS;
}

static zend_result king_server_http1_append_response_headers(
    smart_str *response,
    zval *headers
)
{
    zend_string *header_name;
    zval *header_value;

    if (headers == NULL || Z_TYPE_P(headers) != IS_ARRAY) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers), header_name, header_value)
    {
        if (header_name == NULL) {
            continue;
        }

        if (
            zend_string_equals_literal_ci(header_name, "Content-Length")
            || zend_string_equals_literal_ci(header_name, "Connection")
        ) {
            continue;
        }

        if (Z_TYPE_P(header_value) == IS_ARRAY) {
            zval *entry;

            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
            {
                zend_string *value = zval_get_string(entry);

                smart_str_append(response, header_name);
                smart_str_appends(response, ": ");
                smart_str_append(response, value);
                smart_str_appends(response, "\r\n");
                zend_string_release(value);
            }
            ZEND_HASH_FOREACH_END();
            continue;
        }

        {
            zend_string *value = zval_get_string(header_value);

            smart_str_append(response, header_name);
            smart_str_appends(response, ": ");
            smart_str_append(response, value);
            smart_str_appends(response, "\r\n");
            zend_string_release(value);
        }
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_server_http1_send_response(
    king_client_session_t *session,
    zval *retval,
    zend_long deadline_ms,
    const char *function_name
)
{
    zval *status_zv;
    zval *headers_zv;
    zval *body_zv;
    zend_long status = 200;
    zend_string *body = NULL;
    smart_str response = {0};
    zend_result rc;

    if (session->transport_socket_fd < 0) {
        return SUCCESS;
    }

    status_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "status", sizeof("status") - 1);
    headers_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "headers", sizeof("headers") - 1);
    body_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "body", sizeof("body") - 1);

    if (status_zv != NULL && Z_TYPE_P(status_zv) != IS_NULL) {
        status = zval_get_long(status_zv);
    }

    if (body_zv != NULL && Z_TYPE_P(body_zv) != IS_NULL) {
        body = zval_get_string(body_zv);
    } else {
        body = zend_string_init("", 0, 0);
    }

    smart_str_appends(&response, "HTTP/1.1 ");
    smart_str_append_long(&response, status);
    smart_str_appendc(&response, ' ');
    smart_str_appends(&response, king_server_http1_status_text(status));
    smart_str_appends(&response, "\r\n");

    if (king_server_http1_append_response_headers(&response, headers_zv) != SUCCESS) {
        zend_string_release(body);
        smart_str_free(&response);
        return FAILURE;
    }

    smart_str_appends(&response, "Content-Length: ");
    smart_str_append_unsigned(&response, (zend_ulong) ZSTR_LEN(body));
    smart_str_appends(&response, "\r\nConnection: close\r\n\r\n");
    if (ZSTR_LEN(body) > 0) {
        smart_str_appendl(&response, ZSTR_VAL(body), ZSTR_LEN(body));
    }
    smart_str_0(&response);

    rc = king_server_http1_write_all_fd(
        session->transport_socket_fd,
        ZSTR_VAL(response.s),
        ZSTR_LEN(response.s),
        deadline_ms,
        function_name
    );

    smart_str_free(&response);
    zend_string_release(body);
    return rc;
}

PHP_FUNCTION(king_http1_server_listen)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session;
    zval request;
    zval retval;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_local_open_session(
        "king_http1_server_listen",
        host,
        host_len,
        port,
        config,
        3,
        "http/1.1",
        "server_http1_local"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    king_server_http1_build_request(&request, session, host, host_len, port);

    if (king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http1_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);

        if (!Z_ISUNDEF(retval)) {
            zval_ptr_dtor(&retval);
        }

        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_local_validate_response(
            &retval,
            session,
            "http/1.1",
            "king_http1_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);
        zval_ptr_dtor(&retval);
        RETURN_FALSE;
    }

    king_server_local_close_session(session);
    zval_ptr_dtor(&request);
    zval_ptr_dtor(&retval);

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_http1_server_listen_once)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session = NULL;
    zval request;
    zval retval;
    smart_str request_head = {0};
    zend_long content_length = 0;
    zend_long accept_deadline_ms;
    zend_long read_deadline_ms;
    zend_long write_deadline_ms;
    zend_long stream_id = 0;
    int listener_fd = -1;
    int accepted_fd = -1;
    zend_bool rc = 0;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    session = king_server_local_open_session(
        "king_http1_server_listen_once",
        host,
        host_len,
        port,
        config,
        3,
        "http/1.1",
        "server_http1_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_http1_open_listener_socket(
            host,
            port,
            &listener_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    accept_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_accept_once(
            listener_fd,
            accept_deadline_ms,
            &accepted_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    close(listener_fd);
    listener_fd = -1;

    if (
        king_server_http1_apply_transport_snapshot_from_socket(
            session,
            accepted_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    read_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_read_request_head(
            accepted_fd,
            &request_head,
            read_deadline_ms,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http1_parse_request_head(
            ZSTR_VAL(request_head.s),
            ZSTR_LEN(request_head.s),
            session,
            &request,
            &content_length,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http1_read_request_body(
            accepted_fd,
            &request,
            content_length,
            read_deadline_ms,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        if (EG(exception) != NULL) {
            goto cleanup;
        }

        goto cleanup;
    }

    if (
        king_server_local_validate_response(
            &retval,
            session,
            "http/1.1",
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (!zend_hash_num_elements(&session->server_upgraded_streams) && !session->is_closed) {
        zval *stream_id_zv = zend_hash_str_find(
            Z_ARRVAL(request),
            "stream_id",
            sizeof("stream_id") - 1
        );

        if (stream_id_zv != NULL) {
            stream_id = zval_get_long(stream_id_zv);
        }

        write_deadline_ms = king_server_http1_now_ms()
            + king_server_http1_resolve_timeout_ms(session);
        if (
            king_server_http1_send_response(
                session,
                &retval,
                write_deadline_ms,
                "king_http1_server_listen_once"
            ) != SUCCESS
        ) {
            if (king_server_local_errno_is_peer_disconnect(errno)) {
                king_server_local_mark_stream_cancelled_if_registered(
                    session,
                    stream_id,
                    "king_http1_server_listen_once"
                );
            }
            goto cleanup;
        }
    }

    rc = 1;

cleanup:
    if (listener_fd >= 0) {
        close(listener_fd);
    }

    if (session != NULL) {
        king_server_local_close_session(session);
    }

    if (request_head.s != NULL) {
        smart_str_free(&request_head);
    }

    if (!Z_ISUNDEF(request)) {
        zval_ptr_dtor(&request);
    }

    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }

    if (!rc) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}
