/*
 * =========================================================================
 * FILENAME:   src/server/websocket.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates the server-side WebSocket-upgrade slice on top of the shared
 * King\Session and King\WebSocket runtimes. Local HTTP/1/2/3 listener leaves
 * now return an in-process bidirectional frame channel, while the on-wire
 * HTTP/1 one-shot listener duplicates the accepted socket and keeps real
 * frame I/O handler-owned for the callback lifetime.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/server/websocket.h"

#include "main/php_network.h"
#include "ext/standard/base64.h"
#include "ext/standard/sha1.h"

#include <errno.h>
#include <stdint.h>
#include <sys/socket.h>
#include <stdarg.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

#include "control.inc"

static const char *king_server_websocket_accept_magic =
    "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

static zend_string *king_server_websocket_build_authority(
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

static zend_bool king_server_websocket_is_secure(
    const king_client_session_t *session
)
{
    if (session->negotiated_alpn != NULL) {
        if (zend_string_equals_literal(session->negotiated_alpn, "h2")) {
            return 1;
        }

        if (zend_string_equals_literal(session->negotiated_alpn, "h3")) {
            return 1;
        }
    }

    if (
        session->transport_socket_family != NULL
        && zend_string_equals_literal(session->transport_socket_family, "udp")
    ) {
        return 1;
    }

    return 0;
}

static zend_string *king_server_websocket_compute_accept(
    zend_string *client_key
)
{
    PHP_SHA1_CTX context;
    unsigned char digest[20];

    PHP_SHA1Init(&context);
    PHP_SHA1Update(
        &context,
        (const unsigned char *) ZSTR_VAL(client_key),
        ZSTR_LEN(client_key)
    );
    PHP_SHA1Update(
        &context,
        (const unsigned char *) king_server_websocket_accept_magic,
        strlen(king_server_websocket_accept_magic)
    );
    PHP_SHA1Final(digest, &context);

    return php_base64_encode(digest, sizeof(digest));
}

static zend_result king_server_websocket_write_all_fd(
    int fd,
    const char *buffer,
    size_t buffer_len,
    const char *function_name
)
{
    size_t written = 0;

    while (written < buffer_len) {
        ssize_t chunk = send(fd, buffer + written, buffer_len - written, 0);

        if (chunk <= 0) {
            king_server_control_set_errorf(
                "%s() failed to write the HTTP/1 websocket upgrade response (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_result king_server_websocket_send_upgrade_response(
    king_client_session_t *session,
    const char *function_name
)
{
    zend_string *accept_value;
    zend_string *response;
    zend_result rc;

    if (
        session->server_pending_websocket_key == NULL
        || session->transport_socket_fd < 0
    ) {
        king_server_control_set_errorf(
            "%s() cannot complete an on-wire websocket upgrade without an active request key.",
            function_name
        );
        return FAILURE;
    }

    accept_value = king_server_websocket_compute_accept(
        session->server_pending_websocket_key
    );
    response = strpprintf(
        0,
        "HTTP/1.1 101 Switching Protocols\r\n"
        "Upgrade: websocket\r\n"
        "Connection: Upgrade\r\n"
        "Sec-WebSocket-Accept: %s\r\n"
        "\r\n",
        ZSTR_VAL(accept_value)
    );

    rc = king_server_websocket_write_all_fd(
        session->transport_socket_fd,
        ZSTR_VAL(response),
        ZSTR_LEN(response),
        function_name
    );

    zend_string_release(response);
    zend_string_release(accept_value);
    return rc;
}

PHP_FUNCTION(king_server_upgrade_to_websocket)
{
    zval *zsession;
    zend_long stream_id;
    king_client_session_t *session;
    const char *scheme;
    zend_bool secure;
    zend_string *authority;
    zend_string *request_target;
    zend_string *url;
    php_stream *transport_stream = NULL;
    king_ws_state *state;
    zval marker;
    zend_bool on_wire_upgrade;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_LONG(stream_id)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_control_fetch_open_session(
        zsession,
        1,
        "king_server_upgrade_to_websocket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_control_validate_stream_id(
            session,
            stream_id,
            "king_server_upgrade_to_websocket"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (zend_hash_index_exists(&session->server_upgraded_streams, (zend_ulong) stream_id)) {
        king_server_control_set_errorf(
            "king_server_upgrade_to_websocket() stream %ld is already upgraded locally.",
            stream_id
        );
        RETURN_FALSE;
    }

    secure = king_server_websocket_is_secure(session);
    on_wire_upgrade =
        session->server_pending_websocket_upgrade
        && session->server_pending_request_target != NULL
        && session->server_pending_websocket_key != NULL
        && session->transport_socket_fd >= 0;
    if (session->transport_socket_fd >= 0 && !on_wire_upgrade) {
        king_server_control_set_errorf(
            "king_server_upgrade_to_websocket() requires an active HTTP/1 websocket upgrade request on on-wire server sessions."
        );
        RETURN_FALSE;
    }
    scheme = secure ? "wss" : "ws";
    authority = king_server_websocket_build_authority(
        ZSTR_VAL(session->host),
        ZSTR_LEN(session->host),
        session->port
    );
    request_target = on_wire_upgrade
        ? zend_string_copy(session->server_pending_request_target)
        : strpprintf(0, "/stream/%ld", stream_id);
    url = strpprintf(
        0,
        "%s://%s%s",
        scheme,
        ZSTR_VAL(authority),
        ZSTR_VAL(request_target)
    );

    state = ecalloc(1, sizeof(*state));
    state->url = url;
    state->scheme = zend_string_init(scheme, strlen(scheme), 0);
    state->host = zend_string_copy(session->host);
    state->request_target = request_target;
    state->port = session->port;
    state->max_payload_size =
        session->config_websocket_default_max_payload_size > 0
            ? session->config_websocket_default_max_payload_size
            : 16777216;
    state->ping_interval_ms =
        session->config_websocket_default_ping_interval_ms > 0
            ? session->config_websocket_default_ping_interval_ms
            : 25000;
    state->handshake_timeout_ms =
        session->config_websocket_handshake_timeout_ms > 0
            ? session->config_websocket_handshake_timeout_ms
            : 5000;
    state->last_close_status_code = 1000;
    state->state = KING_WS_STATE_OPEN;
    state->secure = secure != 0;
    state->server_endpoint = true;
    state->server_local_only = on_wire_upgrade ? false : true;
    state->handshake_complete = true;
    state->closed = false;
    ZVAL_UNDEF(&state->config);
    ZVAL_UNDEF(&state->headers);

    if (on_wire_upgrade) {
        int websocket_fd = dup(session->transport_socket_fd);

        if (websocket_fd < 0) {
            king_ws_state_free(state);
            zend_string_release(authority);
            king_server_control_set_errorf(
                "king_server_upgrade_to_websocket() failed to duplicate the accepted HTTP/1 socket (errno %d).",
                errno
            );
            RETURN_FALSE;
        }

        transport_stream = php_stream_sock_open_from_socket(websocket_fd, NULL);
        if (transport_stream == NULL) {
            close(websocket_fd);
            king_ws_state_free(state);
            zend_string_release(authority);
            king_server_control_set_errorf(
                "king_server_upgrade_to_websocket() failed to attach the accepted HTTP/1 socket to a websocket stream."
            );
            RETURN_FALSE;
        }

        php_stream_set_option(
            transport_stream,
            PHP_STREAM_OPTION_BLOCKING,
            1,
            NULL
        );
        state->transport_stream = transport_stream;

        if (
            king_server_websocket_send_upgrade_response(
                session,
                "king_server_upgrade_to_websocket"
            ) != SUCCESS
        ) {
            king_ws_state_free(state);
            zend_string_release(authority);
            RETURN_FALSE;
        }
    }

    ZVAL_TRUE(&marker);
    if (zend_hash_index_add_new(
            &session->server_upgraded_streams,
            (zend_ulong) stream_id,
            &marker
        ) == NULL) {
        zval_ptr_dtor(&marker);
        king_ws_state_free(state);
        zend_string_release(authority);
        king_server_control_set_errorf(
            "king_server_upgrade_to_websocket() failed to record the local WebSocket upgrade for stream %ld.",
            stream_id
        );
        RETURN_FALSE;
    }

    session->server_websocket_upgrade_count++;
    session->server_last_websocket_stream_id = stream_id;
    session->server_last_websocket_secure = secure != 0;
    session->last_activity_at = time(NULL);
    king_server_control_set_string_bytes(
        &session->server_last_websocket_url,
        ZSTR_VAL(url),
        ZSTR_LEN(url)
    );

    zend_string_release(authority);

    king_set_error("");
    RETURN_RES(zend_register_resource(state, le_king_ws));
}
