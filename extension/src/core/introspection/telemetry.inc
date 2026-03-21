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

PHP_FUNCTION(king_telemetry_get_status)
{
    zval system_status;
    zval feature_status;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    array_init(&system_status);
    add_assoc_bool(&system_status, "enabled", king_open_telemetry_config.enable ? 1 : 0);
    add_assoc_string(&system_status, "service_name",
        king_safe_string(king_open_telemetry_config.service_name));
    add_assoc_string(&system_status, "exporter_endpoint",
        king_safe_string(king_open_telemetry_config.exporter_endpoint));
    add_assoc_string(&system_status, "exporter_protocol",
        king_safe_string(king_open_telemetry_config.exporter_protocol));
    add_assoc_zval(return_value, "system_status", &system_status);

    array_init(&feature_status);
    add_assoc_bool(&feature_status, "metrics_enable",
        king_open_telemetry_config.metrics_enable ? 1 : 0);
    add_assoc_bool(&feature_status, "logs_enable",
        king_open_telemetry_config.logs_enable ? 1 : 0);
    add_assoc_zval(return_value, "feature_status", &feature_status);
}

PHP_FUNCTION(king_telemetry_get_metrics)
{
    ZEND_PARSE_PARAMETERS_NONE();

    /*
     * The skeleton build wires telemetry configuration, but it has no active
     * metric registry, exporter queue, or collection state. Return a stable
     * empty list until a real runtime metrics backend exists.
     */
    array_init(return_value);
}

PHP_FUNCTION(king_telemetry_get_trace_context)
{
    ZEND_PARSE_PARAMETERS_NONE();

    /*
     * The active skeleton build wires telemetry config, but it has no live
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
     * The skeleton build does not maintain a distributed-tracing runtime or
     * current-span store. Parsing input headers would imply state that the
     * active build cannot honor yet, so extraction remains a stable false.
     */
    (void) headers;
    RETURN_FALSE;
}

PHP_FUNCTION(king_telemetry_flush)
{
    ZEND_PARSE_PARAMETERS_NONE();

    /*
     * The skeleton build wires telemetry configuration, but it has no active
     * exporter queues or pending span/metric/log batches yet. Expose a stable
     * flush report with zero exported items instead of a blind FALSE stub.
     */
    array_init(return_value);
    add_assoc_long(return_value, "spans_exported", 0);
    add_assoc_long(return_value, "metrics_exported", 0);
    add_assoc_long(return_value, "logs_exported", 0);
    add_assoc_long(return_value, "export_timestamp", (zend_long) time(NULL));
}
