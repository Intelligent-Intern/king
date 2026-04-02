/*
 * S3 multipart-upload slice. Manages resumable upload-session state and the
 * provider-specific chunk/complete flow for the S3 adapter.
 */

static int king_object_store_s3_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    char *provider_token,
    size_t provider_token_size,
    char *error,
    size_t error_size
)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    smart_str url = {0};
    const char *upload_id_start;
    const char *upload_id_end;
    size_t upload_id_len;

    if (provider_token == NULL || provider_token_size == 0) {
        return FAILURE;
    }
    provider_token[0] = '\0';

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_s3_build_url(&config, object_id, 0, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_appends(&url, "?uploads");
    smart_str_0(&url);

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request_url(
            &config,
            "POST",
            ZSTR_VAL(url.s),
            "",
            0,
            NULL,
            0,
            metadata,
            NULL,
            0,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_free(&url);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_s3_format_response_error("POST", object_id, "multipart-init", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    upload_id_start = response.body.s == NULL ? NULL : strstr(ZSTR_VAL(response.body.s), "<UploadId>");
    if (upload_id_start == NULL) {
        snprintf(error, error_size, "cloud_s3 multipart init for '%s' did not return an upload id.", object_id);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    upload_id_start += sizeof("<UploadId>") - 1;
    upload_id_end = strstr(upload_id_start, "</UploadId>");
    if (upload_id_end == NULL || upload_id_end <= upload_id_start) {
        snprintf(error, error_size, "cloud_s3 multipart init for '%s' returned an invalid upload id.", object_id);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    upload_id_len = (size_t) (upload_id_end - upload_id_start);
    if (upload_id_len >= provider_token_size) {
        upload_id_len = provider_token_size - 1;
    }

    memcpy(provider_token, upload_id_start, upload_id_len);
    provider_token[upload_id_len] = '\0';
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_s3_append_upload_chunk(
    const char *object_id,
    const char *provider_token,
    uint32_t part_number,
    const char *source_path,
    uint64_t chunk_size,
    char *part_token,
    size_t part_token_size,
    char *error,
    size_t error_size
)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    smart_str url = {0};
    FILE *source_fp = NULL;

    if (part_token == NULL || part_token_size == 0) {
        return FAILURE;
    }
    part_token[0] = '\0';

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_s3_build_url(&config, object_id, 0, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_append_printf(&url, "?partNumber=%u&uploadId=%s", part_number, provider_token);
    smart_str_0(&url);

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        snprintf(error, error_size, "cloud_s3 could not open multipart chunk %u for '%s'.", part_number, object_id);
        smart_str_free(&url);
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request_url(
            &config,
            "PUT",
            ZSTR_VAL(url.s),
            NULL,
            0,
            source_fp,
            chunk_size,
            NULL,
            NULL,
            0,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        fclose(source_fp);
        king_object_store_http_response_destroy(&response);
        smart_str_free(&url);
        return FAILURE;
    }
    fclose(source_fp);
    smart_str_free(&url);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_s3_format_response_error("PUT", object_id, "multipart-part", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (!king_object_store_http_header_get(&response.headers, "ETag", part_token, part_token_size)) {
        snprintf(error, error_size, "cloud_s3 multipart part %u for '%s' did not return an ETag.", part_number, object_id);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_s3_complete_upload_session(
    const char *object_id,
    const char *provider_token,
    const HashTable *provider_parts,
    char *error,
    size_t error_size
)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    smart_str url = {0};
    smart_str body = {0};
    uint32_t part_count;
    uint32_t part_number;

    if (provider_parts == NULL) {
        return FAILURE;
    }

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_s3_build_url(&config, object_id, 0, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_append_printf(&url, "?uploadId=%s", provider_token);
    smart_str_0(&url);

    smart_str_appends(&body, "<CompleteMultipartUpload>");
    part_count = (uint32_t) zend_hash_num_elements((HashTable *) provider_parts);
    for (part_number = 1; part_number <= part_count; ++part_number) {
        zval *part_etag = zend_hash_index_find((HashTable *) provider_parts, part_number);

        if (part_etag == NULL || Z_TYPE_P(part_etag) != IS_STRING) {
            snprintf(error, error_size, "cloud_s3 multipart completion for '%s' is missing part %u.", object_id, part_number);
            smart_str_free(&body);
            smart_str_free(&url);
            return FAILURE;
        }

        smart_str_append_printf(
            &body,
            "<Part><PartNumber>%u</PartNumber><ETag>%s</ETag></Part>",
            part_number,
            Z_STRVAL_P(part_etag)
        );
    }
    smart_str_appends(&body, "</CompleteMultipartUpload>");
    smart_str_0(&body);

    {
        const char *extra_headers[] = {"Content-Type: application/xml"};

        king_object_store_http_response_init(&response);
        if (king_object_store_s3_http_request_url(
                &config,
                "POST",
                ZSTR_VAL(url.s),
                ZSTR_VAL(body.s),
                ZSTR_LEN(body.s),
                NULL,
                0,
                NULL,
                extra_headers,
                1,
                &response,
                error,
                error_size
            ) != SUCCESS) {
            king_object_store_http_response_destroy(&response);
            smart_str_free(&body);
            smart_str_free(&url);
            return FAILURE;
        }
    }
    smart_str_free(&body);
    smart_str_free(&url);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_s3_format_response_error("POST", object_id, "multipart-complete", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_s3_abort_upload_session(
    const char *object_id,
    const char *provider_token,
    char *error,
    size_t error_size
)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    smart_str url = {0};

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_s3_build_url(&config, object_id, 0, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_append_printf(&url, "?uploadId=%s", provider_token);
    smart_str_0(&url);

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request_url(
            &config,
            "DELETE",
            ZSTR_VAL(url.s),
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
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_free(&url);

    if (response.status_code != 404 && (response.status_code < 200 || response.status_code >= 300)) {
        king_object_store_s3_format_response_error("DELETE", object_id, "multipart-abort", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}
