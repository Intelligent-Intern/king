static void king_orchestrator_record_terminal_error(
    zend_string *run_id,
    zend_bool cancelled,
    const char *category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
)
{
    if (run_id == NULL) {
        return;
    }

    if (cancelled) {
        (void) king_orchestrator_pipeline_run_cancelled_classified(
            run_id,
            king_get_error(),
            category,
            retry_disposition,
            step_index,
            backend
        );
    } else {
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            king_get_error(),
            category,
            retry_disposition,
            step_index,
            backend
        );
    }
}

static void king_orchestrator_mark_interrupted_run(
    zend_string *run_id,
    zend_bool cancelled,
    zend_long step_index,
    const char *backend
)
{
    const char *category = "backend";
    const char *retry_disposition = "caller_managed_retry";

    if (cancelled) {
        category = "cancelled";
        retry_disposition = "not_applicable";
    } else if (
        EG(exception) != NULL
        && instanceof_function(EG(exception)->ce, king_ce_timeout_exception)
    ) {
        category = "timeout";
        retry_disposition = "caller_managed_retry";
    }

    king_orchestrator_record_terminal_error(
        run_id,
        cancelled,
        category,
        retry_disposition,
        step_index,
        backend
    );
}

static zend_result king_orchestrator_enforce_max_concurrency(
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];
    size_t active_runs;

    if (control == NULL || control->max_concurrency <= 0) {
        return SUCCESS;
    }

    active_runs = king_orchestrator_count_active_runs();
    if ((zend_long) active_runs < control->max_concurrency) {
        return SUCCESS;
    }

    snprintf(
        message,
        sizeof(message),
        "%s() cannot exceed the active orchestrator max_concurrency of %ld while %zu run(s) are already in flight.",
        function_name,
        control->max_concurrency,
        active_runs
    );
    return king_orchestrator_raise_error(
        message,
        king_ce_runtime_exception,
        throw_on_error
    );
}

static zend_result king_orchestrator_remote_prepare_stream(
    php_stream *stream,
    zend_long timeout_ms
)
{
    struct timeval timeout;

    if (stream == NULL) {
        return FAILURE;
    }

    if (timeout_ms <= 0) {
        timeout_ms = 30000;
    }

    timeout.tv_sec = timeout_ms / 1000;
    timeout.tv_usec = (timeout_ms % 1000) * 1000;

    php_stream_set_option(stream, PHP_STREAM_OPTION_BLOCKING, 1, NULL);
    php_stream_set_option(
        stream,
        PHP_STREAM_OPTION_READ_TIMEOUT,
        0,
        &timeout
    );

    return SUCCESS;
}

