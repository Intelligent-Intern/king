static zend_bool king_object_store_http_header_get(
    const smart_str *headers,
    const char *name,
    char *destination,
    size_t destination_size)
{
    const char *cursor;
    size_t name_len;

    if (headers == NULL || headers->s == NULL || name == NULL || destination == NULL || destination_size == 0) {
        return 0;
    }

    cursor = ZSTR_VAL(headers->s);
    name_len = strlen(name);

    while (*cursor != '\0') {
        const char *line_end = strstr(cursor, "\r\n");
        const char *colon;
        size_t line_len;
        size_t value_len;

        if (line_end == NULL) {
            line_end = cursor + strlen(cursor);
        }

        line_len = (size_t) (line_end - cursor);
        colon = memchr(cursor, ':', line_len);
        if (colon != NULL) {
            size_t header_name_len = (size_t) (colon - cursor);
            if (header_name_len == name_len && strncasecmp(cursor, name, name_len) == 0) {
                const char *value_start = colon + 1;
                while (value_start < line_end && (*value_start == ' ' || *value_start == '\t')) {
                    value_start++;
                }
                value_len = (size_t) (line_end - value_start);
                if (value_len >= destination_size) {
                    value_len = destination_size - 1;
                }
                memcpy(destination, value_start, value_len);
                destination[value_len] = '\0';
                return 1;
            }
        }

        cursor = *line_end == '\0' ? line_end : line_end + 2;
    }

    return 0;
}

static time_t king_object_store_parse_rfc7231_time(const char *value)
{
    struct tm tm_value;
    char *parsed;

    if (value == NULL || value[0] == '\0') {
        return 0;
    }

    memset(&tm_value, 0, sizeof(tm_value));
    parsed = strptime(value, "%a, %d %b %Y %H:%M:%S GMT", &tm_value);
    if (parsed == NULL || *parsed != '\0') {
        return 0;
    }

    return timegm(&tm_value);
}

static time_t king_object_store_parse_iso8601_utc(const char *value)
{
    struct tm tm_value;
    char *parsed;

    if (value == NULL || value[0] == '\0') {
        return 0;
    }

    memset(&tm_value, 0, sizeof(tm_value));
    parsed = strptime(value, "%Y-%m-%dT%H:%M:%SZ", &tm_value);
    if (parsed == NULL || *parsed != '\0') {
        return 0;
    }

    return timegm(&tm_value);
}

