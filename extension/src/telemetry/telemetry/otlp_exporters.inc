static zend_bool king_telemetry_batch_has_signals(king_telemetry_batch_t *batch)
{
    if (batch == NULL) {
        return 0;
    }

    return zend_hash_num_elements(Z_ARRVAL(batch->metrics)) > 0
        || zend_hash_num_elements(Z_ARRVAL(batch->spans)) > 0
        || zend_hash_num_elements(Z_ARRVAL(batch->logs)) > 0;
}

static int king_telemetry_dispatch_otlp_json_payload(const char *endpoint_url, smart_str *json_payload)
{
    king_telemetry_http_response_t response;
    int result;
    size_t payload_bytes = 0;

    if (endpoint_url == NULL || json_payload == NULL) {
        return FAILURE;
    }

    smart_str_0(json_payload);
    if (json_payload->s != NULL) {
        payload_bytes = ZSTR_LEN(json_payload->s);
    }

    /*
     * Oversized OTLP payloads are never retryable: another flush on the same
     * batch would re-emit the same wire body. Fail closed before dispatch and
     * let the caller drop the signal instead of poison-requeueing it forever.
     */
    if (payload_bytes > KING_TELEMETRY_MAX_REQUEST_SIZE) {
        return KING_TELEMETRY_EXPORT_RESULT_DROP;
    }

    memset(&response, 0, sizeof(response));
    result = king_telemetry_http_post(
        endpoint_url,
        ZSTR_VAL(json_payload->s),
        payload_bytes,
        &response
    );

    smart_str_free(&response.data);

    if (result == KING_TELEMETRY_EXPORT_RESULT_DROP) {
        return KING_TELEMETRY_EXPORT_RESULT_DROP;
    }

    if (result == SUCCESS && response.status_code >= 200 && response.status_code < 300) {
        return SUCCESS;
    }

    return FAILURE;
}

