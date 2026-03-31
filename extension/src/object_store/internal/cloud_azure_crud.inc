static int king_object_store_azure_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    king_object_metadata_t final_metadata;
    const king_object_metadata_t *metadata_source = metadata;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;
    uint64_t old_size = 0;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata_source != NULL) {
        final_metadata = *metadata_source;
    }
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_CLOUD_AZURE,
        1
    );

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_azure_head_object(
            object_id,
            &exists,
            &existing_metadata,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (exists) {
        old_size = existing_metadata.content_length;
    }
    king_object_store_prepare_metadata_for_write(
        object_id,
        data,
        data_size,
        &final_metadata,
        exists ? &existing_metadata : NULL
    );

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "PUT",
            object_id,
            0,
            data,
            data_size,
            NULL,
            0,
            &final_metadata,
            NULL,
            &response,
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "PUT",
            object_id,
            "write",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "cloud_azure write for '%s' succeeded but local metadata sidecar write failed.",
            object_id
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (update_counters) {
        king_object_store_apply_local_fs_counters_for_rewrite(object_id, data_size, exists ? 1 : 0, old_size);
    }
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    king_object_metadata_t final_metadata;
    const king_object_metadata_t *metadata_source = metadata;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;
    uint64_t old_size = 0;
    uint64_t source_size = 0;
    char computed_sha256[65];
    FILE *source_fp = NULL;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_compute_sha256_hex_for_path(source_path, computed_sha256, &source_size) != SUCCESS) {
        snprintf(error, error_size, "cloud_azure could not hash staged upload content for '%s'.", object_id);
        return FAILURE;
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata_source != NULL) {
        final_metadata = *metadata_source;
    }
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_CLOUD_AZURE,
        1
    );

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_azure_head_object(
            object_id,
            &exists,
            &existing_metadata,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (exists) {
        old_size = existing_metadata.content_length;
    }
    king_object_store_finalize_metadata_for_write(
        object_id,
        source_size,
        computed_sha256,
        &final_metadata,
        exists ? &existing_metadata : NULL
    );

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        snprintf(error, error_size, "cloud_azure could not open the staged upload for '%s'.", object_id);
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "PUT",
            object_id,
            0,
            NULL,
            0,
            source_fp,
            source_size,
            &final_metadata,
            NULL,
            &response,
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        fclose(source_fp);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }
    fclose(source_fp);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "PUT",
            object_id,
            "write",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "cloud_azure write for '%s' succeeded but local metadata sidecar write failed.",
            object_id
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (update_counters) {
        king_object_store_apply_local_fs_counters_for_rewrite(object_id, source_size, exists ? 1 : 0, old_size);
    }
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_read_with_error(
    const char *object_id,
    void **data,
    size_t *data_size,
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *error,
    size_t error_size)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    void *copy;
    char range_header[96];

    if (data == NULL || data_size == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "GET",
            object_id,
            0,
            NULL,
            0,
            NULL,
            0,
            NULL,
            (offset > 0 || has_length)
                ? (king_object_store_build_range_header(offset, length, has_length, range_header, sizeof(range_header)), range_header)
                : NULL,
            &response,
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code == 404) {
        error[0] = '\0';
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    if ((offset > 0 || has_length) && response.status_code == 416) {
        snprintf(
            error,
            error_size,
            "cloud_azure range read for '%s' requested range starts past the end of the object.",
            object_id
        );
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (response.status_code < 200 || response.status_code >= 300 || ((offset > 0 || has_length) && response.status_code != 206)) {
        king_object_store_azure_format_response_error(
            "GET",
            object_id,
            "read",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    copy = pemalloc(response.body_bytes + 1, 1);
    if (copy == NULL) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.body_bytes > 0 && response.body.s != NULL) {
        memcpy(copy, ZSTR_VAL(response.body.s), response.body_bytes);
    }
    ((char *) copy)[response.body_bytes] = '\0';

    *data = copy;
    *data_size = response.body_bytes;
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_azure_read_with_error(
        object_id,
        data,
        data_size,
        0,
        0,
        0,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error)
    );
}

static int king_object_store_azure_read_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
)
{
    int rc;

    rc = king_object_store_azure_read_with_error(
        object_id,
        data,
        data_size,
        offset,
        length,
        has_length,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error)
    );
    if (rc != SUCCESS) {
        return rc;
    }

    if (metadata != NULL && king_object_store_backend_read_metadata(object_id, metadata) != SUCCESS) {
        king_object_store_release_read_buffer(data, data_size);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_azure_read_to_path_with_error(
    const char *object_id,
    const char *destination_path,
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *error,
    size_t error_size
)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    char range_header[96];
    FILE *destination_fp = NULL;

    if (destination_path == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    destination_fp = fopen(destination_path, "w+b");
    if (destination_fp == NULL) {
        snprintf(error, error_size, "cloud_azure could not open the bounded-memory download sink for '%s'.", object_id);
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "GET",
            object_id,
            0,
            NULL,
            0,
            NULL,
            0,
            NULL,
            (offset > 0 || has_length)
                ? (king_object_store_build_range_header(offset, length, has_length, range_header, sizeof(range_header)), range_header)
                : NULL,
            &response,
            error,
            error_size,
            destination_fp
        ) != SUCCESS) {
        fclose(destination_fp);
        unlink(destination_path);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }
    fclose(destination_fp);

    if (response.status_code == 404) {
        error[0] = '\0';
        unlink(destination_path);
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    if ((offset > 0 || has_length) && response.status_code == 416) {
        snprintf(
            error,
            error_size,
            "cloud_azure range read for '%s' requested range starts past the end of the object.",
            object_id
        );
        unlink(destination_path);
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (response.status_code < 200 || response.status_code >= 300 || ((offset > 0 || has_length) && response.status_code != 206)) {
        king_object_store_azure_format_response_error(
            "GET",
            object_id,
            "read",
            &response,
            error,
            error_size
        );
        unlink(destination_path);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_delete_with_error(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool update_counters,
    zend_bool remove_sidecar,
    zend_bool allow_missing,
    zend_bool *removed_out)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;

    if (removed_out != NULL) {
        *removed_out = 0;
    }

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_azure_head_object(
            object_id,
            &exists,
            &existing_metadata,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (!exists) {
        if (error != NULL && error_size > 0) {
            error[0] = '\0';
        }
        return allow_missing ? SUCCESS : KING_OBJECT_STORE_RESULT_MISS;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "DELETE",
            object_id,
            0,
            NULL,
            0,
            NULL,
            0,
            NULL,
            NULL,
            &response,
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "DELETE",
            object_id,
            "delete",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (removed_out != NULL) {
        *removed_out = 1;
    }

    if (update_counters) {
        if (king_object_store_runtime.current_stored_bytes >= existing_metadata.content_length) {
            king_object_store_runtime.current_stored_bytes -= existing_metadata.content_length;
        }
        if (king_object_store_runtime.current_object_count > 0) {
            king_object_store_runtime.current_object_count--;
        }
    }

    if (remove_sidecar) {
        king_object_store_meta_remove(object_id);
    }
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out)
{
    return king_object_store_azure_delete_with_error(
        object_id,
        error,
        error_size,
        0,
        0,
        1,
        removed_out
    );
}

static int king_object_store_azure_remove(const char *object_id)
{
    return king_object_store_azure_delete_with_error(
        object_id,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error),
        1,
        1,
        0,
        NULL
    );
}

static zend_result king_object_store_azure_parse_list_xml(
    const char *xml,
    zval *return_array,
    uint64_t *object_count_out,
    uint64_t *stored_bytes_out,
    time_t *latest_object_at_out)
{
    const char *cursor;

    if (xml == NULL) {
        return FAILURE;
    }

    cursor = xml;
    while ((cursor = strstr(cursor, "<Blob>")) != NULL) {
        const char *entry_end = strstr(cursor, "</Blob>");
        const char *name_start;
        const char *name_end;
        const char *size_start;
        const char *size_end;
        const char *modified_start;
        const char *modified_end;
        char key[256];
        char size_value[64];
        char modified_value[64];
        uint64_t size_bytes;
        time_t stored_at = 0;

        if (entry_end == NULL) {
            return FAILURE;
        }

        name_start = strstr(cursor, "<Name>");
        name_end = name_start != NULL ? strstr(name_start, "</Name>") : NULL;
        size_start = strstr(cursor, "<Content-Length>");
        size_end = size_start != NULL ? strstr(size_start, "</Content-Length>") : NULL;
        modified_start = strstr(cursor, "<Last-Modified>");
        modified_end = modified_start != NULL ? strstr(modified_start, "</Last-Modified>") : NULL;

        if (name_start == NULL || name_end == NULL || size_start == NULL || size_end == NULL) {
            return FAILURE;
        }

        name_start += strlen("<Name>");
        size_start += strlen("<Content-Length>");
        if (modified_start != NULL) {
            modified_start += strlen("<Last-Modified>");
        }

        if ((size_t) (name_end - name_start) >= sizeof(key) || (size_t) (size_end - size_start) >= sizeof(size_value)) {
            return FAILURE;
        }

        memcpy(key, name_start, (size_t) (name_end - name_start));
        key[name_end - name_start] = '\0';
        memcpy(size_value, size_start, (size_t) (size_end - size_start));
        size_value[size_end - size_start] = '\0';

        if (modified_start != NULL && modified_end != NULL && modified_end > modified_start) {
            size_t modified_len = (size_t) (modified_end - modified_start);
            if (modified_len >= sizeof(modified_value)) {
                return FAILURE;
            }
            memcpy(modified_value, modified_start, modified_len);
            modified_value[modified_len] = '\0';
            stored_at = king_object_store_parse_rfc7231_time(modified_value);
            if (stored_at == 0) {
                stored_at = king_object_store_parse_iso8601_utc(modified_value);
            }
        }

        size_bytes = (uint64_t) strtoull(size_value, NULL, 10);

        if (return_array != NULL) {
            zval exported_entry;
            king_object_metadata_t metadata;
            king_object_metadata_t *metadata_ptr = NULL;

            if (king_object_store_backend_read_metadata(key, &metadata) == SUCCESS) {
                if (king_object_store_metadata_is_expired_now(&metadata)) {
                    cursor = entry_end + strlen("</Blob>");
                    continue;
                }
                metadata_ptr = &metadata;
            }

            king_object_store_list_entry_from_metadata(
                &exported_entry,
                key,
                metadata_ptr,
                size_bytes,
                stored_at
            );
            add_next_index_zval(return_array, &exported_entry);
        }

        if (object_count_out != NULL) {
            (*object_count_out)++;
        }
        if (stored_bytes_out != NULL) {
            (*stored_bytes_out) += size_bytes;
        }
        if (latest_object_at_out != NULL && stored_at > *latest_object_at_out) {
            *latest_object_at_out = stored_at;
        }

        cursor = entry_end + strlen("</Blob>");
    }

    return SUCCESS;
}

static int king_object_store_azure_list(zval *return_array)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;

    if (king_object_store_azure_resolve_config(&config, king_object_store_runtime.primary_adapter_error, sizeof(king_object_store_runtime.primary_adapter_error)) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "GET",
            NULL,
            1,
            NULL,
            0,
            NULL,
            0,
            NULL,
            NULL,
            &response,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            NULL
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "GET",
            NULL,
            "list",
            &response,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.body.s == NULL) {
        king_object_store_http_response_destroy(&response);
        return SUCCESS;
    }

    if (king_object_store_azure_parse_list_xml(
            ZSTR_VAL(response.body.s),
            return_array,
            NULL,
            NULL,
            NULL
        ) != SUCCESS) {
        snprintf(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "Failed to parse cloud_azure inventory XML."
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_rehydrate_stats(char *error, size_t error_size)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    uint64_t object_count = 0;
    uint64_t stored_bytes = 0;
    time_t latest_object_at = 0;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "GET",
            NULL,
            1,
            NULL,
            0,
            NULL,
            0,
            NULL,
            NULL,
            &response,
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "GET",
            NULL,
            "inventory rehydrate",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.body.s != NULL && king_object_store_azure_parse_list_xml(
            ZSTR_VAL(response.body.s),
            NULL,
            &object_count,
            &stored_bytes,
            &latest_object_at
        ) != SUCCESS) {
        snprintf(error, error_size, "Failed to parse cloud_azure inventory XML during rehydrate.");
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_runtime.current_object_count = object_count;
    king_object_store_runtime.current_stored_bytes = stored_bytes;
    king_object_store_runtime.latest_object_at = latest_object_at;

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}
