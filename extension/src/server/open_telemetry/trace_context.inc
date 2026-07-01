static zend_bool king_server_telemetry_header_name_equals_ci(
    zend_string *header_name,
    const char *expected,
    size_t expected_len
)
{
    size_t index;

    if (header_name == NULL || ZSTR_LEN(header_name) != expected_len) {
        return 0;
    }

    for (index = 0; index < expected_len; index++) {
        if (tolower((unsigned char) ZSTR_VAL(header_name)[index])
            != tolower((unsigned char) expected[index])) {
            return 0;
        }
    }

    return 1;
}

static zval *king_server_telemetry_find_request_header(
    zval *request,
    const char *header_name,
    size_t header_name_len
)
{
    zval *headers;
    zval *entry;
    zend_string *key;
    zval *value;

    if (request == NULL || Z_TYPE_P(request) != IS_ARRAY) {
        return NULL;
    }

    headers = zend_hash_str_find(
        Z_ARRVAL_P(request),
        "headers",
        sizeof("headers") - 1
    );
    if (headers == NULL || Z_TYPE_P(headers) != IS_ARRAY) {
        return NULL;
    }

    entry = zend_hash_str_find(
        Z_ARRVAL_P(headers),
        header_name,
        header_name_len
    );
    if (entry != NULL) {
        return entry;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers), key, value) {
        if (king_server_telemetry_header_name_equals_ci(
                key,
                header_name,
                header_name_len
            )) {
            return value;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_string *king_server_telemetry_copy_first_header_string(zval *header_value)
{
    zval *entry;

    if (header_value == NULL) {
        return NULL;
    }

    if (Z_TYPE_P(header_value) == IS_STRING && Z_STRLEN_P(header_value) > 0) {
        return zend_string_copy(Z_STR_P(header_value));
    }

    if (Z_TYPE_P(header_value) != IS_ARRAY) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry) {
        if (Z_TYPE_P(entry) == IS_STRING && Z_STRLEN_P(entry) > 0) {
            return zend_string_copy(Z_STR_P(entry));
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_string *king_server_telemetry_dup_lowercase_slice(
    const char *value,
    size_t value_len
)
{
    zend_string *normalized;
    size_t index;

    normalized = zend_string_alloc(value_len, 0);
    for (index = 0; index < value_len; index++) {
        ZSTR_VAL(normalized)[index] = (char) tolower((unsigned char) value[index]);
    }
    ZSTR_VAL(normalized)[value_len] = '\0';

    return normalized;
}

static zend_bool king_server_telemetry_slice_is_hex(
    const char *value,
    size_t value_len
)
{
    size_t index;

    for (index = 0; index < value_len; index++) {
        if (!isxdigit((unsigned char) value[index])) {
            return 0;
        }
    }

    return 1;
}

static zend_bool king_server_telemetry_slice_is_all_zeroes(
    const char *value,
    size_t value_len
)
{
    size_t index;

    for (index = 0; index < value_len; index++) {
        if (value[index] != '0') {
            return 0;
        }
    }

    return 1;
}

static zend_result king_server_telemetry_extract_incoming_trace_context(
    zval *request,
    zval *destination
)
{
    zval *traceparent_value;
    zval *tracestate_value;
    zend_string *traceparent = NULL;
    zend_string *trace_state = NULL;
    const char *value;

    traceparent_value = king_server_telemetry_find_request_header(
        request,
        "traceparent",
        sizeof("traceparent") - 1
    );
    if (traceparent_value == NULL) {
        return FAILURE;
    }

    traceparent = king_server_telemetry_copy_first_header_string(traceparent_value);
    if (traceparent == NULL) {
        return FAILURE;
    }

    value = ZSTR_VAL(traceparent);
    if (ZSTR_LEN(traceparent) != 55
        || value[2] != '-'
        || value[35] != '-'
        || value[52] != '-'
        || !king_server_telemetry_slice_is_hex(value, 2)
        || !king_server_telemetry_slice_is_hex(value + 3, 32)
        || !king_server_telemetry_slice_is_hex(value + 36, 16)
        || !king_server_telemetry_slice_is_hex(value + 53, 2)
        || (tolower((unsigned char) value[0]) == 'f'
            && tolower((unsigned char) value[1]) == 'f')
        || king_server_telemetry_slice_is_all_zeroes(value + 3, 32)
        || king_server_telemetry_slice_is_all_zeroes(value + 36, 16)) {
        zend_string_release(traceparent);
        return FAILURE;
    }

    array_init(destination);
    add_assoc_str(
        destination,
        "trace_id",
        king_server_telemetry_dup_lowercase_slice(value + 3, 32)
    );
    add_assoc_str(
        destination,
        "parent_span_id",
        king_server_telemetry_dup_lowercase_slice(value + 36, 16)
    );
    add_assoc_str(
        destination,
        "trace_flags",
        king_server_telemetry_dup_lowercase_slice(value + 53, 2)
    );

    tracestate_value = king_server_telemetry_find_request_header(
        request,
        "tracestate",
        sizeof("tracestate") - 1
    );
    trace_state = king_server_telemetry_copy_first_header_string(tracestate_value);
    if (trace_state != NULL) {
        add_assoc_str(destination, "trace_state", trace_state);
    }

    zend_string_release(traceparent);
    return SUCCESS;
}

static zval *king_server_telemetry_find_request_incoming_trace_context(zval *request)
{
    zval *telemetry;

    if (request == NULL || Z_TYPE_P(request) != IS_ARRAY) {
        return NULL;
    }

    telemetry = zend_hash_str_find(
        Z_ARRVAL_P(request),
        "telemetry",
        sizeof("telemetry") - 1
    );
    if (telemetry == NULL || Z_TYPE_P(telemetry) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_str_find(
        Z_ARRVAL_P(telemetry),
        "incoming_trace_context",
        sizeof("incoming_trace_context") - 1
    );
}

static zend_result king_server_telemetry_parse_trace_flags(
    zval *trace_flags,
    uint8_t *parsed_flags
)
{
    char *endptr = NULL;
    unsigned long parsed;

    if (trace_flags == NULL
        || parsed_flags == NULL
        || Z_TYPE_P(trace_flags) != IS_STRING
        || Z_STRLEN_P(trace_flags) != 2) {
        return FAILURE;
    }

    parsed = strtoul(Z_STRVAL_P(trace_flags), &endptr, 16);
    if (endptr == NULL || *endptr != '\0' || parsed > 0xffUL) {
        return FAILURE;
    }

    *parsed_flags = (uint8_t) parsed;
    return SUCCESS;
}