int king_telemetry_export_batch(void)
{
    int metrics_result = SUCCESS;
    int spans_result = SUCCESS;
    int logs_result = SUCCESS;
    zend_bool attempted = 0;
    zend_bool failed = 0;

    if (!king_telemetry_system_initialized || !king_telemetry_runtime_config.enabled) {
        return FAILURE;
    }

    king_telemetry_batch_t *batch = king_telemetry_peek_batch();
    if (!batch) {
        return SUCCESS; /* No batches to export */
    }
    
    /* Export metrics via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->metrics)) > 0) {
        attempted = 1;
        metrics_result = king_telemetry_export_metrics_otlp(&batch->metrics);
        if (metrics_result != SUCCESS) {
            failed = 1;
        }
        if (!king_telemetry_batch_signal_complete(&batch->metrics, metrics_result)) {
            failed = 1;
        }
    }
    
    /* Export spans via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->spans)) > 0) {
        attempted = 1;
        spans_result = king_telemetry_export_spans_otlp(&batch->spans);
        if (spans_result != SUCCESS) {
            failed = 1;
        }
        if (!king_telemetry_batch_signal_complete(&batch->spans, spans_result)) {
            failed = 1;
        }
    }
    
    /* Export logs via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->logs)) > 0) {
        attempted = 1;
        logs_result = king_telemetry_export_logs_otlp(&batch->logs);
        if (logs_result != SUCCESS) {
            failed = 1;
        }
        if (!king_telemetry_batch_signal_complete(&batch->logs, logs_result)) {
            failed = 1;
        }
    }

    if (!attempted) {
        batch = king_telemetry_remove_head_batch_in_memory();
        if (batch != NULL) {
            (void) king_telemetry_persist_queue_state();
            king_telemetry_free_batch(batch);
        }
        return SUCCESS;
    }

    if (!failed) {
        king_telemetry_export_success_count++;
        batch = king_telemetry_remove_head_batch_in_memory();
        if (batch != NULL) {
            (void) king_telemetry_persist_queue_state();
            king_telemetry_free_batch(batch);
        }
    } else {
        king_telemetry_export_failure_count++;
        if (king_telemetry_batch_has_signals(batch)) {
            king_telemetry_batch_refresh_estimated_bytes(batch);
            king_telemetry_move_head_batch_to_tail_in_memory();
            (void) king_telemetry_persist_queue_state();
            king_telemetry_retry_requeue_count++;
        } else {
            batch = king_telemetry_remove_head_batch_in_memory();
            if (batch != NULL) {
                (void) king_telemetry_persist_queue_state();
                king_telemetry_free_batch(batch);
            }
        }
    }

    if (failed) {
        return FAILURE;
    }

    (void) metrics_result;
    (void) spans_result;
    (void) logs_result;
    return SUCCESS;
}

int king_telemetry_export_metrics_otlp(zval *metrics)
{
    if (!king_telemetry_runtime_config.otel_exporter_endpoint[0]) {
        return FAILURE; /* No endpoint configured */
    }
    
    /* Build OTLP endpoint URL for metrics */
    char endpoint_url[1024];
    snprintf(endpoint_url, sizeof(endpoint_url), "%s/v1/metrics",
             king_telemetry_runtime_config.otel_exporter_endpoint);
    
    /* Convert metrics to OTLP JSON format */
    smart_str json_payload = {0};
    smart_str_alloc(&json_payload, 4096, 0);
    
    /* Basic OTLP JSON structure for metrics */
    smart_str_appends(&json_payload, "{\"resourceMetrics\":[{\"resource\":{},\"scopeMetrics\":[{\"scope\":{},\"metrics\":[");
    
    /* Convert each metric to OTLP format */
    zval *metric_data;
    int first_metric = 1;
    
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(metrics), metric_data) {
        zval *name_zval;
        zval *value_zval;
        zval *type_zval;
        zval *timestamp_zval;
        const char *metric_type;
        double metric_value;
        zend_long metric_timestamp = 0;

        if (Z_TYPE_P(metric_data) != IS_ARRAY) {
            continue;
        }

        name_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "name", sizeof("name") - 1);
        value_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "value", sizeof("value") - 1);
        type_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "type", sizeof("type") - 1);
        timestamp_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "timestamp", sizeof("timestamp") - 1);

        if (name_zval == NULL
            || value_zval == NULL
            || type_zval == NULL
            || Z_TYPE_P(name_zval) != IS_STRING
            || Z_STRLEN_P(name_zval) == 0) {
            continue;
        }

        metric_type = king_telemetry_metric_type_from_zval(type_zval);
        if (metric_type == NULL) {
            continue;
        }

        metric_value = zval_get_double(value_zval);
        if (timestamp_zval != NULL) {
            metric_timestamp = zval_get_long(timestamp_zval);
        }

        if (!first_metric) {
            smart_str_appends(&json_payload, ",");
        }
        first_metric = 0;

        smart_str_appends(&json_payload, "{\"name\":\"");
        smart_str_append(&json_payload, Z_STR_P(name_zval));
        smart_str_appends(&json_payload, "\",\"data\":{\"dataPoints\":[{\"timeUnixNano\":\"");
        
        /* Add timestamp */
        char timestamp_str[32];
        if (metric_timestamp > 0) {
            snprintf(
                timestamp_str,
                sizeof(timestamp_str),
                "%lld%09ld",
                (long long) metric_timestamp,
                0L
            );
        } else {
            struct timespec ts;
            clock_gettime(CLOCK_REALTIME, &ts);
            snprintf(
                timestamp_str,
                sizeof(timestamp_str),
                "%lld%09ld",
                (long long) ts.tv_sec,
                ts.tv_nsec
            );
        }
        smart_str_appends(&json_payload, timestamp_str);
        smart_str_appends(&json_payload, "\",");
        
        /* Add value based on type */
        if (strcmp(metric_type, "counter") == 0) {
            smart_str_appends(&json_payload, "\"asInt\":\"");
            smart_str_append_long(&json_payload, (zend_long) metric_value);
            smart_str_appends(&json_payload, "\"");
        } else {
            smart_str_appends(&json_payload, "\"asDouble\":\"");
            smart_str_append_double(&json_payload, metric_value, 6, 0);
            smart_str_appends(&json_payload, "\"");
        }
        
        smart_str_appends(&json_payload, "}]}}");
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appends(&json_payload, "]}]}]}");
    int result = king_telemetry_dispatch_otlp_json_payload(endpoint_url, &json_payload);

    smart_str_free(&json_payload);

    return result;
}

