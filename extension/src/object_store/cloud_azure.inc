typedef struct _king_object_store_azure_runtime_config_t {
    char endpoint[512];
    char container[256];
    char access_token[1024];
    zend_bool verify_tls;
} king_object_store_azure_runtime_config_t;

static zend_result king_object_store_azure_resolve_config(
    king_object_store_azure_runtime_config_t *config,
    char *error,
    size_t error_size)
{
    zval *value;
    char account_name[128];
    size_t endpoint_len;

    if (config == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    memset(config, 0, sizeof(*config));
    memset(account_name, 0, sizeof(account_name));
    error[0] = '\0';

    if (Z_TYPE(king_object_store_runtime.config.cloud_credentials) != IS_ARRAY) {
        snprintf(error, error_size, "cloud_credentials must be configured for cloud_azure.");
        return FAILURE;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        snprintf(error, error_size, "storage_root_path is required for cloud_azure metadata sidecars.");
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("api_endpoint");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("endpoint");
    }
    if (value == NULL) {
        if (king_object_store_copy_cloud_string(
                king_object_store_cloud_credentials_find("account_name"),
                "account_name",
                account_name,
                sizeof(account_name),
                1,
                error,
                error_size
            ) != SUCCESS) {
            return FAILURE;
        }

        snprintf(
            config->endpoint,
            sizeof(config->endpoint),
            "https://%s.blob.core.windows.net",
            account_name
        );
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

    value = king_object_store_cloud_credentials_find("container");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("bucket");
    }
    if (king_object_store_copy_cloud_string(
            value,
            "container",
            config->container,
            sizeof(config->container),
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

static zend_result king_object_store_azure_build_url(
    const king_object_store_azure_runtime_config_t *config,
    const char *object_id,
    zend_bool list_bucket,
    smart_str *url,
    char *error,
    size_t error_size)
{
    if (config == NULL || url == NULL) {
        snprintf(error, error_size, "Failed to build cloud_azure URL.");
        return FAILURE;
    }

    smart_str_appends(url, config->endpoint);
    smart_str_appendc(url, '/');
    smart_str_appends(url, config->container);

    if (list_bucket) {
        smart_str_appends(url, "?restype=container&comp=list");
    } else if (object_id != NULL && object_id[0] != '\0') {
        smart_str_appendc(url, '/');
        king_object_store_url_encode_component(url, object_id);
    }

    smart_str_0(url);
    return SUCCESS;
}

static zend_bool king_object_store_azure_response_is_throttled(
    const king_object_store_http_response_t *response)
{
    char error_code[128];

    if (response == NULL) {
        return 0;
    }

    if (response->status_code == 429) {
        return 1;
    }

    if (response->status_code != 503 && response->status_code != 500) {
        return 0;
    }

    if (!king_object_store_s3_response_error_code(response, error_code, sizeof(error_code))) {
        return 0;
    }

    return strcasecmp(error_code, "ServerBusy") == 0
        || strcasecmp(error_code, "TooManyRequests") == 0
        || strcasecmp(error_code, "Throttled") == 0
        || strcasecmp(error_code, "OperationTimedOut") == 0;
}

static void king_object_store_azure_format_response_error(
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
                "cloud_azure credentials were rejected during %s for '%s' (HTTP %ld).",
                safe_method,
                object_id,
                status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_azure credentials were rejected during %s (HTTP %ld).",
                safe_operation,
                status_code
            );
        }
        return;
    }

    if (king_object_store_azure_response_is_throttled(response)) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_azure %s for '%s' was throttled by the configured endpoint (HTTP %ld).",
                safe_operation,
                object_id,
                status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_azure %s was throttled by the configured endpoint (HTTP %ld).",
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
            "cloud_azure %s for '%s' returned HTTP %ld.",
            safe_method,
            object_id,
            status_code
        );
        return;
    }

    snprintf(
        error,
        error_size,
        "cloud_azure %s returned HTTP %ld.",
        safe_operation,
        status_code
    );
}

