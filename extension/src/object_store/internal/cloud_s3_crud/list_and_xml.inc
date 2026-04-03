static zend_result king_object_store_s3_parse_list_xml(
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
    while ((cursor = strstr(cursor, "<Contents>")) != NULL) {
        const char *entry_end = strstr(cursor, "</Contents>");
        const char *key_start;
        const char *key_end;
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

        key_start = strstr(cursor, "<Key>");
        key_end = key_start != NULL ? strstr(key_start, "</Key>") : NULL;
        size_start = strstr(cursor, "<Size>");
        size_end = size_start != NULL ? strstr(size_start, "</Size>") : NULL;
        modified_start = strstr(cursor, "<LastModified>");
        modified_end = modified_start != NULL ? strstr(modified_start, "</LastModified>") : NULL;

        if (key_start == NULL || key_end == NULL || size_start == NULL || size_end == NULL) {
            return FAILURE;
        }

        key_start += strlen("<Key>");
        size_start += strlen("<Size>");
        if (modified_start != NULL) {
            modified_start += strlen("<LastModified>");
        }

        if ((size_t) (key_end - key_start) >= sizeof(key) || (size_t) (size_end - size_start) >= sizeof(size_value)) {
            return FAILURE;
        }

        memcpy(key, key_start, (size_t) (key_end - key_start));
        key[key_end - key_start] = '\0';
        memcpy(size_value, size_start, (size_t) (size_end - size_start));
        size_value[size_end - size_start] = '\0';

        if (modified_start != NULL && modified_end != NULL && modified_end > modified_start) {
            size_t modified_len = (size_t) (modified_end - modified_start);
            if (modified_len >= sizeof(modified_value)) {
                return FAILURE;
            }
            memcpy(modified_value, modified_start, modified_len);
            modified_value[modified_len] = '\0';
            stored_at = king_object_store_parse_iso8601_utc(modified_value);
        }

        size_bytes = (uint64_t) strtoull(size_value, NULL, 10);

        if (return_array != NULL) {
            zval exported_entry;
            king_object_metadata_t metadata;
            king_object_metadata_t *metadata_ptr = NULL;

            if (king_object_store_backend_read_metadata(key, &metadata) == SUCCESS) {
                if (king_object_store_metadata_is_expired_now(&metadata)) {
                    cursor = entry_end + strlen("</Contents>");
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

        cursor = entry_end + strlen("</Contents>");
    }

    return SUCCESS;
}

static int king_object_store_s3_list(zval *return_array)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;

    if (king_object_store_s3_resolve_config(&config, king_object_store_runtime.primary_adapter_error, sizeof(king_object_store_runtime.primary_adapter_error)) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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

    if (king_object_store_s3_parse_list_xml(
            ZSTR_VAL(response.body.s),
            return_array,
            NULL,
            NULL,
            NULL
        ) != SUCCESS) {
        snprintf(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "Failed to parse cloud_s3 inventory XML."
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

