/*
 * Shared server-control helpers. Provides the common error formatting,
 * session/stream validation and bounded string-normalization helpers reused by
 * the local admin, cancel, upgrade and protocol control leaves.
 */

static void king_server_control_set_errorf(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static bool king_server_control_string_has_crlf(
    const char *value,
    size_t value_len
)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static void king_server_control_set_string_bytes(
    zend_string **slot,
    const char *value,
    size_t value_len
)
{
    if (*slot != NULL) {
        zend_string_release(*slot);
    }

    *slot = zend_string_init(
        value != NULL ? value : "",
        value != NULL ? value_len : 0,
        0
    );
}

static king_client_session_t *king_server_control_fetch_open_session(
    zval *zsession,
    uint32_t arg_num,
    const char *function_name
)
{
    king_client_session_t *session;

    session = king_client_session_fetch_resource(zsession, arg_num);
    if (session == NULL) {
        return NULL;
    }

    if (session->is_closed) {
        king_server_control_set_errorf(
            "%s() cannot operate on a closed session.",
            function_name
        );
        return NULL;
    }

    return session;
}

static zend_result king_server_control_validate_stream_id(
    king_client_session_t *session,
    zend_long stream_id,
    const char *function_name
)
{
    if (stream_id < 0) {
        king_server_control_set_errorf(
            "%s() stream_id must be >= 0.",
            function_name
        );
        return FAILURE;
    }

    if (zend_hash_index_exists(&session->cancelled_streams, (zend_ulong) stream_id)) {
        king_server_control_set_errorf(
            "%s() cannot operate on locally cancelled stream %ld.",
            function_name,
            stream_id
        );
        return FAILURE;
    }

    return SUCCESS;
}
