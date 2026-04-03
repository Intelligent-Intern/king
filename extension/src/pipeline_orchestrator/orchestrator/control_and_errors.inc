static int king_orchestrator_backend_is_file_worker(void)
{
    return king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        && strcmp(king_mcp_orchestrator_config.orchestrator_execution_backend, "file_worker") == 0;
}

static int king_orchestrator_backend_is_remote_peer(void)
{
    return king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        && strcmp(king_mcp_orchestrator_config.orchestrator_execution_backend, "remote_peer") == 0;
}

static void king_orchestrator_error_meta_init(king_orchestrator_error_meta_t *meta)
{
    if (meta == NULL) {
        return;
    }

    memset(meta, 0, sizeof(*meta));
    meta->step_index = -1;
    meta->has_classification = 0;
}

static void king_orchestrator_error_meta_copy_bounded(
    char *destination,
    size_t destination_size,
    const char *source
)
{
    if (destination == NULL || destination_size == 0) {
        return;
    }

    destination[0] = '\0';
    if (source == NULL) {
        return;
    }

    strncpy(destination, source, destination_size - 1);
    destination[destination_size - 1] = '\0';
}

static void king_orchestrator_error_meta_set(
    king_orchestrator_error_meta_t *meta,
    const char *category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
)
{
    if (meta == NULL) {
        return;
    }

    king_orchestrator_error_meta_init(meta);
    king_orchestrator_error_meta_copy_bounded(meta->category, sizeof(meta->category), category);
    king_orchestrator_error_meta_copy_bounded(
        meta->retry_disposition,
        sizeof(meta->retry_disposition),
        retry_disposition
    );
    king_orchestrator_error_meta_copy_bounded(meta->backend, sizeof(meta->backend), backend);
    meta->step_index = step_index;
    meta->has_classification = 1;
}

static zend_string *king_orchestrator_remote_serialize_zval(zval *value)
{
    smart_str buffer = {0};
    php_serialize_data_t var_hash;

    PHP_VAR_SERIALIZE_INIT(var_hash);
    php_var_serialize(&buffer, value, &var_hash);
    PHP_VAR_SERIALIZE_DESTROY(var_hash);
    smart_str_0(&buffer);

    return buffer.s;
}

static zend_string *king_orchestrator_remote_encode_zval_base64(zval *value)
{
    zend_string *serialized;
    zend_string *encoded;

    if (value == NULL) {
        zval null_value;

        ZVAL_NULL(&null_value);
        serialized = king_orchestrator_remote_serialize_zval(&null_value);
    } else {
        serialized = king_orchestrator_remote_serialize_zval(value);
    }

    if (serialized == NULL) {
        return NULL;
    }

    encoded = php_base64_encode(
        (const unsigned char *) ZSTR_VAL(serialized),
        ZSTR_LEN(serialized)
    );
    zend_string_release(serialized);

    return encoded;
}

static zend_bool king_orchestrator_remote_value_tree_is_safe(
    zval *value,
    HashTable *seen_arrays
)
{
    zval *entry;
    zend_ulong array_key;

    if (value == NULL) {
        return 0;
    }

    switch (Z_TYPE_P(value)) {
        case IS_NULL:
        case IS_FALSE:
        case IS_TRUE:
        case IS_LONG:
        case IS_DOUBLE:
        case IS_STRING:
            return 1;
        case IS_ARRAY:
            array_key = (zend_ulong) (uintptr_t) Z_ARR_P(value);
            if (zend_hash_index_exists(seen_arrays, array_key)) {
                return 1;
            }
            zend_hash_index_add_empty_element(seen_arrays, array_key);
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry) {
                if (!king_orchestrator_remote_value_tree_is_safe(entry, seen_arrays)) {
                    return 0;
                }
            } ZEND_HASH_FOREACH_END();
            return 1;
        case IS_REFERENCE:
        case IS_OBJECT:
        case IS_RESOURCE:
        default:
            return 0;
    }
}