static void king_object_store_s3_metadata_from_headers(
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

    if (king_object_store_http_header_get(headers, "x-amz-meta-object-id", value, sizeof(value))) {
        strncpy(metadata->object_id, value, sizeof(metadata->object_id) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-content-type", value, sizeof(value))) {
        strncpy(metadata->content_type, value, sizeof(metadata->content_type) - 1);
    } else if (king_object_store_http_header_get(headers, "Content-Type", value, sizeof(value))) {
        strncpy(metadata->content_type, value, sizeof(metadata->content_type) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-content-encoding", value, sizeof(value))) {
        strncpy(metadata->content_encoding, value, sizeof(metadata->content_encoding) - 1);
    } else if (king_object_store_http_header_get(headers, "Content-Encoding", value, sizeof(value))) {
        strncpy(metadata->content_encoding, value, sizeof(metadata->content_encoding) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-etag", value, sizeof(value))) {
        strncpy(metadata->etag, value, sizeof(metadata->etag) - 1);
    } else if (king_object_store_http_header_get(headers, "ETag", value, sizeof(value))) {
        strncpy(metadata->etag, value, sizeof(metadata->etag) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-integrity-sha256", value, sizeof(value))) {
        strncpy(metadata->integrity_sha256, value, sizeof(metadata->integrity_sha256) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-content-length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    } else if (king_object_store_http_header_get(headers, "Content-Length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-version", value, sizeof(value))) {
        metadata->version = (uint64_t) strtoull(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-created-at", value, sizeof(value))) {
        metadata->created_at = (time_t) strtoll(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-modified-at", value, sizeof(value))) {
        metadata->modified_at = (time_t) strtoll(value, NULL, 10);
    }
    if (metadata->modified_at == 0 && king_object_store_http_header_get(headers, "Last-Modified", value, sizeof(value))) {
        metadata->modified_at = king_object_store_parse_rfc7231_time(value);
    }
    if (metadata->created_at == 0) {
        metadata->created_at = metadata->modified_at;
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-expires-at", value, sizeof(value))) {
        metadata->expires_at = (time_t) strtoll(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-object-type", value, sizeof(value))) {
        metadata->object_type = (king_object_type_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-cache-policy", value, sizeof(value))) {
        metadata->cache_policy = (king_cache_policy_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-cache-ttl-seconds", value, sizeof(value))) {
        metadata->cache_ttl_seconds = (uint32_t) strtoul(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-is-backed-up", value, sizeof(value))) {
        metadata->is_backed_up = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-replication-status", value, sizeof(value))) {
        metadata->replication_status = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-is-distributed", value, sizeof(value))) {
        metadata->is_distributed = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-distribution-peer-count", value, sizeof(value))) {
        metadata->distribution_peer_count = (uint32_t) strtoul(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-local-fs-present", value, sizeof(value))) {
        metadata->local_fs_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-distributed-present", value, sizeof(value))) {
        metadata->distributed_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-cloud-s3-present", value, sizeof(value))) {
        metadata->cloud_s3_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-cloud-gcs-present", value, sizeof(value))) {
        metadata->cloud_gcs_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-amz-meta-cloud-azure-present", value, sizeof(value))) {
        metadata->cloud_azure_present = (uint8_t) atoi(value);
    }
}

static zend_result king_object_store_s3_head_object(
    const char *object_id,
    zend_bool *exists_out,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;

    if (exists_out != NULL) {
        *exists_out = 0;
    }

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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
        king_object_store_s3_metadata_from_headers(&response.headers, object_id, metadata);
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static zend_result king_object_store_s3_http_request_url(
    const king_object_store_s3_runtime_config_t *config,
    const char *method,
    const char *url,
    const void *body,
    size_t body_size,
    FILE *body_stream,
    uint64_t body_stream_size,
    const king_object_metadata_t *request_metadata,
    const char * const *extra_headers,
    size_t extra_header_count,
    king_object_store_http_response_t *response,
    char *error,
    size_t error_size
)
{
    CURL *easy = NULL;
    struct curl_slist *headers = NULL;
    char auth_mode[128];
    char userpwd[512];
    size_t i;
    CURLcode curl_code;

    if (config == NULL || method == NULL || url == NULL || response == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_ensure_libcurl_ready() != SUCCESS) {
        snprintf(error, error_size, "%s", king_object_store_libcurl.load_error);
        return FAILURE;
    }

    easy = king_object_store_libcurl.curl_easy_init_fn();
    if (easy == NULL) {
        snprintf(error, error_size, "Failed to create libcurl handle for cloud_s3 operation.");
        return FAILURE;
    }

    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Accept: */*");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Expect:");
    if (config->session_token[0] != '\0') {
        char token_header[512];

        snprintf(token_header, sizeof(token_header), "x-amz-security-token: %s", config->session_token);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, token_header);
    }

    if (request_metadata != NULL || strcmp(method, "PUT") == 0) {
        if (request_metadata != NULL && request_metadata->content_type[0] != '\0') {
            int header_result = king_object_store_append_named_http_header(
                &headers,
                "Content-Type",
                "content_type",
                request_metadata->content_type,
                error,
                error_size
            );

            if (header_result != SUCCESS) {
                king_object_store_libcurl.curl_slist_free_all_fn(headers);
                king_object_store_libcurl.curl_easy_cleanup_fn(easy);
                return header_result;
            }
        } else {
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Content-Type: application/octet-stream");
        }
    }
    if (request_metadata != NULL && request_metadata->content_encoding[0] != '\0') {
        int header_result = king_object_store_append_named_http_header(
            &headers,
            "Content-Encoding",
            "content_encoding",
            request_metadata->content_encoding,
            error,
            error_size
        );

        if (header_result != SUCCESS) {
            king_object_store_libcurl.curl_slist_free_all_fn(headers);
            king_object_store_libcurl.curl_easy_cleanup_fn(easy);
            return header_result;
        }
    }
    if (request_metadata != NULL) {
        int header_result = king_object_store_append_metadata_headers(
            &headers,
            "x-amz-meta-",
            request_metadata,
            error,
            error_size
        );

        if (header_result != SUCCESS) {
            king_object_store_libcurl.curl_slist_free_all_fn(headers);
            king_object_store_libcurl.curl_easy_cleanup_fn(easy);
            return header_result;
        }
    }
    for (i = 0; i < extra_header_count; ++i) {
        if (extra_headers[i] != NULL && extra_headers[i][0] != '\0') {
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, extra_headers[i]);
        }
    }

    snprintf(auth_mode, sizeof(auth_mode), "aws:amz:%s:s3", config->region);
    snprintf(userpwd, sizeof(userpwd), "%s:%s", config->access_key, config->secret_key);

    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_URL, url);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_NOSIGNAL, 1L);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_TIMEOUT_MS, KING_OBJECT_STORE_HTTP_TIMEOUT_MS);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_USERAGENT, "king-object-store/1");
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPHEADER, headers);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEFUNCTION, king_object_store_http_write_callback);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEDATA, response);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HEADERFUNCTION, king_object_store_http_header_callback);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HEADERDATA, response);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_SSL_VERIFYPEER, config->verify_tls ? 1L : 0L);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_SSL_VERIFYHOST, config->verify_tls ? 2L : 0L);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_USERPWD, userpwd);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_AWS_SIGV4, auth_mode);
#ifdef CURLAUTH_AWS_SIGV4
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPAUTH, (long) CURLAUTH_AWS_SIGV4);
#endif

    if (strcmp(method, "DELETE") == 0) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_CUSTOMREQUEST, "DELETE");
    } else if (strcmp(method, "PUT") == 0) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_CUSTOMREQUEST, "PUT");
        if (body_stream != NULL) {
            rewind(body_stream);
            king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_UPLOAD, 1L);
            king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_READDATA, body_stream);
            king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_INFILESIZE_LARGE, (curl_off_t) body_stream_size);
        } else {
            king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_POSTFIELDS, body_size > 0 ? body : "");
            king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_POSTFIELDSIZE, (long) body_size);
        }
    } else if (strcmp(method, "POST") == 0) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_POST, 1L);
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_POSTFIELDS, body_size > 0 ? body : "");
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_POSTFIELDSIZE, (long) body_size);
    } else {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPGET, 1L);
    }

    curl_code = king_object_store_libcurl.curl_easy_perform_fn(easy);
    if (curl_code != CURLE_OK) {
        if (response->body_limit_exceeded) {
            snprintf(error, error_size, "cloud_s3 response body exceeded the object-store size guard.");
        } else if (response->header_limit_exceeded) {
            snprintf(error, error_size, "cloud_s3 response headers exceeded the object-store size guard.");
        } else {
            king_object_store_s3_format_transport_error(method, curl_code, error, error_size);
        }
        king_object_store_libcurl.curl_slist_free_all_fn(headers);
        king_object_store_libcurl.curl_easy_cleanup_fn(easy);
        return FAILURE;
    }

    king_object_store_libcurl.curl_easy_getinfo_fn(easy, CURLINFO_RESPONSE_CODE, &response->status_code);
    smart_str_0(&response->body);
    smart_str_0(&response->headers);

    king_object_store_libcurl.curl_slist_free_all_fn(headers);
    king_object_store_libcurl.curl_easy_cleanup_fn(easy);
    return SUCCESS;
}
