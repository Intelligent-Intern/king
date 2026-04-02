/*
 * =========================================================================
 * FILENAME:   src/client/tls/ticket.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * TLS session-ticket import/export helpers for `King\\Session`. This slice
 * backs the public ticket leaves, enforces bounded ticket sizes, stores the
 * imported ticket on the live session snapshot, and mirrors imported ticket
 * bytes into the shared local ticket ring.
 * =========================================================================
 */

static void king_tls_export_ticket_internal(
    INTERNAL_FUNCTION_PARAMETERS,
    const char *function_name)
{
    zval *zsession;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(zsession)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(zsession, 1);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (!session->tls_has_session_ticket || session->tls_session_ticket == NULL) {
        king_set_error("");
        RETURN_EMPTY_STRING();
    }

    king_set_error("");
    RETVAL_STRINGL(
        ZSTR_VAL(session->tls_session_ticket),
        session->tls_session_ticket_length
    );
    (void) function_name;
}

static void king_tls_import_ticket_internal(
    INTERNAL_FUNCTION_PARAMETERS,
    const char *function_name)
{
    zval *zsession;
    char *ticket;
    size_t ticket_len;
    king_client_session_t *session;
    char message[KING_ERR_LEN];

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_STRING(ticket, ticket_len)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(zsession, 1);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (ticket_len == 0 || ticket_len > KING_MAX_TICKET_SIZE) {
        snprintf(
            message,
            sizeof(message),
            "%s() ticket length must be between 1 and %d bytes.",
            function_name,
            KING_MAX_TICKET_SIZE
        );
        king_set_error(message);
        RETURN_FALSE;
    }

    if (session->is_closed) {
        snprintf(
            message,
            sizeof(message),
            "%s() cannot import a ticket into a closed session.",
            function_name
        );
        king_set_error(message);
        RETURN_FALSE;
    }

    if (king_client_session_store_tls_ticket(
            session,
            (const uint8_t *) ticket,
            ticket_len,
            "imported"
        ) != SUCCESS) {
        snprintf(
            message,
            sizeof(message),
            "%s() failed to store the provided session ticket.",
            function_name
        );
        king_set_error(message);
        RETURN_FALSE;
    }

    king_ticket_ring_put((const uint8_t *) ticket, ticket_len);
    king_set_error("");
    RETURN_TRUE;
}