static zend_result king_orchestrator_remote_decode_base64_zval(
    zend_string *encoded_value,
    zval *return_value
)
{
    zend_string *decoded_payload;
    const unsigned char *cursor;
    const unsigned char *end;
    php_unserialize_data_t var_hash;
    HashTable allowed_classes;
    HashTable seen_arrays;
    zend_result result = FAILURE;

    if (encoded_value == NULL || return_value == NULL) {
        return FAILURE;
    }

    decoded_payload = php_base64_decode(
        (const unsigned char *) ZSTR_VAL(encoded_value),
        ZSTR_LEN(encoded_value)
    );
    if (decoded_payload == NULL) {
        return FAILURE;
    }

    cursor = (const unsigned char *) ZSTR_VAL(decoded_payload);
    end = cursor + ZSTR_LEN(decoded_payload);
    ZVAL_NULL(return_value);

    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    zend_hash_init(&allowed_classes, 0, NULL, NULL, 0);
    php_var_unserialize_set_allowed_classes(var_hash, &allowed_classes);
    if (!php_var_unserialize(
            return_value,
            &cursor,
            end,
            &var_hash)) {
        goto cleanup;
    }
    if (cursor != end) {
        goto cleanup;
    }
    zend_hash_init(&seen_arrays, 0, NULL, NULL, 0);
    if (!king_orchestrator_remote_value_tree_is_safe(return_value, &seen_arrays)) {
        zend_hash_destroy(&seen_arrays);
        goto cleanup;
    }
    zend_hash_destroy(&seen_arrays);
    result = SUCCESS;

cleanup:
    PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
    zend_hash_destroy(&allowed_classes);
    zend_string_release(decoded_payload);
    if (result != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
    }

    return result;
}

static zend_string *king_orchestrator_remote_build_target(void)
{
    const char *host;
    size_t host_len;
    bool needs_brackets;

    if (
        king_mcp_orchestrator_config.orchestrator_remote_host == NULL
        || king_mcp_orchestrator_config.orchestrator_remote_host[0] == '\0'
    ) {
        return NULL;
    }

    host = king_mcp_orchestrator_config.orchestrator_remote_host;
    host_len = strlen(host);
    needs_brackets =
        memchr(host, ':', host_len) != NULL
        && !(host_len >= 2 && host[0] == '[' && host[host_len - 1] == ']');

    if (needs_brackets) {
        return strpprintf(
            0,
            "tcp://[%s]:%ld",
            host,
            king_mcp_orchestrator_config.orchestrator_remote_port
        );
    }

    return strpprintf(
        0,
        "tcp://%s:%ld",
        host,
        king_mcp_orchestrator_config.orchestrator_remote_port
    );
}

static zend_long king_orchestrator_remote_line_limit(void)
{
    if (king_mcp_orchestrator_config.mcp_max_message_size_bytes > 0) {
        return king_mcp_orchestrator_config.mcp_max_message_size_bytes;
    }

    return 4194304;
}

static uint64_t king_orchestrator_monotonic_time_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return ((uint64_t) ts.tv_sec * 1000ULL) + ((uint64_t) ts.tv_nsec / 1000000ULL);
}

static zend_long king_orchestrator_control_timeout_budget_ms(
    king_orchestrator_exec_control_t *control
)
{
    uint64_t elapsed_ms;

    if (control == NULL || control->timeout_ms <= 0) {
        return 0;
    }

    elapsed_ms = king_orchestrator_monotonic_time_ms() - control->started_at_ms;
    if (elapsed_ms >= (uint64_t) control->timeout_ms) {
        return 0;
    }

    return (zend_long) ((uint64_t) control->timeout_ms - elapsed_ms);
}

static zend_long king_orchestrator_control_deadline_budget_ms(
    king_orchestrator_exec_control_t *control
)
{
    uint64_t now_ms;

    if (control == NULL || control->deadline_ms == 0) {
        return 0;
    }

    now_ms = king_orchestrator_monotonic_time_ms();
    if (now_ms >= control->deadline_ms) {
        return 0;
    }

    return (zend_long) (control->deadline_ms - now_ms);
}

static zend_long king_orchestrator_control_effective_budget_ms(
    king_orchestrator_exec_control_t *control
)
{
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;

    timeout_budget_ms = king_orchestrator_control_timeout_budget_ms(control);
    deadline_budget_ms = king_orchestrator_control_deadline_budget_ms(control);

    if (timeout_budget_ms > 0 && deadline_budget_ms > 0) {
        return timeout_budget_ms < deadline_budget_ms
            ? timeout_budget_ms
            : deadline_budget_ms;
    }
    if (timeout_budget_ms > 0) {
        return timeout_budget_ms;
    }
    if (deadline_budget_ms > 0) {
        return deadline_budget_ms;
    }
    if (king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms > 0) {
        return king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms;
    }

    return 30000;
}

static void king_orchestrator_exec_control_cleanup(
    king_orchestrator_exec_control_t *control
)
{
    if (control == NULL) {
        return;
    }

    if (!Z_ISUNDEF(control->cancel_token)) {
        zval_ptr_dtor(&control->cancel_token);
        ZVAL_UNDEF(&control->cancel_token);
    }

    control->run_id = NULL;
}