static zend_result king_orchestrator_remote_write_all(
    php_stream *stream,
    const char *buffer,
    size_t buffer_len,
    const char *function_name
)
{
    size_t written = 0;

    if (stream == NULL || buffer == NULL) {
        return FAILURE;
    }

    (void) function_name;

    while (written < buffer_len) {
        ssize_t chunk = php_stream_write(
            stream,
            buffer + written,
            buffer_len - written
        );

        if (chunk <= 0) {
            king_set_error("king_pipeline_orchestrator_run() failed while writing the remote peer request.");
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_string *king_orchestrator_remote_read_line(
    php_stream *stream,
    const char *function_name
)
{
    char *buffer;
    size_t buffer_len;
    size_t used = 0;

    if (stream == NULL) {
        return NULL;
    }

    (void) function_name;

    buffer_len = (size_t) king_orchestrator_remote_line_limit();
    buffer = emalloc(buffer_len);
    buffer[0] = '\0';

    while (used + 1 < buffer_len) {
        char *newline;
        ssize_t chunk = php_stream_read(stream, buffer + used, (buffer_len - used) - 1);
        size_t line_len;

        if (chunk <= 0) {
            efree(buffer);
            king_set_error("king_pipeline_orchestrator_run() did not receive a complete remote peer response.");
            return NULL;
        }

        used += (size_t) chunk;
        buffer[used] = '\0';
        newline = memchr(buffer, '\n', used);
        if (newline == NULL) {
            continue;
        }

        line_len = (size_t) (newline - buffer);
        if (line_len > 0 && buffer[line_len - 1] == '\r') {
            line_len--;
        }

        {
            zend_string *result = zend_string_init(buffer, line_len, 0);
            efree(buffer);
            return result;
        }
    }

    efree(buffer);
    king_set_error("king_pipeline_orchestrator_run() received an oversized remote peer response.");
    return NULL;
}

static zend_result king_orchestrator_remote_expect_result(
    php_stream *stream,
    const char *function_name,
    zval *return_value,
    king_orchestrator_error_meta_t *error_meta_out
)
{
    zend_string *line;
    char *tab;
    zend_string *decoded_error;
    zend_string *payload;
    zval decoded_meta;
    zval *message;
    zval *category;
    zval *retry_disposition;
    zval *backend;
    zval *step_index;

    king_orchestrator_error_meta_init(error_meta_out);

    line = king_orchestrator_remote_read_line(stream, function_name);
    if (line == NULL) {
        return FAILURE;
    }

    tab = strchr(ZSTR_VAL(line), '\t');
    if (tab == NULL) {
        zend_string_release(line);
        king_set_error("king_pipeline_orchestrator_run() received an invalid acknowledgement from the remote peer.");
        return FAILURE;
    }

    *tab = '\0';
    payload = zend_string_init(tab + 1, strlen(tab + 1), 0);

    if (strcmp(ZSTR_VAL(line), "OK") == 0) {
        zend_string_release(line);
        if (king_orchestrator_remote_decode_base64_zval(payload, return_value) != SUCCESS) {
            zend_string_release(payload);
            king_set_error("king_pipeline_orchestrator_run() received an invalid serialized result from the remote peer.");
            return FAILURE;
        }
        zend_string_release(payload);
        king_set_error("");
        return SUCCESS;
    }

    if (strcmp(ZSTR_VAL(line), "ERR") == 0) {
        decoded_error = php_base64_decode(
            (const unsigned char *) ZSTR_VAL(payload),
            ZSTR_LEN(payload)
        );
        zend_string_release(line);
        zend_string_release(payload);
        if (decoded_error == NULL) {
            king_set_error("king_pipeline_orchestrator_run() received an invalid error payload from the remote peer.");
            return FAILURE;
        }
        king_set_error(ZSTR_VAL(decoded_error));
        zend_string_release(decoded_error);
        return FAILURE;
    }

    if (strcmp(ZSTR_VAL(line), "ERRMETA") == 0) {
        ZVAL_NULL(&decoded_meta);
        if (king_orchestrator_remote_decode_base64_zval(payload, &decoded_meta) != SUCCESS) {
            zend_string_release(line);
            zend_string_release(payload);
            king_set_error("king_pipeline_orchestrator_run() received an invalid structured error payload from the remote peer.");
            return FAILURE;
        }
        zend_string_release(line);
        zend_string_release(payload);

        if (Z_TYPE(decoded_meta) != IS_ARRAY) {
            zval_ptr_dtor(&decoded_meta);
            king_set_error("king_pipeline_orchestrator_run() received a malformed structured error payload from the remote peer.");
            return FAILURE;
        }

        message = zend_hash_str_find(Z_ARRVAL(decoded_meta), "message", sizeof("message") - 1);
        category = zend_hash_str_find(Z_ARRVAL(decoded_meta), "category", sizeof("category") - 1);
        retry_disposition = zend_hash_str_find(Z_ARRVAL(decoded_meta), "retry_disposition", sizeof("retry_disposition") - 1);
        backend = zend_hash_str_find(Z_ARRVAL(decoded_meta), "backend", sizeof("backend") - 1);
        step_index = zend_hash_str_find(Z_ARRVAL(decoded_meta), "step_index", sizeof("step_index") - 1);

        if (message == NULL || Z_TYPE_P(message) != IS_STRING) {
            zval_ptr_dtor(&decoded_meta);
            king_set_error("king_pipeline_orchestrator_run() received a structured remote error without a message.");
            return FAILURE;
        }

        king_set_error(Z_STRVAL_P(message));
        if (
            error_meta_out != NULL
            && category != NULL && Z_TYPE_P(category) == IS_STRING
            && retry_disposition != NULL && Z_TYPE_P(retry_disposition) == IS_STRING
        ) {
            king_orchestrator_error_meta_set(
                error_meta_out,
                Z_STRVAL_P(category),
                Z_STRVAL_P(retry_disposition),
                step_index != NULL && Z_TYPE_P(step_index) == IS_LONG ? Z_LVAL_P(step_index) : -1,
                backend != NULL && Z_TYPE_P(backend) == IS_STRING ? Z_STRVAL_P(backend) : NULL
            );
        }

        zval_ptr_dtor(&decoded_meta);
        return FAILURE;
    }

    zend_string_release(line);
    zend_string_release(payload);
    king_set_error("king_pipeline_orchestrator_run() received an unknown response opcode from the remote peer.");
    return FAILURE;
}