int king_telemetry_export_spans_otlp(zval *spans)
{
    if (!king_telemetry_runtime_config.otel_exporter_endpoint[0]) {
        return FAILURE; /* No endpoint configured */
    }
    
    /* Build OTLP endpoint URL for spans */
    char endpoint_url[1024];
    snprintf(endpoint_url, sizeof(endpoint_url), "%s/v1/traces",
             king_telemetry_runtime_config.otel_exporter_endpoint);
    
    /* Convert spans to OTLP JSON format */
    smart_str json_payload = {0};
    smart_str_alloc(&json_payload, 4096, 0);
    
    /* Basic OTLP JSON structure for spans */
    smart_str_appends(&json_payload, "{\"resourceSpans\":[{\"resource\":{},\"scopeSpans\":[{\"scope\":{},\"spans\":[");
    
    /* Convert each span to OTLP format */
    zval *span_data;
    int first_span = 1;
    
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(spans), span_data) {
        zval *name_zval;
        zval *trace_id_zval;
        zval *span_id_zval;
        zval *parent_span_id_zval;
        zval *start_time_zval;
        zval *end_time_zval;
        zval *kind_zval;
        zval *attributes_zval;
        char start_time_str[32];
        char end_time_str[32];

        if (Z_TYPE_P(span_data) != IS_ARRAY) {
            continue;
        }

        name_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "name", sizeof("name") - 1);
        trace_id_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "trace_id", sizeof("trace_id") - 1);
        span_id_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "span_id", sizeof("span_id") - 1);
        parent_span_id_zval = zend_hash_str_find(
            Z_ARRVAL_P(span_data),
            "parent_span_id",
            sizeof("parent_span_id") - 1
        );
        start_time_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "start_time", sizeof("start_time") - 1);
        end_time_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "end_time", sizeof("end_time") - 1);
        kind_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "kind", sizeof("kind") - 1);
        attributes_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "attributes", sizeof("attributes") - 1);

        if (name_zval == NULL
            || trace_id_zval == NULL
            || span_id_zval == NULL
            || start_time_zval == NULL
            || end_time_zval == NULL
            || Z_TYPE_P(name_zval) != IS_STRING
            || Z_TYPE_P(trace_id_zval) != IS_STRING
            || Z_TYPE_P(span_id_zval) != IS_STRING
            || Z_STRLEN_P(name_zval) == 0
            || Z_STRLEN_P(trace_id_zval) == 0
            || Z_STRLEN_P(span_id_zval) == 0) {
            continue;
        }

        if (!first_span) {
            smart_str_appends(&json_payload, ",");
        }
        first_span = 0;

        snprintf(
            start_time_str,
            sizeof(start_time_str),
            "%lld%09ld",
            (long long) zval_get_long(start_time_zval),
            0L
        );
        snprintf(
            end_time_str,
            sizeof(end_time_str),
            "%lld%09ld",
            (long long) zval_get_long(end_time_zval),
            0L
        );

        smart_str_appends(&json_payload, "{\"traceId\":\"");
        smart_str_append_escaped(&json_payload, Z_STRVAL_P(trace_id_zval), Z_STRLEN_P(trace_id_zval));
        smart_str_appends(&json_payload, "\",\"spanId\":\"");
        smart_str_append_escaped(&json_payload, Z_STRVAL_P(span_id_zval), Z_STRLEN_P(span_id_zval));
        smart_str_appends(&json_payload, "\",\"name\":\"");
        smart_str_append_escaped(&json_payload, Z_STRVAL_P(name_zval), Z_STRLEN_P(name_zval));
        smart_str_appends(&json_payload, "\",\"startTimeUnixNano\":\"");
        smart_str_appends(&json_payload, start_time_str);
        smart_str_appends(&json_payload, "\",\"endTimeUnixNano\":\"");
        smart_str_appends(&json_payload, end_time_str);
        smart_str_appends(&json_payload, "\",\"kind\":");
        smart_str_append_long(&json_payload, king_telemetry_span_kind_to_otlp_kind(kind_zval));

        if (parent_span_id_zval != NULL
            && Z_TYPE_P(parent_span_id_zval) == IS_STRING
            && Z_STRLEN_P(parent_span_id_zval) > 0) {
            smart_str_appends(&json_payload, ",\"parentSpanId\":\"");
            smart_str_append_escaped(
                &json_payload,
                Z_STRVAL_P(parent_span_id_zval),
                Z_STRLEN_P(parent_span_id_zval)
            );
            smart_str_appends(&json_payload, "\"");
        }

        king_telemetry_json_append_attributes(&json_payload, attributes_zval);
        smart_str_appends(&json_payload, "}");
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appends(&json_payload, "]}]}]}");
    int result = king_telemetry_dispatch_otlp_json_payload(endpoint_url, &json_payload);

    smart_str_free(&json_payload);

    return result;
}