static zend_result king_orchestrator_raise_error(
    const char *message,
    zend_class_entry *exception_ce,
    zend_bool throw_on_error)
{
    king_set_error(message);

    if (throw_on_error) {
        zend_throw_exception_ex(exception_ce, 0, "%s", message);
    }

    return FAILURE;
}

static zend_bool king_orchestrator_exception_message_contains(const char *needle)
{
    zval rv;
    zval *message;

    if (needle == NULL || needle[0] == '\0' || EG(exception) == NULL) {
        return 0;
    }

    message = zend_read_property(
        zend_ce_exception,
        EG(exception),
        "message",
        sizeof("message") - 1,
        1,
        &rv
    );
    if (message == NULL || Z_TYPE_P(message) != IS_STRING) {
        return 0;
    }

    return strstr(Z_STRVAL_P(message), needle) != NULL;
}

static zend_result king_orchestrator_validate_positive_long_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];

    if (Z_TYPE_P(value) != IS_LONG) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (Z_LVAL_P(value) <= 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_orchestrator_exec_control_parse(
    zval *options,
    const char *function_name,
    zend_bool throw_on_error,
    king_orchestrator_exec_control_t *control)
{
    zval *option_value;
    zend_long deadline_ms = 0;
    char message[KING_ERR_LEN];

    if (control == NULL) {
        return FAILURE;
    }

    control->timeout_ms = king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms > 0
        ? king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms
        : 0;
    control->max_concurrency = king_mcp_orchestrator_config.orchestrator_loop_concurrency_default > 0
        ? king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
        : 1;
    control->deadline_ms = 0;
    control->started_at_ms = king_orchestrator_monotonic_time_ms();
    ZVAL_UNDEF(&control->cancel_token);
    control->run_id = NULL;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "overall_timeout_ms",
        sizeof("overall_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "overall_timeout_ms",
                &control->timeout_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "timeout_ms",
                &control->timeout_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "deadline_ms",
        sizeof("deadline_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "deadline_ms",
                &deadline_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
        control->deadline_ms = (uint64_t) deadline_ms;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "max_concurrency",
        sizeof("max_concurrency") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "max_concurrency",
                &control->max_concurrency,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    if (
        king_mcp_orchestrator_config.orchestrator_loop_concurrency_default > 0
        && control->max_concurrency > king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() option 'max_concurrency' must be <= the configured orchestrator_loop_concurrency_default (%ld).",
            function_name,
            king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "cancel",
        sizeof("cancel") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            Z_TYPE_P(option_value) != IS_OBJECT
            || !instanceof_function(Z_OBJCE_P(option_value), king_ce_cancel_token)
        ) {
            snprintf(
                message,
                sizeof(message),
                "%s() option 'cancel' must be null or King\\CancelToken.",
                function_name
            );
            return king_orchestrator_raise_error(
                message,
                king_ce_validation_exception,
                throw_on_error
            );
        }

        ZVAL_COPY(&control->cancel_token, option_value);
    }

    return SUCCESS;
}

static zend_result king_orchestrator_exec_control_check(
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error,
    zend_bool *cancelled_out)
{
    char message[KING_ERR_LEN];
    uint64_t now_ms;

    if (control == NULL) {
        return SUCCESS;
    }

    king_process_pending_interrupts();

    if (cancelled_out != NULL) {
        *cancelled_out = 0;
    }

    if (king_transport_cancel_token_is_cancelled(&control->cancel_token)) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active orchestrator run via CancelToken.",
            function_name
        );
        if (cancelled_out != NULL) {
            *cancelled_out = 1;
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (
        control->run_id != NULL
        && king_orchestrator_run_cancel_requested(control->run_id)
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active orchestrator run via the persisted file-worker cancel channel.",
            function_name
        );
        if (cancelled_out != NULL) {
            *cancelled_out = 1;
        }
        (void) king_orchestrator_pipeline_run_cancelled_classified(
            control->run_id,
            message,
            "cancelled",
            "not_applicable",
            -1,
            "file_worker"
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    now_ms = king_orchestrator_monotonic_time_ms();
    if (
        control->timeout_ms > 0
        && now_ms - control->started_at_ms >= (uint64_t) control->timeout_ms
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active orchestrator timeout budget.",
            function_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    if (control->deadline_ms > 0 && now_ms >= control->deadline_ms) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active orchestrator deadline budget.",
            function_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

