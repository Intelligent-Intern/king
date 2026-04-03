static zend_bool king_object_store_registry_copy_string_option(
    zval *options,
    const char *key,
    char *destination,
    size_t destination_size,
    const char *function_name
)
{
    zval *value;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), key, strlen(key));
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' must be a string when provided.",
            function_name,
            key
        );
        return 0;
    }
    if (Z_STRLEN_P(value) >= destination_size) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' exceeds the supported length.",
            function_name,
            key
        );
        return 0;
    }

    memset(destination, 0, destination_size);
    memcpy(destination, Z_STRVAL_P(value), Z_STRLEN_P(value));
    destination[Z_STRLEN_P(value)] = '\0';
    return 1;
}

static zend_bool king_object_store_registry_validate_http_header_option(
    const char *key,
    const char *value,
    const char *function_name
)
{
    char error[256];
    int result;

    if (value == NULL || value[0] == '\0') {
        return 1;
    }

    result = king_object_store_http_header_value_validate(
        key,
        value,
        error,
        sizeof(error)
    );
    if (result == SUCCESS) {
        return 1;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "%s() %s",
        function_name,
        error[0] != '\0'
            ? error
            : "Object-store metadata contains unsupported CR/LF characters for cloud HTTP headers."
    );
    return 0;
}

static php_stream *king_object_store_registry_fetch_stream_resource(
    zval *stream_zv,
    uint32_t arg_num,
    const char *function_name
)
{
    php_stream *stream = NULL;

    if (stream_zv == NULL || Z_TYPE_P(stream_zv) != IS_RESOURCE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() argument %u must be a stream resource.",
            function_name,
            arg_num
        );
        return NULL;
    }

    php_stream_from_zval_no_verify(stream, stream_zv);
    if (stream == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() argument %u must be a valid stream resource.",
            function_name,
            arg_num
        );
        return NULL;
    }

    return stream;
}

static zend_bool king_object_store_registry_acquire_mutation_lock(
    const char *object_id,
    int *lock_fd_out,
    const char *function_name
)
{
    char error[256];

    if (lock_fd_out != NULL) {
        *lock_fd_out = -1;
    }

    if (king_object_store_acquire_object_lock(object_id, lock_fd_out, error, sizeof(error)) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() %s",
            function_name,
            error[0] != '\0' ? error : "failed to acquire the object-store mutation lock."
        );
        return 0;
    }

    return 1;
}

static void king_object_store_registry_release_mutation_lock(int *lock_fd)
{
    king_object_store_release_object_lock(lock_fd);
}

static const char *king_object_store_registry_primary_failure_message(const char *fallback_message)
{
    if (king_object_store_runtime.primary_adapter_error[0] != '\0') {
        return king_object_store_runtime.primary_adapter_error;
    }

    return fallback_message != NULL ? fallback_message : "Primary object-store backend operation failed.";
}

static zend_bool king_object_store_registry_primary_failure_is_runtime_capacity(const char *message)
{
    return message != NULL && strstr(message, "runtime capacity") != NULL;
}

static zend_bool king_object_store_registry_primary_failure_is_throttled(const char *message)
{
    return message != NULL && strstr(message, "was throttled by the configured endpoint") != NULL;
}

static zend_bool king_object_store_registry_primary_failure_is_quota_exhausted(const char *message)
{
    return message != NULL && strstr(message, "reported exhausted quota") != NULL;
}

static void king_object_store_registry_throw_classified_primary_message(
    zend_class_entry *exception_ce,
    const char *message
)
{
    const char *effective_message =
        (message != NULL && message[0] != '\0')
            ? message
            : "Primary object-store backend operation failed.";

    if (king_object_store_registry_primary_failure_is_throttled(effective_message)) {
        zend_throw_exception_ex(
            exception_ce,
            0,
            "Object-store primary backend throttled the operation; retry with backoff. %s",
            effective_message
        );
        return;
    }

    if (king_object_store_registry_primary_failure_is_quota_exhausted(effective_message)) {
        zend_throw_exception_ex(
            exception_ce,
            0,
            "Object-store primary backend rejected the operation because the configured endpoint reported exhausted quota. %s",
            effective_message
        );
        return;
    }

    zend_throw_exception_ex(exception_ce, 0, "%s", effective_message);
}

static void king_object_store_registry_throw_primary_failure(
    int result_code,
    const char *fallback_message
)
{
    const char *message = king_object_store_registry_primary_failure_message(fallback_message);

    switch (result_code) {
        case KING_OBJECT_STORE_RESULT_UNAVAILABLE:
            king_object_store_registry_throw_classified_primary_message(king_ce_runtime_exception, message);
            return;
        case KING_OBJECT_STORE_RESULT_VALIDATION:
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", message);
            return;
        default:
            king_object_store_registry_throw_classified_primary_message(king_ce_system_exception, message);
            return;
    }
}

