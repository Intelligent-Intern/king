/*
 * =========================================================================
 * FILENAME:   src/server/http2.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the original local HTTP/2 listener leaf and adds a narrow one-shot
 * on-wire h2c listener slice so v1 can verify a real network-backed HTTP/2
 * request/response flow without pretending the full long-lived server stack
 * is finished yet.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/server/http2.h"
#include "include/server/session.h"

#include "Zend/zend_smart_str.h"

#include <arpa/inet.h>
#include <errno.h>
#include <netdb.h>
#include <poll.h>
#include <stdint.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "local_listener.inc"

#define KING_SERVER_HTTP2_CLIENT_PREFACE "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
#define KING_SERVER_HTTP2_CLIENT_PREFACE_LEN 24
#define KING_SERVER_HTTP2_MAX_FRAME_PAYLOAD_BYTES 1048576
#define KING_SERVER_HTTP2_MAX_REQUEST_BODY_BYTES 1048576

#define KING_SERVER_HTTP2_FRAME_DATA 0x0
#define KING_SERVER_HTTP2_FRAME_HEADERS 0x1
#define KING_SERVER_HTTP2_FRAME_SETTINGS 0x4
#define KING_SERVER_HTTP2_FRAME_PING 0x6
#define KING_SERVER_HTTP2_FRAME_GOAWAY 0x7

#define KING_SERVER_HTTP2_FLAG_ACK 0x1
#define KING_SERVER_HTTP2_FLAG_END_STREAM 0x1
#define KING_SERVER_HTTP2_FLAG_END_HEADERS 0x4
#define KING_SERVER_HTTP2_FLAG_PADDED 0x8
#define KING_SERVER_HTTP2_FLAG_PRIORITY 0x20

typedef struct _king_server_http2_request_state {
    zval headers;
    smart_str body;
    zend_string *method;
    zend_string *path;
    zend_string *scheme;
    zend_string *authority;
    size_t body_bytes;
    uint32_t stream_id;
    zend_bool headers_initialized;
} king_server_http2_request_state;

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

    authority = strpprintf(0, "%.*s:%ld", (int) host_len, host, port);

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
        return strpprintf(0, "[%.*s]:%ld", (int) host_len, host, port);
    }

    return strpprintf(0, "%.*s:%ld", (int) host_len, host, port);
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

    snprintf(port_buffer, sizeof(port_buffer), "%ld", port);
    gai_status = getaddrinfo(host, port_buffer, &hints, &results);
    if (gai_status != 0) {
        king_server_local_set_errorf(
            "%s() failed to resolve the HTTP/2 listen target '%s:%ld' (getaddrinfo code %d).",
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
        "%s() failed to bind the on-wire HTTP/2 listener on '%s:%ld' (errno %d).",
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

static zend_result king_server_http2_hpack_static_name_value(
    uint32_t index,
    const char **name_out,
    const char **value_out
)
{
    switch (index) {
        case 1:
            *name_out = ":authority";
            *value_out = "";
            return SUCCESS;
        case 2:
            *name_out = ":method";
            *value_out = "GET";
            return SUCCESS;
        case 3:
            *name_out = ":method";
            *value_out = "POST";
            return SUCCESS;
        case 4:
            *name_out = ":path";
            *value_out = "/";
            return SUCCESS;
        case 5:
            *name_out = ":path";
            *value_out = "/index.html";
            return SUCCESS;
        case 6:
            *name_out = ":scheme";
            *value_out = "http";
            return SUCCESS;
        case 7:
            *name_out = ":scheme";
            *value_out = "https";
            return SUCCESS;
        case 8:
            *name_out = ":status";
            *value_out = "200";
            return SUCCESS;
        case 9:
            *name_out = ":status";
            *value_out = "204";
            return SUCCESS;
        case 10:
            *name_out = ":status";
            *value_out = "206";
            return SUCCESS;
        case 11:
            *name_out = ":status";
            *value_out = "304";
            return SUCCESS;
        case 12:
            *name_out = ":status";
            *value_out = "400";
            return SUCCESS;
        case 13:
            *name_out = ":status";
            *value_out = "404";
            return SUCCESS;
        case 14:
            *name_out = ":status";
            *value_out = "500";
            return SUCCESS;
        case 31:
            *name_out = "content-type";
            *value_out = "";
            return SUCCESS;
        default:
            return FAILURE;
    }
}

static zend_result king_server_http2_hpack_static_name(
    uint32_t index,
    const char **name_out
)
{
    const char *value = NULL;

    if (king_server_http2_hpack_static_name_value(index, name_out, &value) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http2_hpack_decode_integer(
    const unsigned char *buffer,
    size_t buffer_len,
    uint8_t prefix_bits,
    uint32_t *value_out,
    size_t *consumed_out,
    const char *function_name
)
{
    uint32_t value;
    uint32_t mask = (1U << prefix_bits) - 1U;
    size_t consumed = 1;
    uint32_t multiplier = 0;

    if (buffer_len == 0) {
        king_server_local_set_errorf(
            "%s() received a truncated HPACK integer.",
            function_name
        );
        return FAILURE;
    }

    value = (uint32_t) (buffer[0] & mask);
    if (value < mask) {
        *value_out = value;
        *consumed_out = consumed;
        return SUCCESS;
    }

    while (consumed < buffer_len) {
        uint8_t byte = buffer[consumed++];

        value += (uint32_t) (byte & 0x7f) << multiplier;
        if ((byte & 0x80) == 0) {
            *value_out = value;
            *consumed_out = consumed;
            return SUCCESS;
        }

        multiplier += 7;
        if (multiplier > 28) {
            king_server_local_set_errorf(
                "%s() received an HPACK integer that is too large.",
                function_name
            );
            return FAILURE;
        }
    }

    king_server_local_set_errorf(
        "%s() received a truncated HPACK integer continuation.",
        function_name
    );
    return FAILURE;
}

static zend_result king_server_http2_hpack_decode_string(
    const unsigned char *buffer,
    size_t buffer_len,
    zend_string **value_out,
    size_t *consumed_out,
    const char *function_name
)
{
    uint32_t string_len = 0;
    size_t consumed = 0;

    if (buffer_len == 0) {
        king_server_local_set_errorf(
            "%s() received a truncated HPACK string.",
            function_name
        );
        return FAILURE;
    }

    if ((buffer[0] & 0x80) != 0) {
        king_server_local_set_errorf(
            "%s() does not accept Huffman-coded HPACK strings on the current HTTP/2 wire leaf.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_server_http2_hpack_decode_integer(
            buffer,
            buffer_len,
            7,
            &string_len,
            &consumed,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (buffer_len - consumed < string_len) {
        king_server_local_set_errorf(
            "%s() received a truncated HPACK string payload.",
            function_name
        );
        return FAILURE;
    }

    *value_out = zend_string_init(
        (const char *) buffer + consumed,
        string_len,
        0
    );
    *consumed_out = consumed + string_len;
    return SUCCESS;
}

static zend_result king_server_http2_request_store_header(
    king_server_http2_request_state *state,
    zend_string *name,
    zend_string *value
)
{
    zval *existing;

    if (!state->headers_initialized) {
        array_init(&state->headers);
        state->headers_initialized = 1;
    }

    existing = zend_hash_find(Z_ARRVAL(state->headers), name);
    if (existing == NULL) {
        zval value_zv;

        ZVAL_STR(&value_zv, zend_string_copy(value));
        zend_hash_update(Z_ARRVAL(state->headers), name, &value_zv);
    } else if (Z_TYPE_P(existing) == IS_ARRAY) {
        add_next_index_str(existing, zend_string_copy(value));
    } else {
        zval values;
        zend_string *first_value = zval_get_string(existing);

        array_init(&values);
        add_next_index_str(&values, first_value);
        add_next_index_str(&values, zend_string_copy(value));
        zend_hash_update(Z_ARRVAL(state->headers), name, &values);
    }

    if (zend_string_equals_literal(name, ":method")) {
        if (state->method != NULL) {
            zend_string_release(state->method);
        }
        state->method = zend_string_copy(value);
    } else if (zend_string_equals_literal(name, ":path")) {
        if (state->path != NULL) {
            zend_string_release(state->path);
        }
        state->path = zend_string_copy(value);
    } else if (zend_string_equals_literal(name, ":scheme")) {
        if (state->scheme != NULL) {
            zend_string_release(state->scheme);
        }
        state->scheme = zend_string_copy(value);
    } else if (zend_string_equals_literal(name, ":authority")) {
        if (state->authority != NULL) {
            zend_string_release(state->authority);
        }
        state->authority = zend_string_copy(value);
    }

    return SUCCESS;
}

static zend_result king_server_http2_hpack_decode_headers(
    const unsigned char *buffer,
    size_t buffer_len,
    king_server_http2_request_state *state,
    const char *function_name
)
{
    size_t offset = 0;

    while (offset < buffer_len) {
        uint8_t first = buffer[offset];
        zend_string *name = NULL;
        zend_string *value = NULL;
        uint32_t name_index = 0;
        size_t consumed = 0;
        size_t string_consumed = 0;

        if ((first & 0x80) != 0) {
            const char *static_name = NULL;
            const char *static_value = NULL;
            uint32_t header_index = 0;

            if (
                king_server_http2_hpack_decode_integer(
                    buffer + offset,
                    buffer_len - offset,
                    7,
                    &header_index,
                    &consumed,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            if (
                header_index == 0
                || king_server_http2_hpack_static_name_value(
                    header_index,
                    &static_name,
                    &static_value
                ) != SUCCESS
            ) {
                king_server_local_set_errorf(
                    "%s() received an unsupported indexed HPACK header (%u).",
                    function_name,
                    header_index
                );
                return FAILURE;
            }

            name = zend_string_init(static_name, strlen(static_name), 0);
            value = zend_string_init(static_value, strlen(static_value), 0);
            offset += consumed;
        } else if ((first & 0xe0) == 0x20) {
            king_server_local_set_errorf(
                "%s() does not accept HPACK dynamic table size updates on the current HTTP/2 wire leaf.",
                function_name
            );
            return FAILURE;
        } else if ((first & 0xc0) == 0x40) {
            if (
                king_server_http2_hpack_decode_integer(
                    buffer + offset,
                    buffer_len - offset,
                    6,
                    &name_index,
                    &consumed,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            offset += consumed;
            if (name_index == 0) {
                if (
                    king_server_http2_hpack_decode_string(
                        buffer + offset,
                        buffer_len - offset,
                        &name,
                        &string_consumed,
                        function_name
                    ) != SUCCESS
                ) {
                    return FAILURE;
                }
                offset += string_consumed;
            } else {
                const char *static_name = NULL;

                if (
                    king_server_http2_hpack_static_name(name_index, &static_name) != SUCCESS
                ) {
                    king_server_local_set_errorf(
                        "%s() received an unsupported indexed HPACK header name (%u).",
                        function_name,
                        name_index
                    );
                    return FAILURE;
                }

                name = zend_string_init(static_name, strlen(static_name), 0);
            }

            if (
                king_server_http2_hpack_decode_string(
                    buffer + offset,
                    buffer_len - offset,
                    &value,
                    &string_consumed,
                    function_name
                ) != SUCCESS
            ) {
                zend_string_release(name);
                return FAILURE;
            }
            offset += string_consumed;
        } else {
            if (
                king_server_http2_hpack_decode_integer(
                    buffer + offset,
                    buffer_len - offset,
                    4,
                    &name_index,
                    &consumed,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            offset += consumed;
            if (name_index == 0) {
                if (
                    king_server_http2_hpack_decode_string(
                        buffer + offset,
                        buffer_len - offset,
                        &name,
                        &string_consumed,
                        function_name
                    ) != SUCCESS
                ) {
                    return FAILURE;
                }
                offset += string_consumed;
            } else {
                const char *static_name = NULL;

                if (
                    king_server_http2_hpack_static_name(name_index, &static_name) != SUCCESS
                ) {
                    king_server_local_set_errorf(
                        "%s() received an unsupported indexed HPACK header name (%u).",
                        function_name,
                        name_index
                    );
                    return FAILURE;
                }

                name = zend_string_init(static_name, strlen(static_name), 0);
            }

            if (
                king_server_http2_hpack_decode_string(
                    buffer + offset,
                    buffer_len - offset,
                    &value,
                    &string_consumed,
                    function_name
                ) != SUCCESS
            ) {
                zend_string_release(name);
                return FAILURE;
            }
            offset += string_consumed;
        }

        king_server_http2_request_store_header(state, name, value);
        zend_string_release(name);
        zend_string_release(value);
    }

    return SUCCESS;
}

static zend_result king_server_http2_hpack_encode_integer(
    smart_str *buffer,
    uint32_t value,
    uint8_t prefix_bits,
    uint8_t first_byte_mask
)
{
    uint32_t prefix_max = (1U << prefix_bits) - 1U;

    if (value < prefix_max) {
        smart_str_appendc(buffer, (char) (first_byte_mask | value));
        return SUCCESS;
    }

    smart_str_appendc(buffer, (char) (first_byte_mask | prefix_max));
    value -= prefix_max;
    while (value >= 128) {
        smart_str_appendc(buffer, (char) ((value % 128U) + 128U));
        value /= 128U;
    }
    smart_str_appendc(buffer, (char) value);
    return SUCCESS;
}

static zend_result king_server_http2_hpack_encode_string(
    smart_str *buffer,
    const char *value,
    size_t value_len
)
{
    king_server_http2_hpack_encode_integer(buffer, (uint32_t) value_len, 7, 0x00);
    if (value_len > 0) {
        smart_str_appendl(buffer, value, value_len);
    }
    return SUCCESS;
}

static zend_result king_server_http2_hpack_encode_literal_static_name(
    smart_str *buffer,
    uint32_t name_index,
    const char *value,
    size_t value_len
)
{
    king_server_http2_hpack_encode_integer(buffer, name_index, 4, 0x00);
    return king_server_http2_hpack_encode_string(buffer, value, value_len);
}

static zend_result king_server_http2_hpack_encode_literal_name(
    smart_str *buffer,
    const char *name,
    size_t name_len,
    const char *value,
    size_t value_len
)
{
    smart_str_appendc(buffer, '\0');
    king_server_http2_hpack_encode_string(buffer, name, name_len);
    return king_server_http2_hpack_encode_string(buffer, value, value_len);
}

static zend_result king_server_http2_hpack_append_header_value(
    smart_str *buffer,
    zend_string *name,
    zend_string *value
)
{
    if (zend_string_equals_literal_ci(name, "content-type")) {
        return king_server_http2_hpack_encode_literal_static_name(
            buffer,
            31,
            ZSTR_VAL(value),
            ZSTR_LEN(value)
        );
    }

    return king_server_http2_hpack_encode_literal_name(
        buffer,
        ZSTR_VAL(name),
        ZSTR_LEN(name),
        ZSTR_VAL(value),
        ZSTR_LEN(value)
    );
}

static zend_result king_server_http2_write_frame(
    int fd,
    uint8_t type,
    uint8_t flags,
    uint32_t stream_id,
    const unsigned char *payload,
    size_t payload_len,
    const char *function_name
)
{
    unsigned char header[9];

    if (payload_len > 0x00ffffffU) {
        king_server_local_set_errorf(
            "%s() attempted to write an HTTP/2 frame larger than the 24-bit wire length field.",
            function_name
        );
        return FAILURE;
    }

    header[0] = (unsigned char) ((payload_len >> 16) & 0xffU);
    header[1] = (unsigned char) ((payload_len >> 8) & 0xffU);
    header[2] = (unsigned char) (payload_len & 0xffU);
    header[3] = type;
    header[4] = flags;
    header[5] = (unsigned char) ((stream_id >> 24) & 0x7fU);
    header[6] = (unsigned char) ((stream_id >> 16) & 0xffU);
    header[7] = (unsigned char) ((stream_id >> 8) & 0xffU);
    header[8] = (unsigned char) (stream_id & 0xffU);

    if (king_server_http2_write_all_fd(fd, header, sizeof(header), function_name) != SUCCESS) {
        return FAILURE;
    }

    if (payload_len == 0) {
        return SUCCESS;
    }

    return king_server_http2_write_all_fd(fd, payload, payload_len, function_name);
}

static zend_result king_server_http2_read_frame(
    int fd,
    uint8_t *type_out,
    uint8_t *flags_out,
    uint32_t *stream_id_out,
    zend_string **payload_out,
    const char *function_name
)
{
    unsigned char header[9];
    size_t payload_len;
    zend_string *payload;

    if (king_server_http2_read_exact_fd(fd, header, sizeof(header), function_name) != SUCCESS) {
        return FAILURE;
    }

    payload_len = ((size_t) header[0] << 16)
        | ((size_t) header[1] << 8)
        | (size_t) header[2];
    if (payload_len > KING_SERVER_HTTP2_MAX_FRAME_PAYLOAD_BYTES) {
        king_server_local_set_errorf(
            "%s() received an HTTP/2 frame larger than the current one-shot wire leaf allows.",
            function_name
        );
        return FAILURE;
    }

    payload = zend_string_alloc(payload_len, 0);
    if (payload_len > 0) {
        if (
            king_server_http2_read_exact_fd(
                fd,
                (unsigned char *) ZSTR_VAL(payload),
                payload_len,
                function_name
            ) != SUCCESS
        ) {
            zend_string_release(payload);
            return FAILURE;
        }
    }
    ZSTR_VAL(payload)[payload_len] = '\0';

    *type_out = header[3];
    *flags_out = header[4];
    *stream_id_out = (
        ((uint32_t) header[5] & 0x7fU) << 24
        | ((uint32_t) header[6] << 16)
        | ((uint32_t) header[7] << 8)
        | (uint32_t) header[8]
    );
    *payload_out = payload;
    return SUCCESS;
}

static zend_result king_server_http2_expect_client_preface(
    int fd,
    const char *function_name
)
{
    unsigned char preface[KING_SERVER_HTTP2_CLIENT_PREFACE_LEN];

    if (
        king_server_http2_read_exact_fd(
            fd,
            preface,
            sizeof(preface),
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (
        memcmp(
            preface,
            KING_SERVER_HTTP2_CLIENT_PREFACE,
            KING_SERVER_HTTP2_CLIENT_PREFACE_LEN
        ) != 0
    ) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/2 client preface.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http2_send_settings(
    int fd,
    const char *function_name
)
{
    return king_server_http2_write_frame(
        fd,
        KING_SERVER_HTTP2_FRAME_SETTINGS,
        0,
        0,
        NULL,
        0,
        function_name
    );
}

static zend_result king_server_http2_send_settings_ack(
    int fd,
    const char *function_name
)
{
    return king_server_http2_write_frame(
        fd,
        KING_SERVER_HTTP2_FRAME_SETTINGS,
        KING_SERVER_HTTP2_FLAG_ACK,
        0,
        NULL,
        0,
        function_name
    );
}

static zend_result king_server_http2_send_goaway(
    int fd,
    uint32_t last_stream_id,
    const char *function_name
)
{
    unsigned char payload[8];

    payload[0] = (unsigned char) ((last_stream_id >> 24) & 0x7fU);
    payload[1] = (unsigned char) ((last_stream_id >> 16) & 0xffU);
    payload[2] = (unsigned char) ((last_stream_id >> 8) & 0xffU);
    payload[3] = (unsigned char) (last_stream_id & 0xffU);
    payload[4] = 0;
    payload[5] = 0;
    payload[6] = 0;
    payload[7] = 0;

    return king_server_http2_write_frame(
        fd,
        KING_SERVER_HTTP2_FRAME_GOAWAY,
        0,
        0,
        payload,
        sizeof(payload),
        function_name
    );
}

static void king_server_http2_drain_after_goaway(int fd)
{
    struct pollfd pfd;
    int attempt;

    (void) shutdown(fd, SHUT_WR);

    memset(&pfd, 0, sizeof(pfd));
    pfd.fd = fd;
    pfd.events = POLLIN | POLLHUP;

    for (attempt = 0; attempt < 4; attempt++) {
        int poll_rc = poll(&pfd, 1, 100);

        if (poll_rc <= 0) {
            break;
        }

        if ((pfd.revents & (POLLIN | POLLHUP)) == 0) {
            break;
        }

        if ((pfd.revents & POLLIN) != 0) {
            unsigned char header[9];
            ssize_t received = recv(fd, header, sizeof(header), MSG_WAITALL);
            size_t payload_len;

            if (received <= 0 || received < (ssize_t) sizeof(header)) {
                break;
            }

            payload_len = ((size_t) header[0] << 16)
                | ((size_t) header[1] << 8)
                | (size_t) header[2];
            while (payload_len > 0) {
                unsigned char discard[256];
                size_t chunk_len = payload_len < sizeof(discard)
                    ? payload_len
                    : sizeof(discard);

                received = recv(fd, discard, chunk_len, MSG_WAITALL);
                if (received <= 0) {
                    return;
                }

                payload_len -= (size_t) received;
            }
        }

        if ((pfd.revents & POLLHUP) != 0) {
            break;
        }
    }
}

static zend_result king_server_http2_build_wire_request(
    zval *request,
    king_server_http2_request_state *state,
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port,
    const char *function_name
)
{
    zend_string *authority;
    const char *scheme = "http";

    if (state->method == NULL || state->path == NULL) {
        king_server_local_set_errorf(
            "%s() requires ':method' and ':path' pseudo headers on the on-wire HTTP/2 leaf.",
            function_name
        );
        return FAILURE;
    }

    authority = state->authority != NULL
        ? zend_string_copy(state->authority)
        : king_server_http2_build_authority(host, host_len, port);
    if (state->scheme != NULL && ZSTR_LEN(state->scheme) > 0) {
        scheme = ZSTR_VAL(state->scheme);
    }

    array_init(request);
    add_assoc_str(request, "method", zend_string_copy(state->method));
    add_assoc_str(request, "uri", zend_string_copy(state->path));
    add_assoc_string(request, "version", "HTTP/2");
    add_assoc_str(request, "host", zend_string_copy(authority));
    add_assoc_zval(request, "headers", &state->headers);
    state->headers_initialized = 0;
    if (state->body.s != NULL) {
        smart_str_0(&state->body);
        add_assoc_str(request, "body", state->body.s);
        state->body.s = NULL;
    } else {
        add_assoc_string(request, "body", "");
    }

    if (session->server_pending_request_target != NULL) {
        zend_string_release(session->server_pending_request_target);
    }
    session->server_pending_request_target = zend_string_copy(state->path);

    if (session->server_pending_websocket_key != NULL) {
        zend_string_release(session->server_pending_websocket_key);
        session->server_pending_websocket_key = NULL;
    }
    session->server_pending_websocket_upgrade = false;

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/2",
        scheme,
        (zend_long) state->stream_id
    );

    zend_string_release(authority);
    return SUCCESS;
}

static zend_result king_server_http2_send_response(
    king_client_session_t *session,
    zval *retval,
    uint32_t stream_id,
    const char *function_name
)
{
    zval *status_zv;
    zval *headers_zv;
    zval *body_zv;
    zend_long status = 200;
    zend_string *status_string = NULL;
    zend_string *body = NULL;
    smart_str header_block = {0};
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

    status_string = strpprintf(0, "%ld", status);
    king_server_http2_hpack_encode_literal_static_name(
        &header_block,
        8,
        ZSTR_VAL(status_string),
        ZSTR_LEN(status_string)
    );

    if (headers_zv != NULL && Z_TYPE_P(headers_zv) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_zv), header_name, header_value)
        {
            if (header_name == NULL) {
                continue;
            }

            if (
                zend_string_equals_literal_ci(header_name, "content-length")
                || zend_string_equals_literal_ci(header_name, "connection")
            ) {
                continue;
            }

            if (Z_TYPE_P(header_value) == IS_ARRAY) {
                zval *entry;

                ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
                {
                    zend_string *value = zval_get_string(entry);

                    king_server_http2_hpack_append_header_value(
                        &header_block,
                        header_name,
                        value
                    );
                    zend_string_release(value);
                }
                ZEND_HASH_FOREACH_END();
            } else {
                zend_string *value = zval_get_string(header_value);

                king_server_http2_hpack_append_header_value(
                    &header_block,
                    header_name,
                    value
                );
                zend_string_release(value);
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    smart_str_0(&header_block);
    rc = king_server_http2_write_frame(
        session->transport_socket_fd,
        KING_SERVER_HTTP2_FRAME_HEADERS,
        ZSTR_LEN(body) == 0
            ? (KING_SERVER_HTTP2_FLAG_END_HEADERS | KING_SERVER_HTTP2_FLAG_END_STREAM)
            : KING_SERVER_HTTP2_FLAG_END_HEADERS,
        stream_id,
        (const unsigned char *) ZSTR_VAL(header_block.s),
        ZSTR_LEN(header_block.s),
        function_name
    );

    if (rc == SUCCESS && ZSTR_LEN(body) > 0) {
        rc = king_server_http2_write_frame(
            session->transport_socket_fd,
            KING_SERVER_HTTP2_FRAME_DATA,
            KING_SERVER_HTTP2_FLAG_END_STREAM,
            stream_id,
            (const unsigned char *) ZSTR_VAL(body),
            ZSTR_LEN(body),
            function_name
        );
    }

    zend_string_release(status_string);
    zend_string_release(body);
    smart_str_free(&header_block);
    return rc;
}

static zend_result king_server_http2_handle_wire_request(
    king_client_session_t *session,
    zval *request,
    const char *host,
    size_t host_len,
    zend_long port,
    const char *function_name
)
{
    king_server_http2_request_state state;
    zend_bool saw_request_end = 0;

    king_server_http2_request_state_init(&state);

    while (!saw_request_end) {
        uint8_t frame_type = 0;
        uint8_t frame_flags = 0;
        uint32_t stream_id = 0;
        zend_string *payload = NULL;

        if (
            king_server_http2_read_frame(
                session->transport_socket_fd,
                &frame_type,
                &frame_flags,
                &stream_id,
                &payload,
                function_name
            ) != SUCCESS
        ) {
            king_server_http2_request_state_dtor(&state);
            return FAILURE;
        }

        switch (frame_type) {
            case KING_SERVER_HTTP2_FRAME_SETTINGS:
                if (stream_id != 0) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an HTTP/2 SETTINGS frame on a non-zero stream.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_ACK) == 0) {
                    if ((ZSTR_LEN(payload) % 6) != 0) {
                        zend_string_release(payload);
                        king_server_local_set_errorf(
                            "%s() received an invalid HTTP/2 SETTINGS payload length.",
                            function_name
                        );
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }

                    if (
                        king_server_http2_send_settings_ack(
                            session->transport_socket_fd,
                            function_name
                        ) != SUCCESS
                    ) {
                        zend_string_release(payload);
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }
                }
                break;

            case KING_SERVER_HTTP2_FRAME_HEADERS:
                if (
                    stream_id == 0
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_END_HEADERS) == 0
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_PADDED) != 0
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_PRIORITY) != 0
                ) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an unsupported HTTP/2 HEADERS frame shape on the current wire leaf.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if (state.stream_id == 0) {
                    state.stream_id = stream_id;
                } else if (state.stream_id != stream_id) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() only supports one active request stream on the current HTTP/2 wire leaf.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if (
                    king_server_http2_hpack_decode_headers(
                        (const unsigned char *) ZSTR_VAL(payload),
                        ZSTR_LEN(payload),
                        &state,
                        function_name
                    ) != SUCCESS
                ) {
                    zend_string_release(payload);
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_END_STREAM) != 0) {
                    saw_request_end = 1;
                }
                break;

            case KING_SERVER_HTTP2_FRAME_DATA:
                if (
                    stream_id == 0
                    || stream_id != state.stream_id
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_PADDED) != 0
                ) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an unsupported HTTP/2 DATA frame shape on the current wire leaf.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if (ZSTR_LEN(payload) > 0) {
                    if (state.body_bytes > KING_SERVER_HTTP2_MAX_REQUEST_BODY_BYTES - ZSTR_LEN(payload)) {
                        zend_string_release(payload);
                        king_server_local_set_errorf(
                            "%s() received an HTTP/2 request body that exceeds the active one-shot limit.",
                            function_name
                        );
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }

                    state.body_bytes += ZSTR_LEN(payload);
                    smart_str_appendl(&state.body, ZSTR_VAL(payload), ZSTR_LEN(payload));
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_END_STREAM) != 0) {
                    saw_request_end = 1;
                }
                break;

            case KING_SERVER_HTTP2_FRAME_PING:
                if (
                    stream_id != 0
                    || ZSTR_LEN(payload) != 8
                ) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an invalid HTTP/2 PING frame.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_ACK) == 0) {
                    if (
                        king_server_http2_write_frame(
                            session->transport_socket_fd,
                            KING_SERVER_HTTP2_FRAME_PING,
                            KING_SERVER_HTTP2_FLAG_ACK,
                            0,
                            (const unsigned char *) ZSTR_VAL(payload),
                            ZSTR_LEN(payload),
                            function_name
                        ) != SUCCESS
                    ) {
                        zend_string_release(payload);
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }
                }
                break;

            default:
                /* Ignore other control frames on the current one-request leaf. */
                break;
        }

        zend_string_release(payload);
    }

    if (
        king_server_http2_build_wire_request(
            request,
            &state,
            session,
            host,
            host_len,
            port,
            function_name
        ) != SUCCESS
    ) {
        king_server_http2_request_state_dtor(&state);
        return FAILURE;
    }

    king_server_http2_request_state_dtor(&state);
    return SUCCESS;
}

