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