static zend_bool king_object_store_registry_parse_rfc3339_utc(
    const char *value,
    time_t *timestamp_out
)
{
    struct tm tm_value;
    char *parsed;

    if (value == NULL || value[0] == '\0' || timestamp_out == NULL) {
        return 0;
    }

    memset(&tm_value, 0, sizeof(tm_value));
    parsed = strptime(value, "%Y-%m-%dT%H:%M:%SZ", &tm_value);
    if (parsed == NULL || *parsed != '\0') {
        return 0;
    }

    *timestamp_out = timegm(&tm_value);
    return 1;
}

static zend_bool king_object_store_registry_copy_expires_option(
    zval *options,
    const char *key,
    time_t *expires_at_out,
    const char *function_name
)
{
    zval *value;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), key, strlen(key));
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        if (Z_LVAL_P(value) < 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option '%s' must be non-negative.",
                function_name,
                key
            );
            return 0;
        }
        *expires_at_out = (time_t) Z_LVAL_P(value);
        return 1;
    }
    if (Z_TYPE_P(value) != IS_STRING
        || !king_object_store_registry_parse_rfc3339_utc(Z_STRVAL_P(value), expires_at_out)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' must be an RFC3339 UTC string or unix timestamp.",
            function_name,
            key
        );
        return 0;
    }

    return 1;
}

static zend_bool king_object_store_registry_copy_uint32_option(
    zval *options,
    const char *key,
    uint32_t *value_out,
    const char *function_name
)
{
    zval *value;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), key, strlen(key));
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0 || Z_LVAL_P(value) > UINT32_MAX) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' must be a non-negative integer.",
            function_name,
            key
        );
        return 0;
    }

    *value_out = (uint32_t) Z_LVAL_P(value);
    return 1;
}

static zend_bool king_object_store_registry_copy_bool_option(
    zval *options,
    const char *key,
    zend_bool *value_out,
    const char *function_name
)
{
    zval *value;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), key, strlen(key));
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' must be a boolean when provided.",
            function_name,
            key
        );
        return 0;
    }

    *value_out = (Z_TYPE_P(value) == IS_TRUE) ? 1 : 0;
    return 1;
}

static zend_bool king_object_store_registry_copy_object_type_option(
    zval *options,
    const char *key,
    king_object_type_t *value_out,
    const char *function_name
)
{
    zval *value;
    const char *type_name;
    size_t i;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), key, strlen(key));
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        if (Z_LVAL_P(value) < KING_OBJECT_TYPE_STATIC_ASSET
            || Z_LVAL_P(value) > KING_OBJECT_TYPE_BINARY_DATA) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option '%s' must be a valid object type integer.",
                function_name,
                key
            );
            return 0;
        }

        *value_out = (king_object_type_t) Z_LVAL_P(value);
        return 1;
    }
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' must be a string or integer when provided.",
            function_name,
            key
        );
        return 0;
    }

    for (i = KING_OBJECT_TYPE_STATIC_ASSET; i <= KING_OBJECT_TYPE_BINARY_DATA; ++i) {
        type_name = king_object_type_to_string((king_object_type_t) i);
        if (strcmp(Z_STRVAL_P(value), type_name) == 0) {
            *value_out = (king_object_type_t) i;
            return 1;
        }
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "%s() option '%s' must be one of static_asset, dynamic_content, media_file, document, cache_entry, binary_data.",
        function_name,
        key
    );
    return 0;
}

static zend_bool king_object_store_registry_copy_cache_policy_option(
    zval *options,
    const char *key,
    king_cache_policy_t *value_out,
    const char *function_name
)
{
    zval *value;
    const char *policy_name;
    size_t i;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), key, strlen(key));
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        if (Z_LVAL_P(value) < KING_CACHE_POLICY_NO_CACHE
            || Z_LVAL_P(value) > KING_CACHE_POLICY_SMART_CDN) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option '%s' must be a valid cache policy integer.",
                function_name,
                key
            );
            return 0;
        }

        *value_out = (king_cache_policy_t) Z_LVAL_P(value);
        return 1;
    }
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' must be a string or integer when provided.",
            function_name,
            key
        );
        return 0;
    }

    for (i = KING_CACHE_POLICY_NO_CACHE; i <= KING_CACHE_POLICY_SMART_CDN; ++i) {
        policy_name = king_cache_policy_to_string((king_cache_policy_t) i);
        if (strcmp(Z_STRVAL_P(value), policy_name) == 0) {
            *value_out = (king_cache_policy_t) i;
            return 1;
        }
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "%s() option '%s' must be one of no_cache, cache_control, etag, last_modified, aggressive, smart_cdn.",
        function_name,
        key
    );
    return 0;
}

