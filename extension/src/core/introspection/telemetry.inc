/*
 * Root telemetry introspection slice: exposes shared last-error helpers, the
 * live current-span snapshot, finalized outgoing trace-context injection, and
 * the still-conservative userland extract placeholder.
 */

PHP_FUNCTION(king_get_last_error)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_return_shared_error(return_value);
}

PHP_FUNCTION(king_client_websocket_get_last_error)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_return_shared_error(return_value);
}

PHP_FUNCTION(king_mcp_get_error)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_return_shared_error(return_value);
}

PHP_FUNCTION(king_telemetry_get_trace_context)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_telemetry_build_trace_context_snapshot(return_value)) {
        RETURN_NULL();
    }
}

PHP_FUNCTION(king_telemetry_inject_context)
{
    zval *headers = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers)
    ZEND_PARSE_PARAMETERS_END();

    if (king_telemetry_inject_current_context_headers(return_value, headers) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "king_telemetry_inject_context() failed to materialize the outgoing trace context."
        );
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_telemetry_extract_context)
{
    zval *headers;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(headers)
    ZEND_PARSE_PARAMETERS_END();

    /*
     * Span lifecycle is live, but incoming distributed trace-context
     * extraction is still intentionally pending until the server/request
     * propagation leaves are closed.
     */
    (void) headers;
    RETURN_FALSE;
}
