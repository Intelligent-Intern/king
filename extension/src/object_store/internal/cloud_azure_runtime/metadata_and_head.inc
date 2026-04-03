static void king_object_store_azure_metadata_from_headers(
    const smart_str *headers,
    const char *object_id,
    king_object_metadata_t *metadata)
{
    char value[512];

    if (metadata == NULL) {
        return;
    }

    memset(metadata, 0, sizeof(*metadata));
    if (object_id != NULL) {
        strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
    }

    if (king_object_store_http_header_get(headers, "x-ms-meta-object-id", value, sizeof(value))) {
        strncpy(metadata->object_id, value, sizeof(metadata->object_id) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-content-type", value, sizeof(value))) {
        strncpy(metadata->content_type, value, sizeof(metadata->content_type) - 1);
    } else if (king_object_store_http_header_get(headers, "Content-Type", value, sizeof(value))) {
        strncpy(metadata->content_type, value, sizeof(metadata->content_type) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-content-encoding", value, sizeof(value))) {
        strncpy(metadata->content_encoding, value, sizeof(metadata->content_encoding) - 1);
    } else if (king_object_store_http_header_get(headers, "Content-Encoding", value, sizeof(value))) {
        strncpy(metadata->content_encoding, value, sizeof(metadata->content_encoding) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-content-length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    } else if (king_object_store_http_header_get(headers, "Content-Length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    } else if (king_object_store_http_header_get(headers, "x-ms-blob-content-length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-etag", value, sizeof(value))) {
        strncpy(metadata->etag, value, sizeof(metadata->etag) - 1);
    } else if (king_object_store_http_header_get(headers, "ETag", value, sizeof(value))) {
        strncpy(metadata->etag, value, sizeof(metadata->etag) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-integrity-sha256", value, sizeof(value))) {
        strncpy(metadata->integrity_sha256, value, sizeof(metadata->integrity_sha256) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-version", value, sizeof(value))) {
        metadata->version = (uint64_t) strtoull(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-created-at", value, sizeof(value))) {
        metadata->created_at = (time_t) strtoll(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-modified-at", value, sizeof(value))) {
        metadata->modified_at = (time_t) strtoll(value, NULL, 10);
    }
    if (metadata->modified_at == 0 && king_object_store_http_header_get(headers, "Last-Modified", value, sizeof(value))) {
        metadata->modified_at = king_object_store_parse_rfc7231_time(value);
    }
    if (metadata->created_at == 0) {
        metadata->created_at = metadata->modified_at;
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-expires-at", value, sizeof(value))) {
        metadata->expires_at = (time_t) strtoll(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-object-type", value, sizeof(value))) {
        metadata->object_type = (king_object_type_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-cache-policy", value, sizeof(value))) {
        metadata->cache_policy = (king_cache_policy_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-cache-ttl-seconds", value, sizeof(value))) {
        metadata->cache_ttl_seconds = (uint32_t) strtoul(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-is-backed-up", value, sizeof(value))) {
        metadata->is_backed_up = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-replication-status", value, sizeof(value))) {
        metadata->replication_status = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-is-distributed", value, sizeof(value))) {
        metadata->is_distributed = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-distribution-peer-count", value, sizeof(value))) {
        metadata->distribution_peer_count = (uint32_t) strtoul(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-local-fs-present", value, sizeof(value))) {
        metadata->local_fs_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-distributed-present", value, sizeof(value))) {
        metadata->distributed_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-cloud-s3-present", value, sizeof(value))) {
        metadata->cloud_s3_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-cloud-gcs-present", value, sizeof(value))) {
        metadata->cloud_gcs_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-ms-meta-cloud-azure-present", value, sizeof(value))) {
        metadata->cloud_azure_present = (uint8_t) atoi(value);
    }
}

static zend_result king_object_store_azure_head_object(
    const char *object_id,
    zend_bool *exists_out,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;

    if (exists_out != NULL) {
        *exists_out = 0;
    }

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "HEAD",
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

    if (response.status_code == 404) {
        king_object_store_http_response_destroy(&response);
        return SUCCESS;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "HEAD",
            object_id,
            "metadata HEAD",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (exists_out != NULL) {
        *exists_out = 1;
    }
    if (metadata != NULL) {
        king_object_store_azure_metadata_from_headers(&response.headers, object_id, metadata);
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

