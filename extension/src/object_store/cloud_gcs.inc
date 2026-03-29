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
    king_object_store_http_response_t *response,
    char *error,
    size_t error_size)
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
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Content-Type: application/octet-stream");
    }

    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_URL, ZSTR_VAL(url.s));
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

    if (strcmp(method, "HEAD") == 0) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_NOBODY, 1L);
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_CUSTOMREQUEST, "HEAD");
    } else if (strcmp(method, "DELETE") == 0) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_CUSTOMREQUEST, "DELETE");
    } else if (strcmp(method, "PUT") == 0) {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_CUSTOMREQUEST, "PUT");
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
        smart_str_free(&url);
        return FAILURE;
    }

    king_object_store_libcurl.curl_easy_getinfo_fn(easy, CURLINFO_RESPONSE_CODE, &response->status_code);
    smart_str_0(&response->body);
    smart_str_0(&response->headers);

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
    if (king_object_store_http_header_get(headers, "x-goog-meta-content-length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    } else if (king_object_store_http_header_get(headers, "Content-Length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    }
    if (king_object_store_http_header_get(headers, "ETag", value, sizeof(value))) {
        strncpy(metadata->etag, value, sizeof(metadata->etag) - 1);
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
            &response,
            error,
            error_size
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

static int king_object_store_gcs_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;
    king_object_metadata_t final_metadata;
    const king_object_metadata_t *metadata_source = metadata;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;
    uint64_t old_size = 0;

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata_source != NULL) {
        final_metadata = *metadata_source;
    }
    if (final_metadata.object_id[0] == '\0') {
        strncpy(final_metadata.object_id, object_id, sizeof(final_metadata.object_id) - 1);
    }
    if (final_metadata.content_length == 0) {
        final_metadata.content_length = data_size;
    }
    if (final_metadata.created_at == 0) {
        final_metadata.created_at = time(NULL);
    }
    final_metadata.modified_at = time(NULL);
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_CLOUD_GCS,
        1
    );

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_gcs_head_object(
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

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request(
            &config,
            "PUT",
            object_id,
            0,
            data,
            data_size,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_gcs_format_response_error(
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
            "cloud_gcs write for '%s' succeeded but local metadata sidecar write failed.",
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

static int king_object_store_gcs_read_with_error(
    const char *object_id,
    void **data,
    size_t *data_size,
    char *error,
    size_t error_size)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;
    void *copy;

    if (data == NULL || data_size == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request(
            &config,
            "GET",
            object_id,
            0,
            NULL,
            0,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code == 404) {
        king_object_store_gcs_format_response_error(
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

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_gcs_format_response_error(
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

static int king_object_store_gcs_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_gcs_read_with_error(
        object_id,
        data,
        data_size,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error)
    );
}

static int king_object_store_gcs_delete_with_error(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool update_counters,
    zend_bool remove_sidecar,
    zend_bool allow_missing,
    zend_bool *removed_out)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;

    if (removed_out != NULL) {
        *removed_out = 0;
    }

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_gcs_head_object(
            object_id,
            &exists,
            &existing_metadata,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (!exists) {
        return allow_missing ? SUCCESS : FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request(
            &config,
            "DELETE",
            object_id,
            0,
            NULL,
            0,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_gcs_format_response_error(
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

static int king_object_store_gcs_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out)
{
    return king_object_store_gcs_delete_with_error(
        object_id,
        error,
        error_size,
        0,
        0,
        1,
        removed_out
    );
}

static int king_object_store_gcs_remove(const char *object_id)
{
    return king_object_store_gcs_delete_with_error(
        object_id,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error),
        1,
        1,
        0,
        NULL
    );
}

static int king_object_store_gcs_list(zval *return_array)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;

    if (king_object_store_gcs_resolve_config(&config, king_object_store_runtime.primary_adapter_error, sizeof(king_object_store_runtime.primary_adapter_error)) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request(
            &config,
            "GET",
            NULL,
            1,
            NULL,
            0,
            &response,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_gcs_format_response_error(
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

    if (king_object_store_s3_parse_list_xml(
            ZSTR_VAL(response.body.s),
            return_array,
            NULL,
            NULL,
            NULL
        ) != SUCCESS) {
        snprintf(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "Failed to parse cloud_gcs inventory XML."
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_gcs_rehydrate_stats(char *error, size_t error_size)
{
    king_object_store_gcs_runtime_config_t config;
    king_object_store_http_response_t response;
    uint64_t object_count = 0;
    uint64_t stored_bytes = 0;
    time_t latest_object_at = 0;

    if (king_object_store_gcs_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_gcs_http_request(
            &config,
            "GET",
            NULL,
            1,
            NULL,
            0,
            &response,
            error,
            error_size
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_gcs_format_response_error(
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

    if (response.body.s != NULL && king_object_store_s3_parse_list_xml(
            ZSTR_VAL(response.body.s),
            NULL,
            &object_count,
            &stored_bytes,
            &latest_object_at
        ) != SUCCESS) {
        snprintf(error, error_size, "Failed to parse cloud_gcs inventory XML during rehydrate.");
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_runtime.current_object_count = object_count;
    king_object_store_runtime.current_stored_bytes = stored_bytes;
    king_object_store_runtime.latest_object_at = latest_object_at;

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}