PHP_FUNCTION(king_http2_server_listen)
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
        "king_http2_server_listen",
        host,
        host_len,
        port,
        config,
        3,
        "h2",
        "server_http2_local"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    king_server_http2_build_request(&request, session, host, host_len, port);

    if (king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http2_server_listen"
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
            "http/2",
            "king_http2_server_listen"
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

PHP_FUNCTION(king_http2_server_listen_once)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session = NULL;
    zval request;
    zval retval;
    int listener_fd = -1;
    int accepted_fd = -1;
    zval *stream_id_zv = NULL;
    zend_long stream_id = 1;
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
        "king_http2_server_listen_once",
        host,
        host_len,
        port,
        config,
        3,
        "h2c",
        "server_http2_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_http2_open_listener_socket(
            host,
            port,
            &listener_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    accepted_fd = accept(listener_fd, NULL, NULL);
    if (accepted_fd < 0) {
        king_server_local_set_errorf(
            "king_http2_server_listen_once() failed to accept the on-wire HTTP/2 connection (errno %d).",
            errno
        );
        goto cleanup;
    }

    close(listener_fd);
    listener_fd = -1;

    if (
        king_server_http2_apply_transport_snapshot_from_socket(
            session,
            accepted_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http2_expect_client_preface(
            accepted_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http2_send_settings(
            accepted_fd,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http2_handle_wire_request(
            session,
            &request,
            host,
            host_len,
            port,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http2_server_listen_once"
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
            "http/2",
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    stream_id_zv = zend_hash_str_find(
        Z_ARRVAL(request),
        "stream_id",
        sizeof("stream_id") - 1
    );
    if (stream_id_zv != NULL) {
        stream_id = zval_get_long(stream_id_zv);
    }

    if (
        king_server_http2_send_response(
            session,
            &retval,
            (uint32_t) stream_id,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http2_send_goaway(
            accepted_fd,
            (uint32_t) stream_id,
            "king_http2_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    king_server_http2_drain_after_goaway(accepted_fd);
    rc = 1;

cleanup:
    if (listener_fd >= 0) {
        close(listener_fd);
    }

    if (session != NULL) {
        king_server_local_close_session(session);
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
