static zend_result king_mcp_set_transfer_error(
    zend_class_entry *exception_ce,
    const char *message)
{
    king_set_error(message);

    if (exception_ce != NULL) {
        zend_throw_exception_ex(exception_ce, 0, "%s", message);
    }

    return FAILURE;
}

static uint64_t king_monotonic_time_ms(void)
{
    return king_hrtime_ms();
}

static zend_result king_mcp_validate_runtime_control_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name,
    zend_class_entry *exception_ce)
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
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (Z_LVAL_P(value) <= 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_mcp_connection_options_parse(
    zval *options,
    const char *function_name,
    zend_class_entry *exception_ce,
    zend_long *default_timeout_ms)
{
    zval *timeout_option;
    zval *default_timeout_option;
    zend_string *key;
    zval *value;
    char message[KING_ERR_LEN];

    if (default_timeout_ms == NULL) {
        return FAILURE;
    }

    *default_timeout_ms = 0;

    if (options == NULL || Z_TYPE_P(options) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(options) != IS_ARRAY) {
        snprintf(message, sizeof(message), "%s() options must be an array.", function_name);
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(options), key, value) {
        if (key == NULL) {
            snprintf(message, sizeof(message), "%s() options only accept string keys.", function_name);
            return king_mcp_set_transfer_error(exception_ce, message);
        }

        if (
            !zend_string_equals_literal(key, "timeout_ms")
            && !zend_string_equals_literal(key, "default_timeout_ms")
        ) {
            snprintf(
                message,
                sizeof(message),
                "%s() option '%s' is not supported.",
                function_name,
                ZSTR_VAL(key)
            );
            return king_mcp_set_transfer_error(exception_ce, message);
        }

        (void) value;
    } ZEND_HASH_FOREACH_END();

    timeout_option = zend_hash_str_find(Z_ARRVAL_P(options), "timeout_ms", sizeof("timeout_ms") - 1);
    default_timeout_option = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "default_timeout_ms",
        sizeof("default_timeout_ms") - 1
    );

    if (
        timeout_option != NULL
        && Z_TYPE_P(timeout_option) != IS_NULL
        && default_timeout_option != NULL
        && Z_TYPE_P(default_timeout_option) != IS_NULL
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() accepts either option 'timeout_ms' or 'default_timeout_ms', not both.",
            function_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (default_timeout_option != NULL && Z_TYPE_P(default_timeout_option) != IS_NULL) {
        return king_mcp_validate_runtime_control_option(
            default_timeout_option,
            "default_timeout_ms",
            default_timeout_ms,
            function_name,
            exception_ce
        );
    }

    if (timeout_option != NULL && Z_TYPE_P(timeout_option) != IS_NULL) {
        return king_mcp_validate_runtime_control_option(
            timeout_option,
            "timeout_ms",
            default_timeout_ms,
            function_name,
            exception_ce
        );
    }

    return SUCCESS;
}

static bool king_mcp_runtime_options_has_timeout(zval *options)
{
    zval *timeout_option;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return false;
    }

    timeout_option = zend_hash_str_find(Z_ARRVAL_P(options), "timeout_ms", sizeof("timeout_ms") - 1);
    return timeout_option != NULL && Z_TYPE_P(timeout_option) != IS_NULL;
}


static zend_result king_mcp_runtime_control_parse(
    zval *options,
    zval *explicit_cancel,
    const char *function_name,
    zend_class_entry *exception_ce,
    king_mcp_runtime_control_t *control)
{
    zval *option_value;
    zval *options_cancel = NULL;
    char message[KING_ERR_LEN];
    zend_long deadline_ms = 0;

    if (control == NULL) {
        return FAILURE;
    }

    control->timeout_ms = king_mcp_orchestrator_config.mcp_default_request_timeout_ms > 0
        ? king_mcp_orchestrator_config.mcp_default_request_timeout_ms
        : 0;
    control->deadline_ms = 0;
    control->started_at_ms = king_monotonic_time_ms();
    control->cancel_token = NULL;

    if (options != NULL && Z_TYPE_P(options) == IS_ARRAY) {
        option_value = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "timeout_ms",
            sizeof("timeout_ms") - 1
        );
        if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
            if (king_mcp_validate_runtime_control_option(
                    option_value,
                    "timeout_ms",
                    &control->timeout_ms,
                    function_name,
                    exception_ce
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
            if (king_mcp_validate_runtime_control_option(
                    option_value,
                    "deadline_ms",
                    &deadline_ms,
                    function_name,
                    exception_ce
                ) != SUCCESS) {
                return FAILURE;
            }
            control->deadline_ms = (uint64_t) deadline_ms;
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
                return king_mcp_set_transfer_error(exception_ce, message);
            }

            options_cancel = option_value;
        }
    }

    if (explicit_cancel != NULL && Z_TYPE_P(explicit_cancel) != IS_NULL) {
        if (options_cancel != NULL) {
            snprintf(
                message,
                sizeof(message),
                "%s() must not receive a CancelToken in both the dedicated argument and options['cancel'].",
                function_name
            );
            return king_mcp_set_transfer_error(exception_ce, message);
        }

        control->cancel_token = explicit_cancel;
    } else {
        control->cancel_token = options_cancel;
    }

    return SUCCESS;
}

static zend_result king_mcp_runtime_control_parse_for_state(
    king_mcp_state *state,
    zval *options,
    zval *explicit_cancel,
    const char *function_name,
    zend_class_entry *exception_ce,
    king_mcp_runtime_control_t *control)
{
    if (
        king_mcp_runtime_control_parse(
            options,
            explicit_cancel,
            function_name,
            exception_ce,
            control
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (
        state != NULL
        && state->default_timeout_ms > 0
        && !king_mcp_runtime_options_has_timeout(options)
    ) {
        control->timeout_ms = state->default_timeout_ms;
    }

    return SUCCESS;
}

static zend_result king_mcp_runtime_control_check(
    king_mcp_runtime_control_t *control,
    const char *function_name,
    zend_class_entry *timeout_exception_ce,
    zend_class_entry *cancel_exception_ce)
{
    char message[KING_ERR_LEN];
    uint64_t now_ms;

    if (control == NULL) {
        return SUCCESS;
    }

    king_process_pending_interrupts();

    if (king_transport_cancel_token_is_cancelled(control->cancel_token)) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active MCP operation via CancelToken.",
            function_name
        );
        return king_mcp_set_transfer_error(cancel_exception_ce, message);
    }

    now_ms = king_monotonic_time_ms();
    if (
        control->timeout_ms > 0
        && now_ms - control->started_at_ms >= (uint64_t) control->timeout_ms
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active MCP timeout budget.",
            function_name
        );
        return king_mcp_set_transfer_error(timeout_exception_ce, message);
    }

    if (control->deadline_ms > 0 && now_ms >= control->deadline_ms) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active MCP deadline budget.",
            function_name
        );
        return king_mcp_set_transfer_error(timeout_exception_ce, message);
    }

    return SUCCESS;
}
