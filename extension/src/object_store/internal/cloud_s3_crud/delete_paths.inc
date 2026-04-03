static int king_object_store_s3_delete_with_error(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool update_counters,
    zend_bool remove_sidecar,
    zend_bool allow_missing,
    zend_bool *removed_out)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;

    if (removed_out != NULL) {
        *removed_out = 0;
    }

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_s3_head_object(
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
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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

static int king_object_store_s3_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out)
{
    return king_object_store_s3_delete_with_error(
        object_id,
        error,
        error_size,
        0,
        0,
        1,
        removed_out
    );
}

static int king_object_store_s3_remove(const char *object_id)
{
    return king_object_store_s3_delete_with_error(
        object_id,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error),
        1,
        1,
        0,
        NULL
    );
}

