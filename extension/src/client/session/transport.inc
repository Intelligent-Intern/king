/*
 * =========================================================================
 * FILENAME:   src/client/session/transport.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Low-level transport state for the current client-session runtime. This
 * file initializes the socket/datagram counters, opens the non-blocking UDP
 * transport used by the local QUIC-flavored session path, captures endpoint
 * metadata, and drives the lightweight probe/drain loops reflected in
 * `Session::poll()` stats.
 * =========================================================================
 */

void king_client_session_init_transport_state(
    king_client_session_t *session)
{
    session->transport_socket_fd = -1;
    session->transport_connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms;
    session->transport_ping_interval_ms = king_quic_transport_config.ping_interval_ms;
    session->transport_last_poll_result = 0;
    session->transport_last_errno = 0;
    session->transport_local_port = 0;
    session->transport_peer_port = 0;
    session->transport_rx_datagram_count = 0;
    session->transport_rx_bytes = 0;
    session->transport_tx_datagram_count = 0;
    session->transport_tx_bytes = 0;
    session->transport_last_tx_at_ms = king_client_transport_now_ms();
    session->transport_last_rx_at_ms = 0;
    session->transport_datagrams_enable = king_quic_transport_config.datagrams_enable;
    session->transport_has_socket = false;
    session->transport_probe_sent = false;
    king_client_session_set_string(&session->transport_backend, "udp_socket");
    king_client_session_set_string(&session->transport_local_address, "");
    king_client_session_set_string(&session->transport_peer_address, "");
    king_client_session_set_string(&session->transport_socket_family, "unknown");
    king_client_session_set_string(&session->transport_error_scope, "none");
}

static void king_client_session_capture_transport_endpoints(
    king_client_session_t *session)
{
    struct sockaddr_storage ss;
    socklen_t len;
    char host[NI_MAXHOST];
    char service[NI_MAXSERV];

    if (session->transport_socket_fd < 0) {
        return;
    }

    len = sizeof(ss);
    if (getpeername(session->transport_socket_fd, (struct sockaddr *) &ss, &len) == 0
        && getnameinfo(
            (struct sockaddr *) &ss,
            len,
            host,
            sizeof(host),
            service,
            sizeof(service),
            NI_NUMERICHOST | NI_NUMERICSERV
        ) == 0) {
        king_client_session_set_string(&session->transport_peer_address, host);
        king_client_session_set_string(
            &session->transport_socket_family,
            king_client_session_family_label(ss.ss_family)
        );
        session->transport_peer_port = strtol(service, NULL, 10);
    }

    len = sizeof(ss);
    if (getsockname(session->transport_socket_fd, (struct sockaddr *) &ss, &len) == 0
        && getnameinfo(
            (struct sockaddr *) &ss,
            len,
            host,
            sizeof(host),
            service,
            sizeof(service),
            NI_NUMERICHOST | NI_NUMERICSERV
        ) == 0) {
        king_client_session_set_string(&session->transport_local_address, host);
        session->transport_local_port = strtol(service, NULL, 10);
    }
}

static zend_result king_client_session_open_udp_transport(
    king_client_session_t *session,
    const char *host,
    zend_long port,
    const char *function_name)
{
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *cursor;
    char port_buffer[6];
    int gai_status;
    int last_errno = 0;

    memset(&hints, 0, sizeof(hints));
    hints.ai_socktype = SOCK_DGRAM;
    hints.ai_family = AF_UNSPEC;
    hints.ai_flags = AI_ADDRCONFIG;

    snprintf(port_buffer, sizeof(port_buffer), "%ld", port);
    gai_status = getaddrinfo(host, port_buffer, &hints, &results);
    if (gai_status != 0) {
        session->transport_last_errno = gai_status;
        king_client_session_set_string(&session->transport_error_scope, "dns");
        king_client_session_throw_transport_exception(
            king_ce_network_exception,
            "%s() failed to resolve UDP endpoint for host '%s' (getaddrinfo code %d).",
            function_name,
            host,
            gai_status
        );
        return FAILURE;
    }

    for (cursor = results; cursor != NULL; cursor = cursor->ai_next) {
        int fd = socket(cursor->ai_family, SOCK_DGRAM, 0);
        int flags;

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

        if (connect(fd, cursor->ai_addr, cursor->ai_addrlen) == 0) {
            session->transport_socket_fd = fd;
            session->transport_has_socket = true;
            session->transport_last_errno = 0;
            king_client_session_set_string(&session->transport_error_scope, "none");
            king_client_session_capture_transport_endpoints(session);
            freeaddrinfo(results);
            return SUCCESS;
        }

        last_errno = errno;
        close(fd);
    }

    freeaddrinfo(results);

    session->transport_last_errno = last_errno;
    king_client_session_set_string(&session->transport_error_scope, "socket_connect");
    king_client_session_throw_transport_exception(
        king_ce_network_exception,
        "%s() failed to connect a non-blocking UDP socket to '%s:%ld' (errno %d).",
        function_name,
        host,
        port,
        last_errno
    );

    return FAILURE;
}

static zend_result king_client_session_maybe_send_probe(
    king_client_session_t *session)
{
    static const unsigned char probe[] = { 'K' };
    zend_long now_ms;
    ssize_t sent;

    if (!session->transport_has_socket || !session->transport_datagrams_enable) {
        return SUCCESS;
    }

    now_ms = king_client_transport_now_ms();

    if (session->transport_ping_interval_ms == 0) {
        if (session->transport_probe_sent) {
            return SUCCESS;
        }
    } else if (
        session->transport_ping_interval_ms > 0
        && now_ms - session->transport_last_tx_at_ms < session->transport_ping_interval_ms
    ) {
        return SUCCESS;
    }

    sent = send(
        session->transport_socket_fd,
        probe,
        sizeof(probe),
        MSG_DONTWAIT
    );
    if (sent < 0) {
        if (errno == EAGAIN || errno == EWOULDBLOCK) {
            return SUCCESS;
        }

        session->transport_last_errno = errno;
        king_client_session_set_string(&session->transport_error_scope, "send");
        king_client_session_throw_transport_exception(
            king_ce_network_exception,
            "king_poll() failed to send a UDP probe on the active transport socket (errno %d).",
            errno
        );
        return FAILURE;
    }

    session->transport_probe_sent = true;
    session->transport_tx_datagram_count++;
    session->transport_tx_bytes += sent;
    session->transport_last_tx_at_ms = now_ms;
    session->last_activity_at = time(NULL);

    return SUCCESS;
}

static zend_result king_client_session_drain_udp_transport(
    king_client_session_t *session)
{
    unsigned char buffer[2048];

    while (session->transport_has_socket) {
        ssize_t received = recv(
            session->transport_socket_fd,
            buffer,
            sizeof(buffer),
            MSG_DONTWAIT
        );

        if (received < 0) {
            if (errno == EAGAIN || errno == EWOULDBLOCK) {
                session->transport_last_errno = 0;
                king_client_session_set_string(&session->transport_error_scope, "none");
                return SUCCESS;
            }

            session->transport_last_errno = errno;
            king_client_session_set_string(&session->transport_error_scope, "recv");
            king_client_session_throw_transport_exception(
                king_ce_network_exception,
                "king_poll() failed to receive a UDP datagram from the active transport socket (errno %d).",
                errno
            );
            return FAILURE;
        }

        if (received == 0) {
            return SUCCESS;
        }

        session->transport_rx_datagram_count++;
        session->transport_rx_bytes += received;
        session->transport_last_rx_at_ms = king_client_transport_now_ms();
        session->last_activity_at = time(NULL);
    }

    return SUCCESS;
}
