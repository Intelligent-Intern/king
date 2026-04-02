/*
 * =========================================================================
 * FILENAME:   src/client/session/state/cancel.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Local stream-cancellation bookkeeping for the client-session runtime.
 * This slice records per-stream cancel mode in the session snapshot, keeps
 * the public cancel counters in sync, and invokes any registered server-side
 * cancel callback state that shares the same Session object.
 * =========================================================================
 */

zend_result king_client_session_mark_cancelled(
    king_client_session_t *session,
    zend_long stream_id,
    const char *how,
    size_t how_len,
    const char *function_name)
{
    zval mode;

    if (
        strcmp(how, "read") != 0
        && strcmp(how, "write") != 0
        && strcmp(how, "both") != 0
    ) {
        char message[KING_ERR_LEN];

        snprintf(
            message,
            sizeof(message),
            "%s() how must be 'read', 'write', or 'both'.",
            function_name
        );
        king_set_error(message);
        return FAILURE;
    }

    if (session->is_closed) {
        char message[KING_ERR_LEN];

        snprintf(
            message,
            sizeof(message),
            "%s() cannot cancel a stream on a closed session.",
            function_name
        );
        king_set_error(message);
        return FAILURE;
    }

    if (zend_hash_index_exists(&session->cancelled_streams, (zend_ulong) stream_id)) {
        char message[KING_ERR_LEN];

        snprintf(
            message,
            sizeof(message),
            "%s() stream %ld is already locally cancelled.",
            function_name,
            stream_id
        );
        king_set_error(message);
        return FAILURE;
    }

    ZVAL_STRINGL(&mode, how, how_len);
    if (zend_hash_index_add_new(
            &session->cancelled_streams,
            (zend_ulong) stream_id,
            &mode
        ) == NULL) {
        zval_ptr_dtor(&mode);
        king_set_error("Failed to record local stream cancellation state.");
        return FAILURE;
    }

    session->cancel_calls++;
    session->canceled_stream_count++;
    session->last_canceled_stream_id = stream_id;
    session->last_activity_at = time(NULL);
    king_client_session_set_string(&session->last_cancel_mode, how);

    if (king_server_cancel_invoke_if_registered(session, stream_id) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}
