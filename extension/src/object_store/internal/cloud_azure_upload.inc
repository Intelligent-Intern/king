static int king_object_store_azure_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    char *provider_token,
    size_t provider_token_size,
    char *error,
    size_t error_size
)
{
    king_object_store_azure_runtime_config_t config;

    (void) object_id;
    (void) metadata;

    if (provider_token != NULL && provider_token_size > 0) {
        provider_token[0] = '\0';
    }

    return king_object_store_azure_resolve_config(&config, error, error_size);
}

static int king_object_store_azure_append_upload_chunk(
    const char *object_id,
    uint32_t part_number,
    const char *source_path,
    uint64_t chunk_size,
    char *part_token,
    size_t part_token_size,
    char *error,
    size_t error_size
)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    smart_str url = {0};
    FILE *source_fp = NULL;
    zend_string *block_id = NULL;
    char part_name[32];

    if (part_token == NULL || part_token_size == 0) {
        return FAILURE;
    }
    part_token[0] = '\0';

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_azure_build_url(&config, object_id, 0, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }

    snprintf(part_name, sizeof(part_name), "%06u", part_number);
    block_id = php_base64_encode((const unsigned char *) part_name, strlen(part_name));
    if (block_id == NULL) {
        smart_str_free(&url);
        snprintf(error, error_size, "cloud_azure could not encode block id for '%s'.", object_id);
        return FAILURE;
    }

    smart_str_append_printf(&url, "?comp=block&blockid=%s", ZSTR_VAL(block_id));
    smart_str_0(&url);

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        zend_string_release(block_id);
        smart_str_free(&url);
        snprintf(error, error_size, "cloud_azure could not open block %u for '%s'.", part_number, object_id);
        return FAILURE;
    }

    {
        const char *extra_headers[] = {"x-ms-blob-type: BlockBlob"};

        king_object_store_http_response_init(&response);
        if (king_object_store_azure_http_request_url(
                &config,
                "PUT",
                ZSTR_VAL(url.s),
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
            zend_string_release(block_id);
            king_object_store_http_response_destroy(&response);
            smart_str_free(&url);
            return FAILURE;
        }
    }
    fclose(source_fp);
    smart_str_free(&url);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error("PUT", object_id, "block-stage", &response, error, error_size);
        zend_string_release(block_id);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    snprintf(part_token, part_token_size, "%s", ZSTR_VAL(block_id));
    zend_string_release(block_id);
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_complete_upload_session(
    const char *object_id,
    const HashTable *provider_parts,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    smart_str url = {0};
    smart_str body = {0};
    uint32_t part_count;
    uint32_t part_number;

    if (provider_parts == NULL) {
        return FAILURE;
    }

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_azure_build_url(&config, object_id, 0, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }
    smart_str_appends(&url, "?comp=blocklist");
    smart_str_0(&url);

    smart_str_appends(&body, "<?xml version=\"1.0\" encoding=\"utf-8\"?><BlockList>");
    part_count = (uint32_t) zend_hash_num_elements((HashTable *) provider_parts);
    for (part_number = 1; part_number <= part_count; ++part_number) {
        zval *block_id = zend_hash_index_find((HashTable *) provider_parts, part_number);

        if (block_id == NULL || Z_TYPE_P(block_id) != IS_STRING) {
            snprintf(error, error_size, "cloud_azure block-list completion for '%s' is missing block %u.", object_id, part_number);
            smart_str_free(&body);
            smart_str_free(&url);
            return FAILURE;
        }

        smart_str_append_printf(&body, "<Latest>%s</Latest>", Z_STRVAL_P(block_id));
    }
    smart_str_appends(&body, "</BlockList>");
    smart_str_0(&body);

    {
        const char *extra_headers[] = {"Content-Type: application/xml"};

        king_object_store_http_response_init(&response);
        if (king_object_store_azure_http_request_url(
                &config,
                "PUT",
                ZSTR_VAL(url.s),
                ZSTR_VAL(body.s),
                ZSTR_LEN(body.s),
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
            smart_str_free(&body);
            smart_str_free(&url);
            return FAILURE;
        }
    }
    smart_str_free(&body);
    smart_str_free(&url);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error("PUT", object_id, "blocklist-complete", &response, error, error_size);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

