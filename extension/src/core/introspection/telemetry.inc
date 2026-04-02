/*
 * Root telemetry introspection slice: exposes shared last-error helpers and
 * keeps the trace-context surface honest about the current build, which has
 * config and queues but no live current-span runtime yet.
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

    /*
     * The active runtime wires telemetry config, but it has no live
     * span runtime or current-trace storage yet. Expose the current state as
     * NULL instead of FALSE so callers can treat this as a real nullable
     * snapshot API.
     */
    RETURN_NULL();
}

PHP_FUNCTION(king_telemetry_inject_context)
{
    zval *headers = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers)
    ZEND_PARSE_PARAMETERS_END();

    /*
     * Without a live span runtime there is nothing to inject. Match the
     * disabled/no-current-span path from the archived implementation and
     * return the provided headers unchanged, or an empty array if none were
     * provided.
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
     * The current runtime does not maintain a distributed-tracing runtime or
     * current-span store. Parsing input headers would imply state that the
     * active build cannot honor yet, so extraction remains a stable false.
     */
    (void) headers;
    RETURN_FALSE;
}
