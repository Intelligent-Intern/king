void king_telemetry_clear_incoming_parent_context(void)
{
    memset(
        &king_telemetry_incoming_parent_context,
        0,
        sizeof(king_telemetry_incoming_parent_context)
    );
}

zend_result king_telemetry_set_incoming_parent_context(
    const char *trace_id,
    const char *parent_span_id,
    uint8_t trace_flags,
    const char *trace_state
)
{
    if (trace_id == NULL
        || parent_span_id == NULL
        || strlen(trace_id) != 32
        || strlen(parent_span_id) != 16) {
        return FAILURE;
    }

    king_telemetry_clear_incoming_parent_context();
    strncpy(
        king_telemetry_incoming_parent_context.trace_id,
        trace_id,
        sizeof(king_telemetry_incoming_parent_context.trace_id) - 1
    );
    strncpy(
        king_telemetry_incoming_parent_context.parent_span_id,
        parent_span_id,
        sizeof(king_telemetry_incoming_parent_context.parent_span_id) - 1
    );
    king_telemetry_incoming_parent_context.trace_flags = trace_flags;

    if (trace_state != NULL && trace_state[0] != '\0') {
        if (strlen(trace_state)
            >= sizeof(king_telemetry_incoming_parent_context.trace_state)) {
            king_telemetry_clear_incoming_parent_context();
            return FAILURE;
        }

        strncpy(
            king_telemetry_incoming_parent_context.trace_state,
            trace_state,
            sizeof(king_telemetry_incoming_parent_context.trace_state) - 1
        );
    }

    king_telemetry_incoming_parent_context.active = 1;
    return SUCCESS;
}

zend_result king_telemetry_set_incoming_parent_context_from_array(zval *context)
{
    zval *trace_id;
    zval *parent_span_id;
    zval *trace_flags;
    zval *trace_state;
    zend_long parsed_trace_flags = 1;

    if (context == NULL || Z_TYPE_P(context) != IS_ARRAY) {
        return FAILURE;
    }

    trace_id = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "trace_id",
        sizeof("trace_id") - 1
    );
    parent_span_id = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "parent_span_id",
        sizeof("parent_span_id") - 1
    );
    trace_flags = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "trace_flags",
        sizeof("trace_flags") - 1
    );
    trace_state = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "trace_state",
        sizeof("trace_state") - 1
    );

    if (trace_flags != NULL) {
        if (Z_TYPE_P(trace_flags) == IS_LONG) {
            parsed_trace_flags = Z_LVAL_P(trace_flags);
        } else if (Z_TYPE_P(trace_flags) == IS_STRING && Z_STRLEN_P(trace_flags) > 0) {
            parsed_trace_flags = ZEND_STRTOL(Z_STRVAL_P(trace_flags), NULL, 10);
        }
    }

    if (
        trace_id == NULL
        || Z_TYPE_P(trace_id) != IS_STRING
        || parent_span_id == NULL
        || Z_TYPE_P(parent_span_id) != IS_STRING
    ) {
        return FAILURE;
    }

    return king_telemetry_set_incoming_parent_context(
        Z_STRVAL_P(trace_id),
        Z_STRVAL_P(parent_span_id),
        (uint8_t) (parsed_trace_flags & 0xff),
        (trace_state != NULL && Z_TYPE_P(trace_state) == IS_STRING)
            ? Z_STRVAL_P(trace_state)
            : NULL
    );
}
