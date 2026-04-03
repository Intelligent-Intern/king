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