int king_telemetry_export_logs_otlp(zval *logs)
{
    if (!king_telemetry_runtime_config.otel_exporter_endpoint[0]) {
        return FAILURE; /* No endpoint configured */
    }
    
    /* Build OTLP endpoint URL for logs */
    char endpoint_url[1024];
    snprintf(endpoint_url, sizeof(endpoint_url), "%s/v1/logs",
             king_telemetry_runtime_config.otel_exporter_endpoint);
    
    /* Convert logs to OTLP JSON format */
    smart_str json_payload = {0};
    smart_str_alloc(&json_payload, 4096, 0);
    
    /* Basic OTLP JSON structure for logs */
    smart_str_appends(&json_payload, "{\"resourceLogs\":[{\"resource\":{},\"scopeLogs\":[{\"scope\":{},\"logRecords\":[");
    
    /* Convert each log entry to OTLP format */
    zval *log_data;
    int first_log = 1;
    
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(logs), log_data) {
        zval *message_zval;
        zval *level_zval;
        zval *timestamp_zval;
        zval *attributes_zval;
        zval *trace_id_zval;
        zval *span_id_zval;
        char timestamp_str[32];
        const char *level_name = "info";
        int severity_number = 9; /* INFO */

        if (Z_TYPE_P(log_data) != IS_ARRAY) {
            continue;
        }

        message_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "message", sizeof("message") - 1);
        level_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "level", sizeof("level") - 1);
        timestamp_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "timestamp", sizeof("timestamp") - 1);
        attributes_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "attributes", sizeof("attributes") - 1);
        trace_id_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "trace_id", sizeof("trace_id") - 1);
        span_id_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "span_id", sizeof("span_id") - 1);

        if (message_zval == NULL || level_zval == NULL) {
            continue;
        }

        if (Z_TYPE_P(level_zval) == IS_STRING && Z_STRLEN_P(level_zval) > 0) {
            level_name = Z_STRVAL_P(level_zval);
        }

        if (strcmp(level_name, "error") == 0) {
            severity_number = 17;
        } else if (strcmp(level_name, "warn") == 0) {
            severity_number = 13;
        } else if (strcmp(level_name, "debug") == 0) {
            severity_number = 5;
        } else if (strcmp(level_name, "trace") == 0) {
            severity_number = 1;
        }

        if (!first_log) {
            smart_str_appends(&json_payload, ",");
        }
        first_log = 0;

        smart_str_appends(&json_payload, "{\"timeUnixNano\":\"");

        if (timestamp_zval != NULL) {
            snprintf(
                timestamp_str,
                sizeof(timestamp_str),
                "%lld%09ld",
                (long long) zval_get_long(timestamp_zval),
                0L
            );
        } else {
            struct timespec ts;
            clock_gettime(CLOCK_REALTIME, &ts);
            snprintf(
                timestamp_str,
                sizeof(timestamp_str),
                "%lld%09ld",
                (long long) ts.tv_sec,
                ts.tv_nsec
            );
        }
        smart_str_appends(&json_payload, timestamp_str);
        smart_str_appends(&json_payload, "\",\"severityNumber\":");
        smart_str_append_long(&json_payload, severity_number);
        smart_str_appends(&json_payload, ",\"severityText\":\"");
        smart_str_append_escaped(&json_payload, level_name, strlen(level_name));
        smart_str_appends(&json_payload, "\",\"body\":{\"stringValue\":\"");
        king_telemetry_json_append_zval_as_string(&json_payload, message_zval);
        smart_str_appends(&json_payload, "\"}");

        if (trace_id_zval != NULL
            && Z_TYPE_P(trace_id_zval) == IS_STRING
            && Z_STRLEN_P(trace_id_zval) > 0) {
            smart_str_appends(&json_payload, ",\"traceId\":\"");
            smart_str_append_escaped(
                &json_payload,
                Z_STRVAL_P(trace_id_zval),
                Z_STRLEN_P(trace_id_zval)
            );
            smart_str_appends(&json_payload, "\"");
        }

        if (span_id_zval != NULL
            && Z_TYPE_P(span_id_zval) == IS_STRING
            && Z_STRLEN_P(span_id_zval) > 0) {
            smart_str_appends(&json_payload, ",\"spanId\":\"");
            smart_str_append_escaped(
                &json_payload,
                Z_STRVAL_P(span_id_zval),
                Z_STRLEN_P(span_id_zval)
            );
            smart_str_appends(&json_payload, "\"");
        }

        king_telemetry_json_append_attributes(&json_payload, attributes_zval);
        smart_str_appends(&json_payload, "}");
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appends(&json_payload, "]}]}]}");
    int result = king_telemetry_dispatch_otlp_json_payload(endpoint_url, &json_payload);

    smart_str_free(&json_payload);

    return result;
}
