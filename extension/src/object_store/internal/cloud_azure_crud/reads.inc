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