static void king_object_store_azure_format_transport_error(
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
                "cloud_azure %s request failed: could not resolve the configured endpoint host.",
                safe_method
            );
            return;
        case CURLE_COULDNT_CONNECT:
            snprintf(
                error,
                error_size,
                "cloud_azure %s request failed: could not connect to the configured endpoint.",
                safe_method
            );
            return;
        case CURLE_OPERATION_TIMEDOUT:
            snprintf(
                error,
                error_size,
                "cloud_azure %s request failed: timed out while contacting the configured endpoint.",
                safe_method
            );
            return;
        case CURLE_SEND_ERROR:
        case CURLE_RECV_ERROR:
        case CURLE_GOT_NOTHING:
            snprintf(
                error,
                error_size,
                "cloud_azure %s request failed: network I/O with the configured endpoint failed.",
                safe_method
            );
            return;
        default:
            snprintf(
                error,
                error_size,
                "cloud_azure %s request failed: %s",
                safe_method,
                king_object_store_libcurl.curl_easy_strerror_fn(curl_code)
            );
            return;
    }
}

static zend_result king_object_store_azure_http_request(
    const king_object_store_azure_runtime_config_t *config,
    const char *method,
    const char *object_id,
    zend_bool list_container,
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
    char date_header[96];
    time_t now;
    struct tm tm_utc;
    CURLcode curl_code;

    if (config == NULL || method == NULL || response == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_ensure_libcurl_ready() != SUCCESS) {
        snprintf(error, error_size, "%s", king_object_store_libcurl.load_error);
        return FAILURE;
    }

    if (king_object_store_azure_build_url(config, object_id, list_container, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }

    easy = king_object_store_libcurl.curl_easy_init_fn();
    if (easy == NULL) {
        smart_str_free(&url);
        snprintf(error, error_size, "Failed to create libcurl handle for cloud_azure operation.");
        return FAILURE;
    }

    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Accept: */*");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Expect:");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "x-ms-version: 2023-11-03");

    now = time(NULL);
    gmtime_r(&now, &tm_utc);
    strftime(date_header, sizeof(date_header), "x-ms-date: %a, %d %b %Y %H:%M:%S GMT", &tm_utc);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, date_header);

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
    if (strcmp(method, "PUT") == 0) {
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, "x-ms-blob-type: BlockBlob");
    }
    if (request_metadata != NULL) {
        headers = king_object_store_append_metadata_headers(headers, "x-ms-meta-", request_metadata);
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
            snprintf(error, error_size, "cloud_azure response body exceeded the object-store size guard.");
        } else if (response->header_limit_exceeded) {
            snprintf(error, error_size, "cloud_azure response headers exceeded the object-store size guard.");
        } else {
            king_object_store_azure_format_transport_error(method, curl_code, error, error_size);
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

static zend_result king_object_store_azure_http_request_url(
    const king_object_store_azure_runtime_config_t *config,
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
    char date_header[96];
    time_t now;
    struct tm tm_utc;
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
        snprintf(error, error_size, "Failed to create libcurl handle for cloud_azure operation.");
        return FAILURE;
    }

    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Accept: */*");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Expect:");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "x-ms-version: 2023-11-03");

    now = time(NULL);
    gmtime_r(&now, &tm_utc);
    strftime(date_header, sizeof(date_header), "x-ms-date: %a, %d %b %Y %H:%M:%S GMT", &tm_utc);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, date_header);

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
        headers = king_object_store_append_metadata_headers(headers, "x-ms-meta-", request_metadata);
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
            snprintf(error, error_size, "cloud_azure response body exceeded the object-store size guard.");
        } else if (response->header_limit_exceeded) {
            snprintf(error, error_size, "cloud_azure response headers exceeded the object-store size guard.");
        } else {
            king_object_store_azure_format_transport_error(method, curl_code, error, error_size);
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

static int king_object_store_azure_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    king_object_metadata_t final_metadata;
    const king_object_metadata_t *metadata_source = metadata;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;
    uint64_t old_size = 0;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata_source != NULL) {
        final_metadata = *metadata_source;
    }
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_CLOUD_AZURE,
        1
    );

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_azure_head_object(
            object_id,
            &exists,
            &existing_metadata,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (exists) {
        old_size = existing_metadata.content_length;
    }
    king_object_store_prepare_metadata_for_write(
        object_id,
        data,
        data_size,
        &final_metadata,
        exists ? &existing_metadata : NULL
    );

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "PUT",
            object_id,
            0,
            data,
            data_size,
            NULL,
            0,
            &final_metadata,
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
        king_object_store_azure_format_response_error(
            "PUT",
            object_id,
            "write",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "cloud_azure write for '%s' succeeded but local metadata sidecar write failed.",
            object_id
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (update_counters) {
        king_object_store_apply_local_fs_counters_for_rewrite(object_id, data_size, exists ? 1 : 0, old_size);
    }
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_azure_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    king_object_metadata_t final_metadata;
    const king_object_metadata_t *metadata_source = metadata;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;
    uint64_t old_size = 0;
    uint64_t source_size = 0;
    char computed_sha256[65];
    FILE *source_fp = NULL;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_compute_sha256_hex_for_path(source_path, computed_sha256, &source_size) != SUCCESS) {
        snprintf(error, error_size, "cloud_azure could not hash staged upload content for '%s'.", object_id);
        return FAILURE;
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata_source != NULL) {
        final_metadata = *metadata_source;
    }
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_CLOUD_AZURE,
        1
    );

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_azure_head_object(
            object_id,
            &exists,
            &existing_metadata,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (exists) {
        old_size = existing_metadata.content_length;
    }
    king_object_store_finalize_metadata_for_write(
        object_id,
        source_size,
        computed_sha256,
        &final_metadata,
        exists ? &existing_metadata : NULL
    );

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        snprintf(error, error_size, "cloud_azure could not open the staged upload for '%s'.", object_id);
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "PUT",
            object_id,
            0,
            NULL,
            0,
            source_fp,
            source_size,
            &final_metadata,
            NULL,
            &response,
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        fclose(source_fp);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }
    fclose(source_fp);

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "PUT",
            object_id,
            "write",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "cloud_azure write for '%s' succeeded but local metadata sidecar write failed.",
            object_id
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (update_counters) {
        king_object_store_apply_local_fs_counters_for_rewrite(object_id, source_size, exists ? 1 : 0, old_size);
    }
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

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

