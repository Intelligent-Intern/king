/*
 * Root telemetry introspection slice: exposes shared last-error helpers and
 * exposes the live current-span snapshot while keeping the not-yet-finalized
 * propagation helpers honest.
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

    /*
     * The runtime now tracks a live current span, but outward propagation is
     * still a separate contract leaf. Keep the helper conservative until the
     * outgoing trace-context injector is finalized.
     */
    if (headers != NULL) {
        RETURN_ZVAL(headers, 1, 0);
    }

    array_init(return_value);
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
