PHP_FUNCTION(king_telemetry_init)
{
    zval *config_arr;
    if (zend_parse_parameters(1, "a", &config_arr) == FAILURE) RETURN_FALSE;

    if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config_arr)) > 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        RETURN_THROWS();
    }

    king_telemetry_config_t config;
    memset(&config, 0, sizeof(config));
    config.enabled = true;
    config.max_queue_size = king_telemetry_default_max_queue_size();
    config.max_export_batch_size = king_telemetry_default_export_chunk_size();
    config.traces_sampler_ratio = 1.0;
    if (king_telemetry_config_apply_inline_array(&config, config_arr) != SUCCESS) {
        RETURN_THROWS();
    }
    
    king_telemetry_init_system(&config);
    RETURN_TRUE;
}

PHP_FUNCTION(king_telemetry_start_span)
{
    char *op_name;
    size_t op_name_len;
    zval *attrs = NULL;
    char *parent_id = NULL;
    size_t parent_id_len = 0;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(op_name, op_name_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(attrs)
        Z_PARAM_STRING_OR_NULL(parent_id, parent_id_len)
    ZEND_PARSE_PARAMETERS_END();
    
    if (op_name_len == 0 || op_name_len > 127) {
        /* Truncate op_name or throw? Truncating is already handled by strncpy in create_span, 
           but returning false for empty or obviously malicious long names is better. */
        if (op_name_len == 0) RETURN_FALSE;
    }

    king_trace_context_t *span = king_telemetry_create_span(op_name, KING_SPAN_KIND_INTERNAL, parent_id);
    if (!span) RETURN_FALSE;

    king_telemetry_span_merge_attributes(span, attrs);
    king_current_span = span;
    
    RETURN_STRING(span->span_id);
}

PHP_FUNCTION(king_telemetry_end_span)
{
    char *span_id;
    size_t span_id_len;
    zval *final_attrs = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(span_id, span_id_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(final_attrs)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_current_span != NULL
        && strcmp(king_current_span->span_id, span_id) == 0
        && king_telemetry_end_internal_span(king_current_span, final_attrs) == SUCCESS
    ) {
        RETURN_TRUE;
    }

    RETURN_FALSE;
}

PHP_FUNCTION(king_telemetry_log)
{
    char *level_str, *msg;
    size_t level_len, msg_len;
    zval *attrs = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(level_str, level_len)
        Z_PARAM_STRING(msg, msg_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(attrs)
    ZEND_PARSE_PARAMETERS_END();

    king_telemetry_log_internal(king_telemetry_level_from_string(level_str), "php-king", msg, attrs);
    RETURN_TRUE;
}

static zend_bool king_telemetry_header_name_matches(
    zend_string *header_name,
    const char *expected_name)
{
    size_t expected_name_len;

    if (header_name == NULL || expected_name == NULL) {
        return 0;
    }

    expected_name_len = strlen(expected_name);

    return header_name != NULL
        && ZSTR_LEN(header_name) == expected_name_len
        && zend_binary_strcasecmp(
            ZSTR_VAL(header_name),
            ZSTR_LEN(header_name),
            expected_name,
            expected_name_len
        ) == 0;
}

static zend_bool king_telemetry_headers_have_explicit_trace_context(zval *headers_array)
{
    zend_string *header_name;

    if (headers_array == NULL || Z_TYPE_P(headers_array) != IS_ARRAY) {
        return 0;
    }

    ZEND_HASH_FOREACH_STR_KEY(Z_ARRVAL_P(headers_array), header_name) {
        if (king_telemetry_header_name_matches(header_name, "traceparent")
            || king_telemetry_header_name_matches(header_name, "tracestate")) {
            return 1;
        }
    } ZEND_HASH_FOREACH_END();

    return 0;
}

zend_bool king_telemetry_has_injectable_current_context(void)
{
    return king_current_span != NULL
        && strlen(king_current_span->trace_id) == 32
        && strlen(king_current_span->span_id) == 16;
}

zend_result king_telemetry_inject_current_context_headers(
    zval *destination,
    zval *headers_array)
{
    char traceparent[sizeof("00-") + 32 + 1 + 16 + 1 + 2];

    if (destination == NULL) {
        return FAILURE;
    }

    if (headers_array != NULL) {
        ZVAL_COPY(destination, headers_array);
        SEPARATE_ARRAY(destination);
    } else {
        array_init(destination);
    }

    if (!king_telemetry_has_injectable_current_context()
        || king_telemetry_headers_have_explicit_trace_context(destination)) {
        return SUCCESS;
    }

    snprintf(
        traceparent,
        sizeof(traceparent),
        "00-%s-%s-%02x",
        king_current_span->trace_id,
        king_current_span->span_id,
        (unsigned int) (king_current_span->trace_flags & 0xff)
    );
    add_assoc_string(destination, "traceparent", traceparent);

    if (king_current_span->trace_state[0] != '\0') {
        add_assoc_string(destination, "tracestate", king_current_span->trace_state);
    }

    return SUCCESS;
}

/* --- Internal Export Functions --- */

typedef struct _king_telemetry_http_response_t {
    smart_str data;
    size_t bytes;
    long status_code;
    zend_bool body_limit_exceeded;
    CURLcode curl_code;
} king_telemetry_http_response_t;

static void king_telemetry_copy_export_diagnostic_string(
    char *destination,
    size_t destination_size,
    const char *source)
{
    if (destination == NULL || destination_size == 0) {
        return;
    }

    destination[0] = '\0';
    if (source == NULL || source[0] == '\0') {
        return;
    }

    strncpy(destination, source, destination_size - 1);
    destination[destination_size - 1] = '\0';
}

static void king_telemetry_record_export_diagnostic(
    const char *signal,
    const char *stage,
    const char *reason,
    const char *disposition,
    const char *batch_id,
    const char *message,
    zend_long curl_code,
    zend_long http_status,
    size_t request_bytes,
    size_t response_bytes)
{
    memset(
        &king_telemetry_last_export_diagnostic,
        0,
        sizeof(king_telemetry_last_export_diagnostic)
    );
    king_telemetry_last_export_diagnostic.active = 1;
    king_telemetry_copy_export_diagnostic_string(
        king_telemetry_last_export_diagnostic.signal,
        sizeof(king_telemetry_last_export_diagnostic.signal),
        signal
    );
    king_telemetry_copy_export_diagnostic_string(
        king_telemetry_last_export_diagnostic.stage,
        sizeof(king_telemetry_last_export_diagnostic.stage),
        stage
    );
    king_telemetry_copy_export_diagnostic_string(
        king_telemetry_last_export_diagnostic.reason,
        sizeof(king_telemetry_last_export_diagnostic.reason),
        reason
    );
    king_telemetry_copy_export_diagnostic_string(
        king_telemetry_last_export_diagnostic.disposition,
        sizeof(king_telemetry_last_export_diagnostic.disposition),
        disposition
    );
    king_telemetry_copy_export_diagnostic_string(
        king_telemetry_last_export_diagnostic.batch_id,
        sizeof(king_telemetry_last_export_diagnostic.batch_id),
        batch_id
    );
    king_telemetry_copy_export_diagnostic_string(
        king_telemetry_last_export_diagnostic.message,
        sizeof(king_telemetry_last_export_diagnostic.message),
        message
    );
    king_telemetry_last_export_diagnostic.curl_code = curl_code;
    king_telemetry_last_export_diagnostic.http_status = http_status;
    king_telemetry_last_export_diagnostic.request_bytes = request_bytes;
    king_telemetry_last_export_diagnostic.response_bytes = response_bytes;
}

zend_bool king_telemetry_build_last_export_diagnostic(zval *destination)
{
    if (destination == NULL || !king_telemetry_last_export_diagnostic.active) {
        return 0;
    }

    array_init(destination);
    add_assoc_string(destination, "signal", king_telemetry_last_export_diagnostic.signal);
    add_assoc_string(destination, "stage", king_telemetry_last_export_diagnostic.stage);
    add_assoc_string(destination, "reason", king_telemetry_last_export_diagnostic.reason);
    add_assoc_string(
        destination,
        "disposition",
        king_telemetry_last_export_diagnostic.disposition
    );
    add_assoc_string(destination, "batch_id", king_telemetry_last_export_diagnostic.batch_id);
    add_assoc_string(destination, "message", king_telemetry_last_export_diagnostic.message);
    add_assoc_long(destination, "curl_code", king_telemetry_last_export_diagnostic.curl_code);
    add_assoc_long(destination, "http_status", king_telemetry_last_export_diagnostic.http_status);
    add_assoc_long(
        destination,
        "request_bytes",
        (zend_long) king_telemetry_last_export_diagnostic.request_bytes
    );
    add_assoc_long(
        destination,
        "response_bytes",
        (zend_long) king_telemetry_last_export_diagnostic.response_bytes
    );

    return 1;
}

static size_t king_telemetry_http_write_callback(void *ptr, size_t size, size_t nmemb, void *userdata)
{
    king_telemetry_http_response_t *response = (king_telemetry_http_response_t *)userdata;
    size_t total_size = size * nmemb;
    
    if (response->bytes + total_size > KING_TELEMETRY_MAX_RESPONSE_SIZE) {
        /*
         * Fail closed on oversized collector replies so the exporter sees a
         * retryable transport failure instead of buffering unbounded response
         * bytes inside the PHP process.
         */
        response->body_limit_exceeded = 1;
        response->bytes = (size_t) KING_TELEMETRY_MAX_RESPONSE_SIZE + total_size;
        return 0;
    }
    
    smart_str_appendl(&response->data, ptr, total_size);
    response->bytes += total_size;
    
    return total_size;
}

static int king_telemetry_classify_curl_failure(
    CURLcode res,
    const char **stage_out,
    const char **reason_out,
    const char **disposition_out)
{
    if (stage_out != NULL) {
        *stage_out = "transport";
    }
    if (reason_out != NULL) {
        *reason_out = "curl_failure";
    }
    if (disposition_out != NULL) {
        *disposition_out = "retry";
    }

    switch (res) {
        case CURLE_COULDNT_RESOLVE_PROXY:
            if (reason_out != NULL) {
                *reason_out = "could_not_resolve_proxy";
            }
            if (disposition_out != NULL) {
                *disposition_out = "drop";
            }
            return KING_TELEMETRY_EXPORT_RESULT_DROP;
        case CURLE_COULDNT_RESOLVE_HOST:
            if (reason_out != NULL) {
                *reason_out = "could_not_resolve_host";
            }
            if (disposition_out != NULL) {
                *disposition_out = "drop";
            }
            return KING_TELEMETRY_EXPORT_RESULT_DROP;
        case CURLE_UNSUPPORTED_PROTOCOL:
            if (reason_out != NULL) {
                *reason_out = "unsupported_protocol";
            }
            if (disposition_out != NULL) {
                *disposition_out = "drop";
            }
            return KING_TELEMETRY_EXPORT_RESULT_DROP;
        case CURLE_URL_MALFORMAT:
            if (reason_out != NULL) {
                *reason_out = "url_malformat";
            }
            if (disposition_out != NULL) {
                *disposition_out = "drop";
            }
            return KING_TELEMETRY_EXPORT_RESULT_DROP;
        case CURLE_NOT_BUILT_IN:
            if (reason_out != NULL) {
                *reason_out = "feature_not_built_in";
            }
            if (disposition_out != NULL) {
                *disposition_out = "drop";
            }
            return KING_TELEMETRY_EXPORT_RESULT_DROP;
        case CURLE_SSL_CONNECT_ERROR:
            if (stage_out != NULL) {
                *stage_out = "tls";
            }
            if (reason_out != NULL) {
                *reason_out = "ssl_connect_error";
            }
            return FAILURE;
        case CURLE_PEER_FAILED_VERIFICATION:
            if (stage_out != NULL) {
                *stage_out = "tls";
            }
            if (reason_out != NULL) {
                *reason_out = "peer_verification_failed";
            }
            return FAILURE;
        case CURLE_SSL_CERTPROBLEM:
            if (stage_out != NULL) {
                *stage_out = "tls";
            }
            if (reason_out != NULL) {
                *reason_out = "certificate_problem";
            }
            return FAILURE;
        case CURLE_SSL_CIPHER:
            if (stage_out != NULL) {
                *stage_out = "tls";
            }
            if (reason_out != NULL) {
                *reason_out = "cipher_negotiation_failed";
            }
            return FAILURE;
        case CURLE_SSL_CACERT_BADFILE:
            if (stage_out != NULL) {
                *stage_out = "tls";
            }
            if (reason_out != NULL) {
                *reason_out = "ca_bundle_invalid";
            }
            return FAILURE;
        case CURLE_USE_SSL_FAILED:
            if (stage_out != NULL) {
                *stage_out = "tls";
            }
            if (reason_out != NULL) {
                *reason_out = "use_ssl_failed";
            }
            return FAILURE;
        case CURLE_OPERATION_TIMEDOUT:
            if (reason_out != NULL) {
                *reason_out = "timeout";
            }
            return FAILURE;
        case CURLE_COULDNT_CONNECT:
            if (reason_out != NULL) {
                *reason_out = "connection_failed";
            }
            return FAILURE;
        case CURLE_SEND_ERROR:
            if (reason_out != NULL) {
                *reason_out = "send_error";
            }
            return FAILURE;
        case CURLE_RECV_ERROR:
            if (reason_out != NULL) {
                *reason_out = "receive_error";
            }
            return FAILURE;
        default:
            return FAILURE;
    }
}

static int king_telemetry_http_post(
    const char *url,
    const char *json_payload,
    size_t json_payload_bytes,
    const char *batch_id,
    const char *signal_name,
    king_telemetry_http_response_t *response)
{
    CURL *curl = NULL;
    CURLcode res = CURLE_FAILED_INIT;
    struct curl_slist *headers = NULL;
    char batch_id_header[96];
    const char *stage = "transport";
    const char *reason = "curl_failure";
    const char *disposition = "retry";
    const char *message = "Telemetry export failed before the collector replied.";
    int result = FAILURE;

    if (king_telemetry_ensure_libcurl_ready() != SUCCESS) {
        king_telemetry_record_export_diagnostic(
            signal_name,
            "transport",
            "libcurl_unavailable",
            "retry",
            batch_id,
            king_telemetry_libcurl.load_error[0] != '\0'
                ? king_telemetry_libcurl.load_error
                : "Telemetry exporter could not load libcurl.",
            0,
            0,
            json_payload_bytes,
            0
        );
        return FAILURE;
    }

    curl = king_telemetry_libcurl.curl_easy_init_fn();
    if (!curl) {
        king_telemetry_record_export_diagnostic(
            signal_name,
            "transport",
            "curl_easy_init_failed",
            "retry",
            batch_id,
            "Telemetry exporter could not initialize a libcurl handle.",
            0,
            0,
            json_payload_bytes,
            0
        );
        return FAILURE;
    }
    
    /* Set up headers for OTLP HTTP/JSON */
    headers = king_telemetry_libcurl.curl_slist_append_fn(headers, "Content-Type: application/json");
    headers = king_telemetry_libcurl.curl_slist_append_fn(headers, "User-Agent: king-telemetry/1.0");
    if (batch_id != NULL && batch_id[0] != '\0') {
        /*
         * Keep one stable batch identity across retry and durable replay so a
         * downstream collector can dedupe at-least-once re-delivery.
         */
        snprintf(
            batch_id_header,
            sizeof(batch_id_header),
            "X-King-Telemetry-Batch-Id: %s",
            batch_id
        );
        headers = king_telemetry_libcurl.curl_slist_append_fn(headers, batch_id_header);
    }
    
    /* Initialize response buffer */
    memset(response, 0, sizeof(king_telemetry_http_response_t));
    smart_str_alloc(&response->data, 4096, 0);
    
    /* Configure curl request */
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_URL, url);
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_POST, 1L);
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_POSTFIELDS, json_payload);
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_POSTFIELDSIZE, (long) json_payload_bytes);
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_HTTPHEADER, headers);
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_WRITEFUNCTION, king_telemetry_http_write_callback);
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_WRITEDATA, response);
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_TIMEOUT_MS, (long) king_telemetry_runtime_timeout_ms());
    king_telemetry_libcurl.curl_easy_setopt_fn(curl, CURLOPT_NOSIGNAL, 1L); /* Prevent signals in multi-threaded apps */
    
    /* Perform the request */
    res = king_telemetry_libcurl.curl_easy_perform_fn(curl);
    response->curl_code = res;

    if (res == CURLE_OK) {
        king_telemetry_libcurl.curl_easy_getinfo_fn(curl, CURLINFO_RESPONSE_CODE, &response->status_code);
    }
    
    /* Clean up */
    king_telemetry_libcurl.curl_slist_free_all_fn(headers);
    king_telemetry_libcurl.curl_easy_cleanup_fn(curl);

    if (res != CURLE_OK) {
        smart_str_free(&response->data);
        if (response->body_limit_exceeded) {
            king_telemetry_record_export_diagnostic(
                signal_name,
                "collector",
                "response_size_limit_exceeded",
                "retry",
                batch_id,
                "Collector response exceeded the 1 MiB response cap.",
                0,
                0,
                json_payload_bytes,
                response->bytes
            );
            result = FAILURE;
            goto cleanup;
        }

        result = king_telemetry_classify_curl_failure(
            res,
            &stage,
            &reason,
            &disposition
        );
        if (king_telemetry_libcurl.curl_easy_strerror_fn != NULL) {
            message = king_telemetry_libcurl.curl_easy_strerror_fn(res);
        }
        king_telemetry_record_export_diagnostic(
            signal_name,
            stage,
            reason,
            disposition,
            batch_id,
            message,
            (zend_long) res,
            0,
            json_payload_bytes,
            response->bytes
        );
        goto cleanup;
    }
    
    /* Null-terminate the response data */
    smart_str_0(&response->data);
    result = SUCCESS;

cleanup:
    return result;
}
