#include "object_store/object_store_internal.h"
#include "main/php_streams.h"
#include "ext/hash/php_hash.h"
#include "ext/hash/php_hash_sha.h"
#include <inttypes.h>
#include <ctype.h>
#include <unistd.h>

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

static void king_object_store_registry_throw_primary_failure(
    int result_code,
    const char *fallback_message
)
{
    const char *message = king_object_store_registry_primary_failure_message(fallback_message);

    switch (result_code) {
        case KING_OBJECT_STORE_RESULT_UNAVAILABLE:
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", message);
            return;
        case KING_OBJECT_STORE_RESULT_VALIDATION:
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", message);
            return;
        default:
            zend_throw_exception_ex(king_ce_system_exception, 0, "%s", message);
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

static zend_bool king_object_store_registry_apply_put_options(
    zval *options,
    const zend_string *payload,
    king_object_metadata_t *metadata,
    const char *function_name
);

static zend_bool king_object_store_registry_apply_put_metadata_options(
    zval *options,
    king_object_metadata_t *metadata,
    const char *function_name
)
{
    if (metadata == NULL) {
        return 0;
    }

    if (!king_object_store_registry_copy_string_option(
            options,
            "content_type",
            metadata->content_type,
            sizeof(metadata->content_type),
            function_name
        )
        || !king_object_store_registry_copy_string_option(
            options,
            "content_encoding",
            metadata->content_encoding,
            sizeof(metadata->content_encoding),
            function_name
        )
        || !king_object_store_registry_copy_expires_option(
            options,
            "expires_at",
            &metadata->expires_at,
            function_name
        )
        || !king_object_store_registry_copy_uint32_option(
            options,
            "cache_ttl_sec",
            &metadata->cache_ttl_seconds,
            function_name
        )
        || !king_object_store_registry_copy_object_type_option(
            options,
            "object_type",
            &metadata->object_type,
            function_name
        )
        || !king_object_store_registry_copy_cache_policy_option(
            options,
            "cache_policy",
            &metadata->cache_policy,
            function_name
        )) {
        return 0;
    }

    if (!king_object_store_registry_validate_http_header_option(
            "content_type",
            metadata->content_type,
            function_name
        ) || !king_object_store_registry_validate_http_header_option(
            "content_encoding",
            metadata->content_encoding,
            function_name
        )) {
        return 0;
    }

    return 1;
}

static zend_bool king_object_store_registry_apply_put_integrity_option(
    zval *options,
    const char *computed_sha256,
    king_object_metadata_t *metadata,
    const char *function_name
)
{
    char provided_sha256[65];

    if (metadata == NULL || computed_sha256 == NULL) {
        return 0;
    }

    memset(provided_sha256, 0, sizeof(provided_sha256));
    if (!king_object_store_registry_copy_sha256_option(
            options,
            "integrity_sha256",
            provided_sha256,
            function_name
        )) {
        return 0;
    }

    if (provided_sha256[0] != '\0') {
        if (strcmp(computed_sha256, provided_sha256) != 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option 'integrity_sha256' does not match the provided payload.",
                function_name
            );
            return 0;
        }
        memcpy(metadata->integrity_sha256, provided_sha256, sizeof(provided_sha256));
    } else {
        memcpy(metadata->integrity_sha256, computed_sha256, 65);
    }

    return 1;
}

static zend_bool king_object_store_registry_apply_put_options(
    zval *options,
    const zend_string *payload,
    king_object_metadata_t *metadata,
    const char *function_name
)
{
    char computed_sha256[65];

    if (metadata == NULL) {
        return 0;
    }

    if (!king_object_store_registry_apply_put_metadata_options(
            options,
            metadata,
            function_name
        )) {
        return 0;
    }

    king_object_store_compute_sha256_hex(
        ZSTR_VAL(payload),
        ZSTR_LEN(payload),
        computed_sha256
    );
    if (!king_object_store_registry_apply_put_integrity_option(
            options,
            computed_sha256,
            metadata,
            function_name
        )) {
        return 0;
    }

    return 1;
}

static zend_bool king_object_store_registry_validate_capacity_for_size(
    const char *object_id,
    uint64_t new_size,
    const char *function_name
)
{
    char error[256];

    if (!king_object_store_runtime.initialized) {
        return 1;
    }

    if (!king_object_store_runtime_capacity_is_enabled()) {
        return 1;
    }

    error[0] = '\0';
    if (king_object_store_runtime_capacity_check_object_size(
            object_id,
            new_size,
            error,
            sizeof(error)
        ) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() would exceed the configured object-store runtime capacity.",
            function_name
        );
        return 0;
    }

    return 1;
}