static int king_object_store_azure_delete_with_error(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool update_counters,
    zend_bool remove_sidecar,
    zend_bool allow_missing,
    zend_bool *removed_out)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;

    if (removed_out != NULL) {
        *removed_out = 0;
    }

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_azure_head_object(
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
    if (king_object_store_azure_http_request(
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
        king_object_store_azure_format_response_error(
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

static int king_object_store_azure_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out)
{
    return king_object_store_azure_delete_with_error(
        object_id,
        error,
        error_size,
        0,
        0,
        1,
        removed_out
    );
}

static int king_object_store_azure_remove(const char *object_id)
{
    return king_object_store_azure_delete_with_error(
        object_id,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error),
        1,
        1,
        0,
        NULL
    );
}

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

static int king_object_store_azure_rehydrate_stats(char *error, size_t error_size)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    uint64_t object_count = 0;
    uint64_t stored_bytes = 0;
    time_t latest_object_at = 0;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
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
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "GET",
            NULL,
            "inventory rehydrate",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.body.s != NULL && king_object_store_azure_parse_list_xml(
            ZSTR_VAL(response.body.s),
            NULL,
            &object_count,
            &stored_bytes,
            &latest_object_at
        ) != SUCCESS) {
        snprintf(error, error_size, "Failed to parse cloud_azure inventory XML during rehydrate.");
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_runtime.current_object_count = object_count;
    king_object_store_runtime.current_stored_bytes = stored_bytes;
    king_object_store_runtime.latest_object_at = latest_object_at;

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}
