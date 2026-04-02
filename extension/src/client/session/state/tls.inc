/*
 * =========================================================================
 * FILENAME:   src/client/session/state/tls.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * TLS session-ticket persistence for the client-session runtime. This slice
 * stores the active ticket bytes, tracks their source and length, and keeps
 * the session's TLS-resumption snapshot aligned with the current live
 * connection state.
 * =========================================================================
 */

zend_result king_client_session_store_tls_ticket(
    king_client_session_t *session,
    const uint8_t *ticket,
    size_t len,
    const char *source)
{
    if (session == NULL || ticket == NULL || source == NULL) {
        return FAILURE;
    }

    if (len == 0 || len > KING_MAX_TICKET_SIZE) {
        return FAILURE;
    }

    if (session->is_closed) {
        return FAILURE;
    }

    king_client_session_set_string_bytes(
        &session->tls_session_ticket,
        (const char *) ticket,
        len
    );
    session->tls_session_ticket_length = (zend_long) len;
    session->tls_has_session_ticket = true;
    session->tls_ticket_source = source;
    session->last_activity_at = time(NULL);

    return SUCCESS;
}