static zend_bool king_object_store_registry_stage_stream_to_temp_file(
    php_stream *stream,
    const char *function_name,
    char *temp_path,
    size_t temp_path_size,
    uint64_t max_content_length,
    uint64_t *content_length_out,
    char computed_sha256[65]
)
{
    char template_path[1024];
    const char *base_directory;
    int temp_fd;
    FILE *temp_fp = NULL;
    unsigned char digest[32];
    PHP_SHA256_CTX sha_ctx;
    unsigned char *buffer = NULL;
    size_t chunk_bytes;
    uint64_t total = 0;
    static const char hex[] = "0123456789abcdef";
    size_t i;

    if (temp_path == NULL || temp_path_size == 0 || content_length_out == NULL || computed_sha256 == NULL) {
        return 0;
    }

    *content_length_out = 0;
    temp_path[0] = '\0';
    computed_sha256[0] = '\0';

    base_directory = king_object_store_runtime.config.storage_root_path[0] != '\0'
        ? king_object_store_runtime.config.storage_root_path
        : "/tmp";
    if (snprintf(template_path, sizeof(template_path), "%s/.king_object_stream_XXXXXX", base_directory) >= (int) sizeof(template_path)) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "%s() could not allocate a bounded-memory staging path.",
            function_name
        );
        return 0;
    }

    temp_fd = mkstemp(template_path);
    if (temp_fd < 0) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "%s() failed to create a bounded-memory staging file.",
            function_name
        );
        return 0;
    }

    temp_fp = fdopen(temp_fd, "w+b");
    if (temp_fp == NULL) {
        close(temp_fd);
        unlink(template_path);
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "%s() failed to open the bounded-memory staging file.",
            function_name
        );
        return 0;
    }

    chunk_bytes = (size_t) king_object_store_runtime.config.chunk_size_kb * 1024U;
    if (chunk_bytes == 0) {
        chunk_bytes = 64 * 1024;
    }
    buffer = emalloc(chunk_bytes);
    PHP_SHA256Init(&sha_ctx);

    while (1) {
        size_t requested_bytes = chunk_bytes;
        size_t bytes_read;

        if (max_content_length > 0) {
            uint64_t remaining = total < max_content_length ? (max_content_length - total) : 0;

            if (remaining == 0) {
                requested_bytes = 1;
            } else if (remaining < (uint64_t) (chunk_bytes - 1)) {
                requested_bytes = (size_t) remaining + 1;
            }
        }

        bytes_read = php_stream_read(stream, (char *) buffer, requested_bytes);

        if (bytes_read == 0) {
            if (php_stream_eof(stream)) {
                break;
            }

            efree(buffer);
            fclose(temp_fp);
            unlink(template_path);
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "%s() failed while reading from the provided stream.",
                function_name
            );
            return 0;
        }

        if (max_content_length > 0 && total + (uint64_t) bytes_read > max_content_length) {
            efree(buffer);
            fclose(temp_fp);
            unlink(template_path);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() stream payload exceeded configured chunk_size_kb limit of %" PRIu64 " bytes.",
                function_name,
                max_content_length
            );
            return 0;
        }

        if (fwrite(buffer, 1, bytes_read, temp_fp) != bytes_read) {
            efree(buffer);
            fclose(temp_fp);
            unlink(template_path);
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "%s() failed while writing to the bounded-memory staging file.",
                function_name
            );
            return 0;
        }

        PHP_SHA256Update(&sha_ctx, buffer, bytes_read);
        total += (uint64_t) bytes_read;
    }

    PHP_SHA256Final(digest, &sha_ctx);
    efree(buffer);
    fclose(temp_fp);

    for (i = 0; i < sizeof(digest); ++i) {
        computed_sha256[i * 2] = hex[(digest[i] >> 4) & 0x0f];
        computed_sha256[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    computed_sha256[64] = '\0';
    *content_length_out = total;
    snprintf(temp_path, temp_path_size, "%s", template_path);
    return 1;
}

static zend_bool king_object_store_registry_extract_range_options(
    zval *options,
    size_t *offset_out,
    size_t *length_out,
    zend_bool *has_length_out,
    const char *function_name
)
{
    zval *value;

    *offset_out = 0;
    *length_out = 0;
    *has_length_out = 0;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return 1;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), "offset", sizeof("offset") - 1);
    if (value != NULL && Z_TYPE_P(value) != IS_NULL) {
        if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option 'offset' must be a non-negative integer when provided.",
                function_name
            );
            return 0;
        }
        *offset_out = (size_t) Z_LVAL_P(value);
    }

    value = zend_hash_str_find(Z_ARRVAL_P(options), "length", sizeof("length") - 1);
    if (value != NULL && Z_TYPE_P(value) != IS_NULL) {
        if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() option 'length' must be a non-negative integer when provided.",
                function_name
            );
            return 0;
        }
        *length_out = (size_t) Z_LVAL_P(value);
        *has_length_out = 1;
    }

    return 1;
}

