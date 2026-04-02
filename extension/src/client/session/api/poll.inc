/*
 * =========================================================================
 * FILENAME:   src/client/session/api/poll.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Poll-loop entry point for the active client-session transport. This file
 * backs `king_poll()` with bounded timeout validation, socket readiness
 * polling, probe send/drain hooks, and transport error-state surfacing for
 * the local UDP-based session path.
 * =========================================================================
 */

static zend_result king_client_session_poll_internal(
    king_client_session_t *session,
    zend_long timeout_ms)
{
    if (timeout_ms < 0) {
        king_set_error("king_poll() timeout_ms must be >= 0.");
        return FAILURE;
    }

    if (session->is_closed) {
        king_set_error("king_poll() cannot drive a closed session.");
        return FAILURE;
    }

    if (!session->transport_has_socket) {
        king_set_error("king_poll() cannot drive a session without an active transport socket.");
        return FAILURE;
    }

    session->poll_calls++;
    session->last_poll_timeout_ms = timeout_ms;
    session->last_activity_at = time(NULL);

    {
        struct pollfd pfd;
        int poll_result;

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = session->transport_socket_fd;
        pfd.events = POLLIN | POLLOUT | POLLERR | POLLHUP;

        poll_result = poll(&pfd, 1, (int) timeout_ms);
        session->transport_last_poll_result = poll_result;

        if (poll_result < 0) {
            session->transport_last_errno = errno;
            king_client_session_set_string(&session->transport_error_scope, "poll");
            king_client_session_throw_transport_exception(
                king_ce_network_exception,
                "king_poll() failed while waiting on the active UDP transport socket (errno %d).",
                errno
            );
            return FAILURE;
        }

        if ((pfd.revents & POLLOUT) != 0
            && king_client_session_maybe_send_probe(session) != SUCCESS) {
            return FAILURE;
        }

        if ((pfd.revents & POLLIN) != 0
            && king_client_session_drain_udp_transport(session) != SUCCESS) {
            return FAILURE;
        }

        if ((pfd.revents & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            int socket_error = 0;
            socklen_t socket_error_len = sizeof(socket_error);

            getsockopt(
                session->transport_socket_fd,
                SOL_SOCKET,
                SO_ERROR,
                &socket_error,
                &socket_error_len
            );
            session->transport_last_errno = socket_error;
            king_client_session_set_string(&session->transport_error_scope, "socket_state");
            king_client_session_throw_transport_exception(
                king_ce_network_exception,
                "king_poll() detected a transport socket error state (errno %d).",
                socket_error
            );
            return FAILURE;
        }
    }

    session->transport_last_errno = 0;
    king_client_session_set_string(&session->transport_error_scope, "none");
    king_set_error("");
    return SUCCESS;
}

PHP_FUNCTION(king_poll)
{
    zval *zsession;
    zend_long timeout_ms = 0;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(zsession, 1);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (king_client_session_poll_internal(session, timeout_ms) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    RETURN_TRUE;
}
