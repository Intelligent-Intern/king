/*
 * GCS multipart-upload slice. Manages resumable upload-session state and the
 * provider-specific chunk/finalize flow for the GCS adapter.
 */

static int king_object_store_gcs_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    char *provider_token,
    size_t provider_token_size,
    char *error,
    size_t error_size
)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;
    smart_str url = {0};
    const char *location_header;

    if (provider_token == NULL || provider_token_size == 0) {
        return FAILURE;
    }
    provider_token[0] = '\0';

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_gcs_build_url(&config, object_id, 0, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_appends(&url, "?uploadType=resumable");
    smart_str_0(&url);

    {
        const char *extra_headers[] = {"x-goog-resumable: start"};

        king_object_store_http_response_init(&response);
        if (king_object_store_gcs_http_request_url(
                &config,
                "POST",
                ZSTR_VAL(url.s),
                "",
                0,
                NULL,
                0,
                metadata,
                extra_headers,
                1,
                &response,
                error,
                error_size
            ) != SUCCESS) {
            king_object_store_http_response_destroy(&response);
            smart_str_free(&url);
            return FAILURE;
        }
    }
    smart_str_free(&url);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_gcs_format_response_error("POST", object_id, "resumable-init", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    location_header = NULL;
    if (response.headers.s != NULL) {
        static char location[1024];

        if (king_object_store_http_header_get(&response.headers, "Location", location, sizeof(location))) {
            location_header = location;
        }
    }
    if (location_header == NULL || location_header[0] == '\0') {
        snprintf(error, error_size, "cloud_gcs resumable init for '%s' did not return a session URL.", object_id);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    snprintf(provider_token, provider_token_size, "%s", location_header);
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_gcs_append_upload_chunk(
    const char *provider_token,
    const char *source_path,
    uint64_t chunk_size,
    uint64_t offset,
    zend_bool is_final_chunk,
    uint64_t final_size,
    zend_bool *remote_completed_out,
    char *error,
    size_t error_size
)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;
    FILE *source_fp = NULL;
    char content_range_header[128];
    const char *extra_headers[1];

    if (remote_completed_out != NULL) {
        *remote_completed_out = 0;
    }

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    if (is_final_chunk) {
        snprintf(
            content_range_header,
            sizeof(content_range_header),
            "Content-Range: bytes %" PRIu64 "-%" PRIu64 "/%" PRIu64,
            offset,
            offset + chunk_size - 1,
            final_size
        );
    } else {
        snprintf(
            content_range_header,
            sizeof(content_range_header),
            "Content-Range: bytes %" PRIu64 "-%" PRIu64 "/*",
            offset,
            offset + chunk_size - 1
        );
    }

    extra_headers[0] = content_range_header;

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        snprintf(error, error_size, "cloud_gcs could not open the resumable upload chunk.");
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request_url(
            &config,
            "PUT",
            provider_token,
            NULL,
            0,
            source_fp,
            chunk_size,
            NULL,
            extra_headers,
            1,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        fclose(source_fp);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }
    fclose(source_fp);

    if (!is_final_chunk && response.status_code == 308) {
        king_object_store_http_response_destroy(&response);
        return SUCCESS;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_gcs_format_response_error("PUT", NULL, is_final_chunk ? "resumable-complete" : "resumable-chunk", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (remote_completed_out != NULL) {
        *remote_completed_out = 1;
    }
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_gcs_abort_upload_session(
    const char *provider_token,
    char *error,
    size_t error_size
)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request_url(
            &config,
            "DELETE",
            provider_token,
            NULL,
            0,
            NULL,
            0,
            NULL,
            NULL,
            0,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code != 404 && (response.status_code < 200 || response.status_code >= 300)) {
        king_object_store_gcs_format_response_error("DELETE", NULL, "resumable-abort", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}