static void king_object_store_registry_upload_status_to_array(
    zval *return_value,
    const king_object_store_upload_status_t *status
)
{
    array_init(return_value);

    if (status == NULL) {
        return;
    }

    add_assoc_string(return_value, "upload_id", status->upload_id);
    add_assoc_string(return_value, "object_id", status->object_id);
    add_assoc_string(return_value, "backend", (char *) king_storage_backend_to_string(status->backend));
    add_assoc_string(return_value, "protocol", (char *) king_object_store_upload_protocol_to_string(status->protocol));
    add_assoc_long(return_value, "uploaded_bytes", (zend_long) status->uploaded_bytes);
    add_assoc_long(return_value, "next_offset", (zend_long) status->next_offset);
    add_assoc_long(return_value, "chunk_size_bytes", (zend_long) status->chunk_size_bytes);
    add_assoc_long(return_value, "next_part_number", (zend_long) status->next_part_number);
    add_assoc_long(return_value, "uploaded_part_count", (zend_long) status->uploaded_part_count);
    add_assoc_long(return_value, "created_at", (zend_long) status->created_at);
    add_assoc_long(return_value, "updated_at", (zend_long) status->updated_at);
    add_assoc_bool(return_value, "sequential_chunks_required", status->sequential_chunks_required ? 1 : 0);
    add_assoc_bool(return_value, "final_chunk_may_be_shorter", status->final_chunk_may_be_shorter ? 1 : 0);
    add_assoc_bool(return_value, "final_chunk_received", status->final_chunk_received ? 1 : 0);
    add_assoc_bool(return_value, "remote_completed", status->remote_completed ? 1 : 0);
    add_assoc_bool(return_value, "recovered_after_restart", status->recovered_after_restart ? 1 : 0);
    add_assoc_bool(return_value, "completed", status->completed ? 1 : 0);
    add_assoc_bool(return_value, "aborted", status->aborted ? 1 : 0);
}

