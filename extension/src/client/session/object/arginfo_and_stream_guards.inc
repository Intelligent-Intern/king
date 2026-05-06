/*
 * =========================================================================
 * FILENAME:   src/client/session/object.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * `King\\Session` and `King\\Stream` object surface for the active client
 * runtime. This file declares arginfo and class method tables, validates
 * initialized stream/session state, tracks per-stream cancel/body snapshots,
 * and implements the public OO methods for request dispatch, response
 * receive, polling, close, stats, ALPN, and early-hints toggling.
 * =========================================================================
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Session___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, connect_options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_isConnected, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Session_sendRequest, 0, 2, King\\Stream, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 0, "[]")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_STRING, 0, "\"\"")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_poll, 0, 0, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Session_close, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, code, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_stats, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_alpn, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_enableEarlyHints, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, enable, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Stream_receiveResponse, 0, 0, King\\Response, 1)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_send, 0, 1, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_finish, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, finalData, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static zend_result king_stream_object_require_initialized(
    king_stream_object *intern,
    const char *function_name)
{
    if (!Z_ISUNDEF(intern->session) && Z_TYPE(intern->session) == IS_OBJECT && intern->stream_id >= 0) {
        return SUCCESS;
    }

    king_set_error("King\\Stream object is not initialized.");
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() requires an initialized King\\Stream object.",
        function_name
    );
    return FAILURE;
}

static king_client_session_t *king_stream_object_fetch_session(
    king_stream_object *intern,
    const char *function_name)
{
    if (king_stream_object_require_initialized(intern, function_name) != SUCCESS) {
        return NULL;
    }

    return king_client_session_fetch_resource(&intern->session, 0);
}

static zval *king_stream_object_resolve_cancel_token(
    king_stream_object *intern,
    zval *cancel,
    bool prefer_bound)
{
    if (cancel != NULL && Z_TYPE_P(cancel) == IS_OBJECT) {
        return cancel;
    }

    if (
        prefer_bound
        && !Z_ISUNDEF(intern->cancel_token)
        && Z_TYPE(intern->cancel_token) == IS_OBJECT
    ) {
        return &intern->cancel_token;
    }

    return NULL;
}

static zend_bool king_stream_object_is_locally_cancelled(
    king_client_session_t *session,
    king_stream_object *intern)
{
    return zend_hash_index_exists(
        &session->cancelled_streams,
        (zend_ulong) intern->stream_id
    );
}

static zend_result king_stream_object_validate_initial_cancel_token(
    zval *cancel,
    const char *function_name)
{
    king_cancel_token_object *cancel_intern;

    if (cancel == NULL || Z_TYPE_P(cancel) == IS_NULL) {
        return SUCCESS;
    }

    cancel_intern = php_king_cancel_token_obj_from_zend(Z_OBJ_P(cancel));
    if (cancel_intern->cancelled) {
        king_set_error("The provided CancelToken is already cancelled.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() received a CancelToken that is already cancelled.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_stream_object_mark_cancelled(
    king_stream_object *intern,
    king_client_session_t *session,
    const char *function_name)
{
    if (king_stream_object_is_locally_cancelled(session, intern)) {
        intern->closed = true;
        return SUCCESS;
    }

    if (!session->is_closed) {
        if (king_client_session_mark_cancelled(
                session,
                intern->stream_id,
                "both",
                sizeof("both") - 1,
                function_name
            ) != SUCCESS) {
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "%s",
                king_get_error()[0] != '\0'
                    ? king_get_error()
                    : "Failed to record local stream cancellation state."
            );
            return FAILURE;
        }
    }

    intern->closed = true;
    return SUCCESS;
}

static zend_result king_stream_object_throw_if_cancelled(
    king_stream_object *intern,
    king_client_session_t *session,
    zval *cancel,
    const char *function_name)
{
    zval *active_cancel;
    king_cancel_token_object *cancel_intern;

    active_cancel = king_stream_object_resolve_cancel_token(intern, cancel, true);
    if (active_cancel != NULL) {
        cancel_intern = php_king_cancel_token_obj_from_zend(Z_OBJ_P(active_cancel));
        if (cancel_intern->cancelled) {
            if (king_stream_object_mark_cancelled(intern, session, function_name) != SUCCESS) {
                return FAILURE;
            }

            king_set_error("The active stream was cancelled via CancelToken.");
            zend_throw_exception_ex(
                king_ce_stream_stopped,
                0,
                "%s() cannot continue because the stream was cancelled via CancelToken.",
                function_name
            );
            return FAILURE;
        }
    }

    if (king_stream_object_is_locally_cancelled(session, intern)) {
        intern->closed = true;
        king_set_error("The active stream is locally cancelled.");
        zend_throw_exception_ex(
            king_ce_stream_stopped,
            0,
            "%s() cannot continue because the stream is locally cancelled.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_stream_object_append_body(
    king_stream_object *intern,
    zend_string *chunk)
{
    zend_string *current_body;
    zend_string *appended_body;
    size_t current_len;
    size_t chunk_len;

    if (chunk == NULL) {
        return;
    }

    current_body = intern->request_body != NULL
        ? intern->request_body
        : zend_string_init("", 0, 0);
    current_len = ZSTR_LEN(current_body);
    chunk_len = ZSTR_LEN(chunk);
    appended_body = zend_string_safe_alloc(1, current_len, chunk_len, 0);

    if (current_len > 0) {
        memcpy(
            ZSTR_VAL(appended_body),
            ZSTR_VAL(current_body),
            current_len
        );
    }
    if (chunk_len > 0) {
        memcpy(
            ZSTR_VAL(appended_body) + current_len,
            ZSTR_VAL(chunk),
            chunk_len
        );
    }

    ZSTR_VAL(appended_body)[current_len + chunk_len] = '\0';

    if (intern->request_body != NULL) {
        zend_string_release(intern->request_body);
    } else {
        zend_string_release(current_body);
    }

    intern->request_body = appended_body;
    intern->request_body_was_supplied = true;
}

static zend_result king_stream_object_build_http1_url(
    king_client_session_t *session,
    king_stream_object *intern,
    zend_string **url_out)
{
    if (session == NULL || session->host == NULL || intern->request_path == NULL) {
        king_set_error("Stream::receiveResponse() lost the request snapshot required for dispatch.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Stream::receiveResponse() lost the request snapshot required for dispatch."
        );
        return FAILURE;
    }

    if (strchr(ZSTR_VAL(session->host), ':') != NULL) {
        *url_out = strpprintf(
            0,
            "http://[%s]:" ZEND_LONG_FMT "%s",
            ZSTR_VAL(session->host),
            session->port,
            ZSTR_VAL(intern->request_path)
        );
    } else {
        *url_out = strpprintf(
            0,
            "http://%s:" ZEND_LONG_FMT "%s",
            ZSTR_VAL(session->host),
            session->port,
            ZSTR_VAL(intern->request_path)
        );
    }

    return SUCCESS;
}
