ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_MCP___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_request, 0, 3, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_uploadFromStream, 0, 4, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, streamIdentifier, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_downloadToStream, 0, 4, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

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
    return (uint64_t) (zend_hrtime() / 1000000ULL);
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

