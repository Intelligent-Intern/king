typedef struct _king_object_store_gcs_runtime_config_t {
    char endpoint[512];
    char bucket[256];
    char access_token[1024];
    zend_bool path_style;
    zend_bool verify_tls;
} king_object_store_gcs_runtime_config_t;

static zend_result king_object_store_gcs_resolve_config(
    king_object_store_gcs_runtime_config_t *config,
    char *error,
    size_t error_size)
{
    zval *value;
    size_t endpoint_len;

    if (config == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    memset(config, 0, sizeof(*config));
    error[0] = '\0';

    if (Z_TYPE(king_object_store_runtime.config.cloud_credentials) != IS_ARRAY) {
        snprintf(error, error_size, "cloud_credentials must be configured for cloud_gcs.");
        return FAILURE;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        snprintf(error, error_size, "storage_root_path is required for cloud_gcs metadata sidecars.");
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("api_endpoint");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("endpoint");
    }
    if (value == NULL) {
        snprintf(config->endpoint, sizeof(config->endpoint), "%s", "https://storage.googleapis.com");
    } else if (king_object_store_copy_cloud_string(
            value,
            "api_endpoint",
            config->endpoint,
            sizeof(config->endpoint),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    endpoint_len = strlen(config->endpoint);
    while (endpoint_len > 0 && config->endpoint[endpoint_len - 1] == '/') {
        config->endpoint[endpoint_len - 1] = '\0';
        endpoint_len--;
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("bucket"),
            "bucket",
            config->bucket,
            sizeof(config->bucket),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("access_token");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("bearer_token");
    }
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("oauth_token");
    }
    if (king_object_store_copy_cloud_string(
            value,
            "access_token",
            config->access_token,
            sizeof(config->access_token),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_bool(
            king_object_store_cloud_credentials_find("path_style"),
            "path_style",
            1,
            &config->path_style,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_bool(
            king_object_store_cloud_credentials_find("verify_tls"),
            "verify_tls",
            1,
            &config->verify_tls,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_object_store_gcs_build_url(
    const king_object_store_gcs_runtime_config_t *config,
    const char *object_id,
    zend_bool list_bucket,
    smart_str *url,
    char *error,
    size_t error_size)
{
    const char *scheme_sep;

    if (config == NULL || url == NULL) {
        snprintf(error, error_size, "Failed to build cloud_gcs URL.");
        return FAILURE;
    }

    smart_str_appends(url, config->endpoint);

    if (config->path_style) {
        smart_str_appendc(url, '/');
        smart_str_appends(url, config->bucket);
        if (object_id != NULL && object_id[0] != '\0') {
            smart_str_appendc(url, '/');
            king_object_store_url_encode_component(url, object_id);
        }
    } else {
        scheme_sep = strstr(config->endpoint, "://");
        if (scheme_sep == NULL) {
            snprintf(error, error_size, "cloud_gcs endpoint must include a URL scheme.");
            return FAILURE;
        }

        smart_str_free(url);
        smart_str_appends(url, "");
        smart_str_appendl(url, config->endpoint, (size_t) (scheme_sep - config->endpoint + 3));
        smart_str_appends(url, config->bucket);
        smart_str_appendc(url, '.');
        smart_str_appends(url, scheme_sep + 3);
        if (object_id != NULL && object_id[0] != '\0') {
            smart_str_appendc(url, '/');
            king_object_store_url_encode_component(url, object_id);
        }
    }

    if (list_bucket) {
        smart_str_appendc(url, '/');
    }

    smart_str_0(url);
    return SUCCESS;
}

static zend_bool king_object_store_gcs_response_is_throttled(
    const king_object_store_http_response_t *response)
{
    char error_code[128];

    if (response == NULL) {
        return 0;
    }

    if (response->status_code == 429) {
        return 1;
    }

    if (response->status_code != 503) {
        return 0;
    }

    if (!king_object_store_s3_response_error_code(response, error_code, sizeof(error_code))) {
        return 0;
    }

    return strcasecmp(error_code, "SlowDown") == 0
        || strcasecmp(error_code, "Throttled") == 0
        || strcasecmp(error_code, "RateLimitExceeded") == 0
        || strcasecmp(error_code, "TooManyRequests") == 0;
}

static void king_object_store_gcs_format_response_error(
    const char *method,
    const char *object_id,
    const char *operation,
    const king_object_store_http_response_t *response,
    char *error,
    size_t error_size)
{
    const char *safe_method = method == NULL ? "request" : method;
    const char *safe_operation = operation == NULL ? "request" : operation;
    long status_code;

    if (error == NULL || error_size == 0) {
        return;
    }

    status_code = response == NULL ? 0 : response->status_code;

    if (status_code == 401 || status_code == 403) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_gcs credentials were rejected during %s for '%s' (HTTP %ld).",
                safe_method,
                object_id,
                status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_gcs credentials were rejected during %s (HTTP %ld).",
                safe_operation,
                status_code
            );
        }
        return;
    }

    if (king_object_store_gcs_response_is_throttled(response)) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_gcs %s for '%s' was throttled by the configured endpoint (HTTP %ld).",
                safe_operation,
                object_id,
                status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_gcs %s was throttled by the configured endpoint (HTTP %ld).",
                safe_operation,
                status_code
            );
        }
        return;
    }

    if (object_id != NULL && object_id[0] != '\0') {
        snprintf(
            error,
            error_size,
            "cloud_gcs %s for '%s' returned HTTP %ld.",
            safe_method,
            object_id,
            status_code
        );
        return;
    }

    snprintf(
        error,
        error_size,
        "cloud_gcs %s returned HTTP %ld.",
        safe_operation,
        status_code
    );
}

static void king_object_store_gcs_format_transport_error(
    const char *method,
    CURLcode curl_code,
    char *error,
    size_t error_size)
{
    const char *safe_method = method == NULL ? "request" : method;

    if (error == NULL || error_size == 0) {
        return;
    }

    switch (curl_code) {
        case CURLE_COULDNT_RESOLVE_HOST:
            snprintf(
                error,
                error_size,
                "cloud_gcs %s request failed: could not resolve the configured endpoint host.",
                safe_method
            );
            return;
        case CURLE_COULDNT_CONNECT:
            snprintf(
                error,
                error_size,
                "cloud_gcs %s request failed: could not connect to the configured endpoint.",
                safe_method
            );
            return;
        case CURLE_OPERATION_TIMEDOUT:
            snprintf(
                error,
                error_size,
                "cloud_gcs %s request failed: timed out while contacting the configured endpoint.",
                safe_method
            );
            return;
        case CURLE_SEND_ERROR:
        case CURLE_RECV_ERROR:
        case CURLE_GOT_NOTHING:
            snprintf(
                error,
                error_size,
                "cloud_gcs %s request failed: network I/O with the configured endpoint failed.",
                safe_method
            );
            return;
        default:
            snprintf(
                error,
                error_size,
                "cloud_gcs %s request failed: %s",
                safe_method,
                king_object_store_libcurl.curl_easy_strerror_fn(curl_code)
            );
            return;
    }
}

static zend_result king_object_store_gcs_http_request(
    const king_object_store_gcs_runtime_config_t *config,
    const char *method,
    const char *object_id,
    zend_bool list_bucket,
    const void *body,
    size_t body_size,
    FILE *body_stream,
    uint64_t body_stream_size,
    const king_object_metadata_t *request_metadata,
    const char *range_header,
    king_object_store_http_response_t *response,
    char *error,
    size_t error_size,
    FILE *response_body_sink)
{
    CURL *easy = NULL;
    struct curl_slist *headers = NULL;
    smart_str url = {0};
    char auth_header[1400];
    CURLcode curl_code;

    if (config == NULL || method == NULL || response == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_ensure_libcurl_ready() != SUCCESS) {
        snprintf(error, error_size, "%s", king_object_store_libcurl.load_error);
        return FAILURE;
    }

    if (king_object_store_gcs_build_url(config, object_id, list_bucket, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }

    easy = king_object_store_libcurl.curl_easy_init_fn();
    if (easy == NULL) {
        smart_str_free(&url);
        snprintf(error, error_size, "Failed to create libcurl handle for cloud_gcs operation.");
        return FAILURE;
    }

    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Accept: */*");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Expect:");
    snprintf(auth_header, sizeof(auth_header), "Authorization: Bearer %s", config->access_token);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, auth_header);

    if (body != NULL || strcmp(method, "PUT") == 0) {
        char content_type_header[160];

        if (request_metadata != NULL && request_metadata->content_type[0] != '\0') {
            snprintf(content_type_header, sizeof(content_type_header), "Content-Type: %s", request_metadata->content_type);
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, content_type_header);
        } else {
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Content-Type: application/octet-stream");
        }
    }
    if (request_metadata != NULL && request_metadata->content_encoding[0] != '\0') {
        char content_encoding_header[128];

        snprintf(content_encoding_header, sizeof(content_encoding_header), "Content-Encoding: %s", request_metadata->content_encoding);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, content_encoding_header);
    }
    if (request_metadata != NULL) {
        headers = king_object_store_append_metadata_headers(headers, "x-goog-meta-", request_metadata);
    }
    if (range_header != NULL && range_header[0] != '\0') {
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, range_header);
    }

    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_URL, ZSTR_VAL(url.s));
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_NOSIGNAL, 1L);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_TIMEOUT_MS, KING_OBJECT_STORE_HTTP_TIMEOUT_MS);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_USERAGENT, "king-object-store/1");
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPHEADER, headers);
    if (response_body_sink != NULL) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEDATA, response_body_sink);
    } else {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEFUNCTION, king_object_store_http_write_callback);
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEDATA, response);
    }
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HEADERFUNCTION, king_object_store_http_header_callback);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HEADERDATA, response);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_SSL_VERIFYPEER, config->verify_tls ? 1L : 0L);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_SSL_VERIFYHOST, config->verify_tls ? 2L : 0L);

    if (strcmp(method, "HEAD") == 0) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_NOBODY, 1L);
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_CUSTOMREQUEST, "HEAD");
    } else if (strcmp(method, "DELETE") == 0) {
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
    } else {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPGET, 1L);
    }

    curl_code = king_object_store_libcurl.curl_easy_perform_fn(easy);
    king_object_store_libcurl.curl_easy_getinfo_fn(easy, CURLINFO_RESPONSE_CODE, &response->status_code);
    smart_str_0(&response->body);
    smart_str_0(&response->headers);

    if (curl_code != CURLE_OK) {
        if (!response->body_limit_exceeded
            && !response->header_limit_exceeded
            && response->status_code >= 400
            && response->status_code < 600) {
            king_object_store_libcurl.curl_slist_free_all_fn(headers);
            king_object_store_libcurl.curl_easy_cleanup_fn(easy);
            smart_str_free(&url);
            return SUCCESS;
        }
        if (response->body_limit_exceeded) {
            snprintf(error, error_size, "cloud_gcs response body exceeded the object-store size guard.");
        } else if (response->header_limit_exceeded) {
            snprintf(error, error_size, "cloud_gcs response headers exceeded the object-store size guard.");
        } else {
            king_object_store_gcs_format_transport_error(method, curl_code, error, error_size);
        }
        king_object_store_libcurl.curl_slist_free_all_fn(headers);
        king_object_store_libcurl.curl_easy_cleanup_fn(easy);
        smart_str_free(&url);
        return FAILURE;
    }

    king_object_store_libcurl.curl_slist_free_all_fn(headers);
    king_object_store_libcurl.curl_easy_cleanup_fn(easy);
    smart_str_free(&url);

    return SUCCESS;
}

static void king_object_store_gcs_metadata_from_headers(
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

    if (king_object_store_http_header_get(headers, "x-goog-meta-object-id", value, sizeof(value))) {
        strncpy(metadata->object_id, value, sizeof(metadata->object_id) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-content-type", value, sizeof(value))) {
        strncpy(metadata->content_type, value, sizeof(metadata->content_type) - 1);
    } else if (king_object_store_http_header_get(headers, "Content-Type", value, sizeof(value))) {
        strncpy(metadata->content_type, value, sizeof(metadata->content_type) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-content-encoding", value, sizeof(value))) {
        strncpy(metadata->content_encoding, value, sizeof(metadata->content_encoding) - 1);
    } else if (king_object_store_http_header_get(headers, "Content-Encoding", value, sizeof(value))) {
        strncpy(metadata->content_encoding, value, sizeof(metadata->content_encoding) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-etag", value, sizeof(value))) {
        strncpy(metadata->etag, value, sizeof(metadata->etag) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-content-length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    } else if (king_object_store_http_header_get(headers, "Content-Length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "ETag", value, sizeof(value))) {
        strncpy(metadata->etag, value, sizeof(metadata->etag) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-integrity-sha256", value, sizeof(value))) {
        strncpy(metadata->integrity_sha256, value, sizeof(metadata->integrity_sha256) - 1);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-version", value, sizeof(value))) {
        metadata->version = (uint64_t) strtoull(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-created-at", value, sizeof(value))) {
        metadata->created_at = (time_t) strtoll(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-modified-at", value, sizeof(value))) {
        metadata->modified_at = (time_t) strtoll(value, NULL, 10);
    }
    if (metadata->modified_at == 0 && king_object_store_http_header_get(headers, "Last-Modified", value, sizeof(value))) {
        metadata->modified_at = king_object_store_parse_rfc7231_time(value);
    }
    if (metadata->created_at == 0) {
        metadata->created_at = metadata->modified_at;
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-expires-at", value, sizeof(value))) {
        metadata->expires_at = (time_t) strtoll(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-object-type", value, sizeof(value))) {
        metadata->object_type = (king_object_type_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-cache-policy", value, sizeof(value))) {
        metadata->cache_policy = (king_cache_policy_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-cache-ttl-seconds", value, sizeof(value))) {
        metadata->cache_ttl_seconds = (uint32_t) strtoul(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-is-backed-up", value, sizeof(value))) {
        metadata->is_backed_up = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-replication-status", value, sizeof(value))) {
        metadata->replication_status = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-is-distributed", value, sizeof(value))) {
        metadata->is_distributed = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-distribution-peer-count", value, sizeof(value))) {
        metadata->distribution_peer_count = (uint32_t) strtoul(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-local-fs-present", value, sizeof(value))) {
        metadata->local_fs_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-distributed-present", value, sizeof(value))) {
        metadata->distributed_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-cloud-s3-present", value, sizeof(value))) {
        metadata->cloud_s3_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-cloud-gcs-present", value, sizeof(value))) {
        metadata->cloud_gcs_present = (uint8_t) atoi(value);
    }
    if (king_object_store_http_header_get(headers, "x-goog-meta-cloud-azure-present", value, sizeof(value))) {
        metadata->cloud_azure_present = (uint8_t) atoi(value);
    }
}

static zend_result king_object_store_gcs_head_object(
    const char *object_id,
    zend_bool *exists_out,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;

    if (exists_out != NULL) {
        *exists_out = 0;
    }

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request(
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
        king_object_store_gcs_format_response_error(
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
        king_object_store_gcs_metadata_from_headers(&response.headers, object_id, metadata);
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static zend_result king_object_store_gcs_http_request_url(
    const king_object_store_gcs_runtime_config_t *config,
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
    char auth_header[1400];
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
        snprintf(error, error_size, "Failed to create libcurl handle for cloud_gcs operation.");
        return FAILURE;
    }

    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Accept: */*");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Expect:");
    snprintf(auth_header, sizeof(auth_header), "Authorization: Bearer %s", config->access_token);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, auth_header);

    if (request_metadata != NULL || strcmp(method, "PUT") == 0) {
        char content_type_header[160];

        if (request_metadata != NULL && request_metadata->content_type[0] != '\0') {
            snprintf(content_type_header, sizeof(content_type_header), "Content-Type: %s", request_metadata->content_type);
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, content_type_header);
        } else {
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Content-Type: application/octet-stream");
        }
    }
    if (request_metadata != NULL && request_metadata->content_encoding[0] != '\0') {
        char content_encoding_header[128];

        snprintf(content_encoding_header, sizeof(content_encoding_header), "Content-Encoding: %s", request_metadata->content_encoding);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, content_encoding_header);
    }
    if (request_metadata != NULL) {
        headers = king_object_store_append_metadata_headers(headers, "x-goog-meta-", request_metadata);
    }
    for (i = 0; i < extra_header_count; ++i) {
        if (extra_headers[i] != NULL && extra_headers[i][0] != '\0') {
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, extra_headers[i]);
        }
    }

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
            snprintf(error, error_size, "cloud_gcs response body exceeded the object-store size guard.");
        } else if (response->header_limit_exceeded) {
            snprintf(error, error_size, "cloud_gcs response headers exceeded the object-store size guard.");
        } else {
            king_object_store_gcs_format_transport_error(method, curl_code, error, error_size);
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