PHP_FUNCTION(king_object_store_put)
{
    zend_string *object_id;
    zend_string *payload;
    zval *options = NULL;
    king_object_metadata_t metadata;
    int mutation_lock_fd = -1;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    memset(&metadata, 0, sizeof(metadata));
    if (object_id) {
        strncpy(metadata.object_id, ZSTR_VAL(object_id), sizeof(metadata.object_id) - 1);
    }
    metadata.content_length = ZSTR_LEN(payload);
    metadata.created_at = time(NULL);
    metadata.modified_at = metadata.created_at;

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_object_store_registry_acquire_mutation_lock(
            ZSTR_VAL(object_id),
            &mutation_lock_fd,
            "king_object_store_put"
        )) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_validate_capacity_for_size(
            ZSTR_VAL(object_id),
            (uint64_t) ZSTR_LEN(payload),
            "king_object_store_put"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }

    if (!king_object_store_registry_apply_put_options(
            options,
            payload,
            &metadata,
            "king_object_store_put"
        )
        || !king_object_store_registry_check_write_preconditions(
            ZSTR_VAL(object_id),
            options,
            "king_object_store_put"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }

    {
        int result = king_object_store_write_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(payload),
            ZSTR_LEN(payload),
            &metadata
        );

        if (result != SUCCESS) {
            king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
            king_object_store_registry_throw_primary_failure(
                result,
                "Primary object-store backend write failed."
            );
            RETURN_THROWS();
        }
    }

    king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_put_from_stream)
{
    zend_string *object_id;
    zval *stream_zv;
    zval *options = NULL;
    php_stream *stream;
    king_object_metadata_t metadata;
    char temp_path[1024];
    char computed_sha256[65];
    uint64_t content_length = 0;
    zend_bool staged = 0;
    int mutation_lock_fd = -1;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    stream = king_object_store_registry_fetch_stream_resource(stream_zv, 2, "king_object_store_put_from_stream");
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_acquire_mutation_lock(
            ZSTR_VAL(object_id),
            &mutation_lock_fd,
            "king_object_store_put_from_stream"
        )) {
        RETURN_THROWS();
    }

    memset(&metadata, 0, sizeof(metadata));
    strncpy(metadata.object_id, ZSTR_VAL(object_id), sizeof(metadata.object_id) - 1);
    if (!king_object_store_registry_apply_put_metadata_options(
            options,
            &metadata,
            "king_object_store_put_from_stream"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }
    if (!king_object_store_registry_stage_stream_to_temp_file(
            stream,
            "king_object_store_put_from_stream",
            temp_path,
            sizeof(temp_path),
            0,
            &content_length,
            computed_sha256
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }
    staged = 1;

    if (!king_object_store_registry_check_write_preconditions(
            ZSTR_VAL(object_id),
            options,
            "king_object_store_put_from_stream"
        )
        || !king_object_store_registry_validate_capacity_for_size(
            ZSTR_VAL(object_id),
            content_length,
            "king_object_store_put_from_stream"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        unlink(temp_path);
        RETURN_THROWS();
    }

    metadata.content_length = content_length;
    if (!king_object_store_registry_apply_put_integrity_option(
            options,
            computed_sha256,
            &metadata,
            "king_object_store_put_from_stream"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        if (staged) {
            unlink(temp_path);
        }
        RETURN_THROWS();
    }

    {
        int result = king_object_store_write_object_from_file(
            ZSTR_VAL(object_id),
            temp_path,
            &metadata
        );

        if (result != SUCCESS) {
            king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
            if (staged) {
                unlink(temp_path);
            }
            king_object_store_registry_throw_primary_failure(
                result,
                "Primary object-store backend write failed."
            );
            RETURN_THROWS();
        }
    }

    if (staged) {
        unlink(temp_path);
    }
    king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_begin_resumable_upload)
{
    zend_string *object_id;
    zval *options = NULL;
    king_object_metadata_t metadata;
    king_object_store_upload_status_t status;
    char expected_integrity_sha256[65];
    char upload_id[65];
    char error[512];
    int mutation_lock_fd = -1;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "Object-store registry is unavailable.");
        RETURN_THROWS();
    }
    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    memset(&metadata, 0, sizeof(metadata));
    memset(&status, 0, sizeof(status));
    memset(expected_integrity_sha256, 0, sizeof(expected_integrity_sha256));
    metadata.created_at = time(NULL);
    metadata.modified_at = metadata.created_at;
    strncpy(metadata.object_id, ZSTR_VAL(object_id), sizeof(metadata.object_id) - 1);

    if (!king_object_store_registry_acquire_mutation_lock(
            ZSTR_VAL(object_id),
            &mutation_lock_fd,
            "king_object_store_begin_resumable_upload"
        )) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_apply_put_metadata_options(
            options,
            &metadata,
            "king_object_store_begin_resumable_upload"
        )
        || !king_object_store_registry_check_write_preconditions(
            ZSTR_VAL(object_id),
            options,
            "king_object_store_begin_resumable_upload"
        )
        || !king_object_store_registry_copy_sha256_option(
            options,
            "integrity_sha256",
            expected_integrity_sha256,
            "king_object_store_begin_resumable_upload"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }

    {
        int result = king_object_store_begin_upload_session(
            ZSTR_VAL(object_id),
            &metadata,
            expected_integrity_sha256,
            mutation_lock_fd,
            upload_id,
            &status,
            error,
            sizeof(error)
        );

        if (result != SUCCESS) {
            king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
            if (result == KING_OBJECT_STORE_RESULT_UNAVAILABLE) {
                zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", error);
            } else {
                zend_throw_exception_ex(king_ce_system_exception, 0, "%s", error);
            }
            RETURN_THROWS();
        }
    }
    mutation_lock_fd = -1;

    king_object_store_registry_upload_status_to_array(return_value, &status);
}

PHP_FUNCTION(king_object_store_append_resumable_upload_chunk)
{
    zend_string *upload_id;
    zval *stream_zv;
    zval *options = NULL;
    php_stream *stream;
    char temp_path[1024];
    char computed_sha256[65];
    uint64_t content_length = 0;
    zend_bool staged = 0;
    zend_bool is_final_chunk = 0;
    king_object_store_upload_status_t status;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(upload_id)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    stream = king_object_store_registry_fetch_stream_resource(
        stream_zv,
        2,
        "king_object_store_append_resumable_upload_chunk"
    );
    if (stream == NULL) {
        RETURN_THROWS();
    }
    if (!king_object_store_registry_copy_bool_option(
            options,
            "final",
            &is_final_chunk,
            "king_object_store_append_resumable_upload_chunk"
        )) {
        RETURN_THROWS();
    }
    if (!king_object_store_registry_stage_stream_to_temp_file(
            stream,
            "king_object_store_append_resumable_upload_chunk",
            temp_path,
            sizeof(temp_path),
            (uint64_t) king_object_store_runtime.config.chunk_size_kb * 1024U,
            &content_length,
            computed_sha256
        )) {
        RETURN_THROWS();
    }
    staged = 1;

    memset(&status, 0, sizeof(status));
    error[0] = '\0';
    if (king_object_store_append_upload_session_chunk(
            ZSTR_VAL(upload_id),
            temp_path,
            content_length,
            is_final_chunk,
            &status,
            error,
            sizeof(error)
        ) != SUCCESS) {
        if (staged) {
            unlink(temp_path);
        }
        if (strstr(error, "runtime capacity") != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", error);
            RETURN_THROWS();
        }
        zend_throw_exception_ex(king_ce_system_exception, 0, "%s", error);
        RETURN_THROWS();
    }

    if (staged) {
        unlink(temp_path);
    }
    king_object_store_registry_upload_status_to_array(return_value, &status);
}

PHP_FUNCTION(king_object_store_complete_resumable_upload)
{
    zend_string *upload_id;
    king_object_store_upload_status_t status;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(upload_id)
    ZEND_PARSE_PARAMETERS_END();

    memset(&status, 0, sizeof(status));
    error[0] = '\0';
    if (king_object_store_complete_upload_session(
            ZSTR_VAL(upload_id),
            &status,
            error,
            sizeof(error)
        ) != SUCCESS) {
        if (strstr(error, "runtime capacity") != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", error);
            RETURN_THROWS();
        }
        zend_throw_exception_ex(king_ce_system_exception, 0, "%s", error);
        RETURN_THROWS();
    }

    king_object_store_registry_upload_status_to_array(return_value, &status);
}

PHP_FUNCTION(king_object_store_abort_resumable_upload)
{
    zend_string *upload_id;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(upload_id)
    ZEND_PARSE_PARAMETERS_END();

    error[0] = '\0';
    if (king_object_store_abort_upload_session(
            ZSTR_VAL(upload_id),
            error,
            sizeof(error)
        ) != SUCCESS) {
        zend_throw_exception_ex(king_ce_system_exception, 0, "%s", error);
        RETURN_THROWS();
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_get_resumable_upload_status)
{
    zend_string *upload_id;
    king_object_store_upload_status_t status;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(upload_id)
    ZEND_PARSE_PARAMETERS_END();

    memset(&status, 0, sizeof(status));
    error[0] = '\0';
    if (king_object_store_get_upload_session_status(
            ZSTR_VAL(upload_id),
            &status,
            error,
            sizeof(error)
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_object_store_registry_upload_status_to_array(return_value, &status);
}

PHP_FUNCTION(king_object_store_get)
{
    zend_string *object_id;
    zval *options = NULL;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    size_t offset = 0;
    size_t length = 0;
    zend_bool has_length = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_object_store_registry_extract_range_options(
            options,
            &offset,
            &length,
            &has_length,
            "king_object_store_get"
        )) {
        RETURN_THROWS();
    }

    if (has_length && length == 0) {
        RETURN_EMPTY_STRING();
    }

    if (!king_object_store_runtime.initialized) {
        RETURN_FALSE;
    }

    memset(&metadata, 0, sizeof(metadata));
    {
        int result = (offset > 0 || has_length)
        ? king_object_store_read_object_range(
            ZSTR_VAL(object_id),
            offset,
            length,
            has_length,
            &data,
            &data_size,
            NULL
        )
        : king_object_store_read_object(ZSTR_VAL(object_id), &data, &data_size, &metadata);

        if (result == KING_OBJECT_STORE_RESULT_MISS) {
            RETURN_FALSE;
        }
        if (result != SUCCESS) {
            king_object_store_registry_throw_primary_failure(
                result,
                (offset > 0 || has_length)
                    ? "Primary object-store backend ranged read failed."
                    : "Primary object-store backend read failed."
            );
            RETURN_THROWS();
        }
    }

    zend_string *ret_str = zend_string_init(data, data_size, 0);
    if (data_size > 0) {
        memset(data, 0, data_size);
    }
    pefree(data, 1);
    RETURN_STR(ret_str);
}

PHP_FUNCTION(king_object_store_get_to_stream)
{
    zend_string *object_id;
    zval *stream_zv;
    zval *options = NULL;
    php_stream *destination_stream;
    size_t offset = 0;
    size_t length = 0;
    zend_bool has_length = 0;
    king_object_metadata_t metadata;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    destination_stream = king_object_store_registry_fetch_stream_resource(stream_zv, 2, "king_object_store_get_to_stream");
    if (destination_stream == NULL) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_extract_range_options(
            options,
            &offset,
            &length,
            &has_length,
            "king_object_store_get_to_stream"
        )) {
        RETURN_THROWS();
    }

    if (has_length && length == 0) {
        RETURN_TRUE;
    }

    if (!king_object_store_runtime.initialized) {
        RETURN_FALSE;
    }

    memset(&metadata, 0, sizeof(metadata));
    {
        int result = king_object_store_read_object_to_stream(
            ZSTR_VAL(object_id),
            destination_stream,
            offset,
            length,
            has_length,
            (offset > 0 || has_length) ? NULL : &metadata
        );

        if (result == KING_OBJECT_STORE_RESULT_MISS) {
            RETURN_FALSE;
        }
        if (result != SUCCESS) {
            king_object_store_registry_throw_primary_failure(
                result,
                (offset > 0 || has_length)
                    ? "Primary object-store backend ranged read failed."
                    : "Primary object-store backend read failed."
            );
            RETURN_THROWS();
        }
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_delete)
{
    zend_string *object_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    {
        int result = king_object_store_remove_object(ZSTR_VAL(object_id));

        if (result == SUCCESS) {
            RETURN_TRUE;
        }
        if (result == KING_OBJECT_STORE_RESULT_MISS || result == KING_OBJECT_STORE_RESULT_CONFLICT) {
            RETURN_FALSE;
        }

        king_object_store_registry_throw_primary_failure(
            result,
            "Primary object-store backend remove failed."
        );
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_object_store_list)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    if (!king_object_store_runtime.initialized) {
        return;
    }

    {
        int result = king_object_store_list_object(return_value);

        if (result != SUCCESS) {
            zval_ptr_dtor(return_value);
            array_init(return_value);
            king_object_store_registry_throw_primary_failure(
                result,
                "Primary object-store backend list failed."
            );
            RETURN_THROWS();
        }
    }
}

PHP_FUNCTION(king_object_store_cleanup_expired_objects)
{
    uint64_t scanned = 0;
    uint64_t removed = 0;
    uint64_t bytes_reclaimed = 0;
    uint64_t failures = 0;

    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_object_store_runtime.initialized) {
        return;
    }

    if (king_object_store_cleanup_expired_objects(
            &scanned,
            &removed,
            &bytes_reclaimed,
            &failures
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    array_init(return_value);
    add_assoc_string(return_value, "mode", "expiry_cleanup");
    add_assoc_long(return_value, "scanned_objects", (zend_long) scanned);
    add_assoc_long(return_value, "expired_objects_removed", (zend_long) removed);
    add_assoc_long(return_value, "bytes_reclaimed", (zend_long) bytes_reclaimed);
    add_assoc_long(return_value, "removal_failures", (zend_long) failures);
    add_assoc_long(return_value, "cleanup_at", (zend_long) time(NULL));
}

PHP_FUNCTION(king_object_store_get_metadata)
{
    zend_string *object_id;
    king_object_metadata_t metadata;
    int result;
    king_storage_backend_t primary_backend;
    king_storage_backend_t backup_backend;
    struct stat root_stat;
    zend_bool use_local_cache_fallback = 0;
    zend_bool backup_backend_is_real_cloud = 0;
    zend_bool primary_cache_snapshot_matches_route = 0;
    zend_bool backup_cache_snapshot_matches_route = 0;
    const char *root_error = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        return;
    }
    if (king_object_store_public_object_id_validate_zstr(object_id) != NULL) {
        return;
    }

    primary_backend = king_object_store_runtime.config.primary_backend;
    backup_backend = king_object_store_runtime.config.backup_backend;
    if (backup_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        backup_backend = KING_STORAGE_BACKEND_LOCAL_FS;
    }
    use_local_cache_fallback =
        primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
        || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE;

    if (use_local_cache_fallback) {
        if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
            root_error = "the configured storage_root_path is empty.";
        } else if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0) {
            root_error = strerror(errno);
        } else if (!S_ISDIR(root_stat.st_mode)) {
            root_error = "the configured storage_root_path is not a directory.";
        }

        if (root_error != NULL) {
            backup_backend_is_real_cloud =
                backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
                || backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                || backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE;
            if (king_object_store_runtime_metadata_cache_read(ZSTR_VAL(object_id), &metadata) == SUCCESS) {
                primary_cache_snapshot_matches_route =
                    metadata.local_fs_present != 0
                    || metadata.is_backed_up != 0;
                backup_cache_snapshot_matches_route =
                    (backup_backend == KING_STORAGE_BACKEND_DISTRIBUTED
                        && metadata.distributed_present != 0)
                    ||
                    (backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
                        && (metadata.cloud_s3_present != 0 || metadata.is_backed_up != 0))
                    || (backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                        && metadata.cloud_gcs_present != 0)
                    || (backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE
                        && metadata.cloud_azure_present != 0);
            }

            if (backup_backend != primary_backend
                && backup_backend_is_real_cloud
                && primary_cache_snapshot_matches_route
                && backup_cache_snapshot_matches_route) {
                result = SUCCESS;
            } else {
                snprintf(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "local_fs metadata read failed: %s",
                    root_error
                );
                result = FAILURE;
            }
        } else {
            result = king_object_store_backend_read_metadata(ZSTR_VAL(object_id), &metadata);
        }
    } else {
        result = king_object_store_backend_read_metadata(ZSTR_VAL(object_id), &metadata);
    }

    if (result == KING_OBJECT_STORE_RESULT_MISS) {
        RETURN_FALSE;
    }
    if (result != SUCCESS) {
        king_object_store_registry_throw_primary_failure(
            result,
            "Primary object-store backend metadata read failed."
        );
        RETURN_THROWS();
    }

    array_init(return_value);
    add_assoc_string(return_value, "object_id", metadata.object_id);
    add_assoc_string(return_value, "content_type", metadata.content_type[0] != '\0' ? metadata.content_type : "");
    add_assoc_string(return_value, "content_encoding", metadata.content_encoding[0] != '\0' ? metadata.content_encoding : "");
    add_assoc_string(return_value, "etag", metadata.etag[0] != '\0' ? metadata.etag : "");
    add_assoc_string(return_value, "integrity_sha256", metadata.integrity_sha256[0] != '\0' ? metadata.integrity_sha256 : "");
    add_assoc_string(return_value, "object_type_name", king_object_type_to_string(metadata.object_type));
    add_assoc_string(return_value, "cache_policy_name", king_cache_policy_to_string(metadata.cache_policy));
    add_assoc_long(return_value, "content_length", (zend_long) metadata.content_length);
    add_assoc_long(return_value, "version", (zend_long) metadata.version);
    add_assoc_long(return_value, "created_at", (zend_long) metadata.created_at);
    add_assoc_long(return_value, "modified_at", (zend_long) metadata.modified_at);
    add_assoc_long(return_value, "expires_at", (zend_long) metadata.expires_at);
    add_assoc_bool(return_value, "is_expired", king_object_store_metadata_is_expired_now(&metadata));
    add_assoc_long(return_value, "object_type", (zend_long) metadata.object_type);
    add_assoc_long(return_value, "cache_policy", (zend_long) metadata.cache_policy);
    add_assoc_long(return_value, "cache_ttl_seconds", (zend_long) metadata.cache_ttl_seconds);
    add_assoc_long(return_value, "local_fs_present", (zend_long) metadata.local_fs_present);
    add_assoc_long(return_value, "distributed_present", (zend_long) metadata.distributed_present);
    add_assoc_long(return_value, "cloud_s3_present", (zend_long) metadata.cloud_s3_present);
    add_assoc_long(return_value, "cloud_gcs_present", (zend_long) metadata.cloud_gcs_present);
    add_assoc_long(return_value, "cloud_azure_present", (zend_long) metadata.cloud_azure_present);
    add_assoc_long(return_value, "is_backed_up", (zend_long) metadata.is_backed_up);
    add_assoc_long(return_value, "replication_status", (zend_long) metadata.replication_status);
    add_assoc_long(return_value, "is_distributed", (zend_long) metadata.is_distributed);
    add_assoc_long(return_value, "distribution_peer_count", (zend_long) metadata.distribution_peer_count);
}

PHP_FUNCTION(king_object_store_backup_object)
{
    zend_string *object_id;
    zend_string *destination_path;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(destination_path)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s",
                object_id_error
            );
            RETURN_THROWS();
        }
    }

    if (ZSTR_LEN(destination_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Destination path must be a non-empty string."
        );
        RETURN_THROWS();
    }

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_backup_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(destination_path)
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_restore_object)
{
    zend_string *object_id;
    zend_string *source_path;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(source_path)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s",
                object_id_error
            );
            RETURN_THROWS();
        }
    }

    if (ZSTR_LEN(source_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Source path must be a non-empty string."
        );
        RETURN_THROWS();
    }

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_restore_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(source_path)
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_backup_all_objects)
{
    zend_string *destination_path;
    zval *options = NULL;
    zval *mode_zv;
    zval *base_snapshot_path_zv;
    const char *mode = "full";
    const char *base_snapshot_path = NULL;
    zend_bool incremental = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(destination_path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(destination_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Destination path must be a non-empty string."
        );
        RETURN_THROWS();
    }
    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }
    if (options != NULL) {
        mode_zv = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "mode",
            sizeof("mode") - 1
        );
        if (mode_zv != NULL) {
            if (Z_TYPE_P(mode_zv) != IS_STRING || Z_STRLEN_P(mode_zv) == 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "king_object_store_backup_all_objects() option 'mode' must be a non-empty string when provided."
                );
                RETURN_THROWS();
            }

            mode = Z_STRVAL_P(mode_zv);
        }

        if (strcmp(mode, "full") == 0) {
            incremental = 0;
        } else if (strcmp(mode, "incremental") == 0) {
            incremental = 1;
        } else {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "king_object_store_backup_all_objects() option 'mode' must be either 'full' or 'incremental'."
            );
            RETURN_THROWS();
        }

        base_snapshot_path_zv = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "base_snapshot_path",
            sizeof("base_snapshot_path") - 1
        );
        if (base_snapshot_path_zv != NULL) {
            if (Z_TYPE_P(base_snapshot_path_zv) != IS_STRING || Z_STRLEN_P(base_snapshot_path_zv) == 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "king_object_store_backup_all_objects() option 'base_snapshot_path' must be a non-empty string when provided."
                );
                RETURN_THROWS();
            }

            base_snapshot_path = Z_STRVAL_P(base_snapshot_path_zv);
        }
    }
    if (incremental && base_snapshot_path == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_object_store_backup_all_objects() incremental mode requires option 'base_snapshot_path'."
        );
        RETURN_THROWS();
    }
    if (!incremental && base_snapshot_path != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_object_store_backup_all_objects() option 'base_snapshot_path' is only valid when mode='incremental'."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_backup_all_objects(
            ZSTR_VAL(destination_path),
            incremental,
            base_snapshot_path
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_restore_all_objects)
{
    zend_string *source_path;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(source_path)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(source_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Source path must be a non-empty string."
        );
        RETURN_THROWS();
    }
    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_restore_all_objects(ZSTR_VAL(source_path)) == SUCCESS
    );
}