static zend_bool king_object_store_registry_copy_sha256_option(
    zval *options,
    const char *key,
    char destination[65],
    const char *function_name
)
{
    zval *value;
    size_t i;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), key, strlen(key));
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) != 64) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() option '%s' must be a 64-character lowercase SHA-256 hex string.",
            function_name,
            key
        );
        return 0;
    }

    for (i = 0; i < 64; ++i) {
        if (!isxdigit((unsigned char) Z_STRVAL_P(value)[i])) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option '%s' must contain only hexadecimal characters.",
                function_name,
                key
            );
            return 0;
        }
        destination[i] = (char) tolower((unsigned char) Z_STRVAL_P(value)[i]);
    }
    destination[64] = '\0';

    return 1;
}

static void king_object_store_registry_normalize_etag(
    const char *input,
    char *output,
    size_t output_size
)
{
    const char *start = input;
    size_t length;

    if (output == NULL || output_size == 0) {
        return;
    }

    output[0] = '\0';
    if (input == NULL || input[0] == '\0') {
        return;
    }

    if ((input[0] == 'W' || input[0] == 'w') && input[1] == '/') {
        start += 2;
    }
    if (start[0] == '"') {
        start++;
    }

    length = strlen(start);
    if (length > 0 && start[length - 1] == '"') {
        length--;
    }
    if (length >= output_size) {
        length = output_size - 1;
    }

    memcpy(output, start, length);
    output[length] = '\0';
}

static zend_bool king_object_store_registry_check_write_preconditions(
    const char *object_id,
    zval *options,
    const char *function_name
)
{
    zval *value;
    king_object_metadata_t existing_metadata;
    zend_bool exists = 0;
    char current_etag[129];
    char expected_etag[129];

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_backend_read_metadata(object_id, &existing_metadata) == SUCCESS) {
        exists = 1;
    }
    king_object_store_registry_normalize_etag(existing_metadata.etag, current_etag, sizeof(current_etag));

    value = zend_hash_str_find(Z_ARRVAL_P(options), "if_match", sizeof("if_match") - 1);
    if (value != NULL && Z_TYPE_P(value) != IS_NULL) {
        if (Z_TYPE_P(value) != IS_STRING) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option 'if_match' must be a string when provided.",
                function_name
            );
            return 0;
        }

        if (strcmp(Z_STRVAL_P(value), "*") == 0) {
            if (!exists) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "%s() precondition 'if_match=*' failed for '%s'.",
                    function_name,
                    object_id
                );
                return 0;
            }
        } else {
            king_object_store_registry_normalize_etag(Z_STRVAL_P(value), expected_etag, sizeof(expected_etag));
            if (!exists || current_etag[0] == '\0' || strcmp(current_etag, expected_etag) != 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "%s() precondition 'if_match' failed for '%s'.",
                    function_name,
                    object_id
                );
                return 0;
            }
        }
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), "if_none_match", sizeof("if_none_match") - 1);
    if (value != NULL && Z_TYPE_P(value) != IS_NULL) {
        if (Z_TYPE_P(value) != IS_STRING) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option 'if_none_match' must be a string when provided.",
                function_name
            );
            return 0;
        }

        if (strcmp(Z_STRVAL_P(value), "*") == 0) {
            if (exists) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "%s() precondition 'if_none_match=*' failed for '%s'.",
                    function_name,
                    object_id
                );
                return 0;
            }
        } else {
            king_object_store_registry_normalize_etag(Z_STRVAL_P(value), expected_etag, sizeof(expected_etag));
            if (exists && current_etag[0] != '\0' && strcmp(current_etag, expected_etag) == 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "%s() precondition 'if_none_match' failed for '%s'.",
                    function_name,
                    object_id
                );
                return 0;
            }
        }
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), "expected_version", sizeof("expected_version") - 1);
    if (value != NULL && Z_TYPE_P(value) != IS_NULL) {
        if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option 'expected_version' must be a non-negative integer when provided.",
                function_name
            );
            return 0;
        }
        if (!exists || existing_metadata.version != (uint64_t) Z_LVAL_P(value)) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() precondition 'expected_version' failed for '%s'.",
                function_name,
                object_id
            );
            return 0;
        }
    }

    return 1;
}

