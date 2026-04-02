/*
 * Shared cloud HTTP runtime for S3-first adapters. Holds bounded response
 * buffers plus the common request/signing/runtime helpers reused by cloud
 * provider backends.
 */

typedef struct _king_object_store_http_response_t {
    smart_str body;
    smart_str headers;
    size_t body_bytes;
    size_t header_bytes;
    zend_bool body_limit_exceeded;
    zend_bool header_limit_exceeded;
    long status_code;
} king_object_store_http_response_t;

typedef struct _king_object_store_libcurl_api_t {
    void *handle;
    zend_bool ready;
    zend_bool load_attempted;
    char load_error[256];
    CURLcode (*curl_global_init_fn)(long flags);
    void (*curl_global_cleanup_fn)(void);
    CURL *(*curl_easy_init_fn)(void);
    void (*curl_easy_cleanup_fn)(CURL *easy_handle);
    CURLcode (*curl_easy_setopt_fn)(CURL *easy_handle, CURLoption option, ...);
    CURLcode (*curl_easy_perform_fn)(CURL *easy_handle);
    CURLcode (*curl_easy_getinfo_fn)(CURL *easy_handle, CURLINFO info, ...);
    const char *(*curl_easy_strerror_fn)(CURLcode);
    struct curl_slist *(*curl_slist_append_fn)(struct curl_slist *, const char *);
    void (*curl_slist_free_all_fn)(struct curl_slist *);
} king_object_store_libcurl_api_t;

typedef struct _king_object_store_s3_runtime_config_t {
    char endpoint[512];
    char bucket[256];
    char region[64];
    char access_key[128];
    char secret_key[256];
    char session_token[256];
    zend_bool path_style;
    zend_bool verify_tls;
} king_object_store_s3_runtime_config_t;

#define KING_OBJECT_STORE_HTTP_TIMEOUT_MS 15000L
#define KING_OBJECT_STORE_HTTP_MAX_BODY_BYTES (16 * 1024 * 1024)
#define KING_OBJECT_STORE_HTTP_MAX_HEADER_BYTES (128 * 1024)

static king_object_store_libcurl_api_t king_object_store_libcurl = {0};

static void king_object_store_reset_libcurl_runtime_state(void)
{
    memset(&king_object_store_libcurl, 0, sizeof(king_object_store_libcurl));
}

static void king_object_store_close_libcurl_runtime_handle(void)
{
    if (king_object_store_libcurl.handle != NULL) {
        dlclose(king_object_store_libcurl.handle);
        king_object_store_libcurl.handle = NULL;
    }
}

static zend_result king_object_store_load_libcurl_symbol(void **target, const char *name)
{
    *target = dlsym(king_object_store_libcurl.handle, name);
    if (*target == NULL) {
        snprintf(
            king_object_store_libcurl.load_error,
            sizeof(king_object_store_libcurl.load_error),
            "Failed to load libcurl symbol '%s' for cloud object-store operations.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_object_store_ensure_libcurl_ready(void)
{
    const char *const candidates[] = {"libcurl.so.4", "libcurl.so", NULL};
    size_t index;

    if (king_object_store_libcurl.ready) {
        return SUCCESS;
    }

    if (king_object_store_libcurl.load_attempted) {
        return FAILURE;
    }

    king_object_store_libcurl.load_attempted = 1;

    for (index = 0; candidates[index] != NULL; index++) {
        king_object_store_libcurl.handle = dlopen(candidates[index], RTLD_LAZY | RTLD_LOCAL);
        if (king_object_store_libcurl.handle != NULL) {
            break;
        }
    }

    if (king_object_store_libcurl.handle == NULL) {
        snprintf(
            king_object_store_libcurl.load_error,
            sizeof(king_object_store_libcurl.load_error),
            "Failed to load libcurl.so.4 or libcurl.so for cloud object-store operations."
        );
        return FAILURE;
    }

    if (king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_global_init_fn,
            "curl_global_init"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_global_cleanup_fn,
            "curl_global_cleanup"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_init_fn,
            "curl_easy_init"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_cleanup_fn,
            "curl_easy_cleanup"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_setopt_fn,
            "curl_easy_setopt"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_perform_fn,
            "curl_easy_perform"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_getinfo_fn,
            "curl_easy_getinfo"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_strerror_fn,
            "curl_easy_strerror"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_slist_append_fn,
            "curl_slist_append"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_slist_free_all_fn,
            "curl_slist_free_all"
        ) != SUCCESS) {
        king_object_store_close_libcurl_runtime_handle();
        return FAILURE;
    }

    if (king_object_store_libcurl.curl_global_init_fn(CURL_GLOBAL_DEFAULT) != CURLE_OK) {
        snprintf(
            king_object_store_libcurl.load_error,
            sizeof(king_object_store_libcurl.load_error),
            "curl_global_init() failed for cloud object-store operations."
        );
        king_object_store_close_libcurl_runtime_handle();
        return FAILURE;
    }

    king_object_store_libcurl.ready = 1;
    return SUCCESS;
}

static void king_object_store_shutdown_libcurl_runtime(void)
{
    if (king_object_store_libcurl.ready && king_object_store_libcurl.curl_global_cleanup_fn != NULL) {
        king_object_store_libcurl.curl_global_cleanup_fn();
    }

    king_object_store_close_libcurl_runtime_handle();
    king_object_store_reset_libcurl_runtime_state();
}

static void king_object_store_http_response_init(king_object_store_http_response_t *response)
{
    if (response == NULL) {
        return;
    }

    memset(response, 0, sizeof(*response));
}

static void king_object_store_http_response_destroy(king_object_store_http_response_t *response)
{
    if (response == NULL) {
        return;
    }

    smart_str_free(&response->body);
    smart_str_free(&response->headers);
}

static size_t king_object_store_http_write_callback(
    char *contents,
    size_t size,
    size_t nmemb,
    void *userdata)
{
    king_object_store_http_response_t *response = (king_object_store_http_response_t *) userdata;
    size_t bytes = size * nmemb;

    if (response == NULL) {
        return 0;
    }

    if (response->body_bytes + bytes > KING_OBJECT_STORE_HTTP_MAX_BODY_BYTES) {
        response->body_limit_exceeded = 1;
        return 0;
    }

    smart_str_appendl(&response->body, contents, bytes);
    response->body_bytes += bytes;
    return bytes;
}

static size_t king_object_store_http_header_callback(
    char *contents,
    size_t size,
    size_t nmemb,
    void *userdata)
{
    king_object_store_http_response_t *response = (king_object_store_http_response_t *) userdata;
    size_t bytes = size * nmemb;

    if (response == NULL) {
        return 0;
    }

    if (response->header_bytes + bytes > KING_OBJECT_STORE_HTTP_MAX_HEADER_BYTES) {
        response->header_limit_exceeded = 1;
        return 0;
    }

    smart_str_appendl(&response->headers, contents, bytes);
    response->header_bytes += bytes;
    return bytes;
}

static zval *king_object_store_cloud_credentials_find(const char *key)
{
    zval *credentials;

    credentials = &king_object_store_runtime.config.cloud_credentials;
    if (Z_TYPE_P(credentials) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_str_find(
        Z_ARRVAL_P(credentials),
        key,
        strlen(key)
    );
}

static zend_result king_object_store_copy_cloud_string(
    zval *source,
    const char *field_name,
    char *destination,
    size_t destination_size,
    zend_bool required,
    char *error,
    size_t error_size)
{
    if (source == NULL) {
        if (required) {
            snprintf(error, error_size, "cloud_credentials.%s is required for cloud_s3.", field_name);
            return FAILURE;
        }
        return SUCCESS;
    }

    if (Z_TYPE_P(source) != IS_STRING || Z_STRLEN_P(source) == 0) {
        snprintf(error, error_size, "cloud_credentials.%s must be a non-empty string for cloud_s3.", field_name);
        return FAILURE;
    }

    if ((size_t) Z_STRLEN_P(source) >= destination_size) {
        snprintf(error, error_size, "cloud_credentials.%s exceeds the maximum supported length for cloud_s3.", field_name);
        return FAILURE;
    }

    snprintf(destination, destination_size, "%s", Z_STRVAL_P(source));
    return SUCCESS;
}

static zend_result king_object_store_copy_cloud_bool(
    zval *source,
    const char *field_name,
    zend_bool default_value,
    zend_bool *destination,
    char *error,
    size_t error_size)
{
    if (source == NULL) {
        *destination = default_value;
        return SUCCESS;
    }

    if (Z_TYPE_P(source) != IS_TRUE && Z_TYPE_P(source) != IS_FALSE) {
        snprintf(error, error_size, "cloud_credentials.%s must be a boolean for cloud_s3.", field_name);
        return FAILURE;
    }

    *destination = (Z_TYPE_P(source) == IS_TRUE) ? 1 : 0;
    return SUCCESS;
}

static zend_result king_object_store_s3_resolve_config(
    king_object_store_s3_runtime_config_t *config,
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
        snprintf(error, error_size, "cloud_credentials must be configured for cloud_s3.");
        return FAILURE;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        snprintf(error, error_size, "storage_root_path is required for cloud_s3 metadata sidecars.");
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("api_endpoint");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("endpoint");
    }
    if (king_object_store_copy_cloud_string(value, "api_endpoint", config->endpoint, sizeof(config->endpoint), 1, error, error_size) != SUCCESS) {
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

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("access_key"),
            "access_key",
            config->access_key,
            sizeof(config->access_key),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("secret_key"),
            "secret_key",
            config->secret_key,
            sizeof(config->secret_key),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("region"),
            "region",
            config->region,
            sizeof(config->region),
            0,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (config->region[0] == '\0') {
        snprintf(config->region, sizeof(config->region), "%s", "us-east-1");
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("session_token"),
            "session_token",
            config->session_token,
            sizeof(config->session_token),
            0,
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

static void king_object_store_url_encode_component(
    smart_str *encoded,
    const char *value)
{
    const unsigned char *cursor = (const unsigned char *) (value == NULL ? "" : value);
    char hex[4];

    while (*cursor != '\0') {
        if ((*cursor >= 'A' && *cursor <= 'Z')
            || (*cursor >= 'a' && *cursor <= 'z')
            || (*cursor >= '0' && *cursor <= '9')
            || *cursor == '-'
            || *cursor == '_'
            || *cursor == '.'
            || *cursor == '~') {
            smart_str_appendc(encoded, (char) *cursor);
        } else {
            snprintf(hex, sizeof(hex), "%%%02X", (unsigned int) *cursor);
            smart_str_appends(encoded, hex);
        }
        cursor++;
    }
}

static zend_result king_object_store_s3_build_url(
    const king_object_store_s3_runtime_config_t *config,
    const char *object_id,
    zend_bool list_bucket,
    smart_str *url,
    char *error,
    size_t error_size)
{
    const char *scheme_sep;

    if (config == NULL || url == NULL) {
        snprintf(error, error_size, "Failed to build cloud_s3 URL.");
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
            snprintf(error, error_size, "cloud_s3 endpoint must include a URL scheme.");
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
        smart_str_appends(url, "?list-type=2");
    }

    smart_str_0(url);
    return SUCCESS;
}

static zend_bool king_object_store_s3_response_error_code(
    const king_object_store_http_response_t *response,
    char *destination,
    size_t destination_size)
{
    const char *body;
    const char *code_start;
    const char *code_end;
    size_t code_len;

    if (destination != NULL && destination_size > 0) {
        destination[0] = '\0';
    }

    if (response == NULL || response->body.s == NULL || destination == NULL || destination_size == 0) {
        return 0;
    }

    body = ZSTR_VAL(response->body.s);
    code_start = strstr(body, "<Code>");
    if (code_start == NULL) {
        return 0;
    }
    code_start += strlen("<Code>");

    code_end = strstr(code_start, "</Code>");
    if (code_end == NULL || code_end <= code_start) {
        return 0;
    }

    code_len = (size_t) (code_end - code_start);
    if (code_len >= destination_size) {
        code_len = destination_size - 1;
    }

    memcpy(destination, code_start, code_len);
    destination[code_len] = '\0';
    return 1;
}

static zend_bool king_object_store_s3_response_is_throttled(
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
        || strcasecmp(error_code, "Throttling") == 0
        || strcasecmp(error_code, "ThrottlingException") == 0
        || strcasecmp(error_code, "RequestThrottled") == 0
        || strcasecmp(error_code, "TooManyRequestsException") == 0;
}

static zend_bool king_object_store_s3_response_is_quota_exhausted(
    const king_object_store_http_response_t *response)
{
    char error_code[128];

    if (response == NULL) {
        return 0;
    }

    if (response->status_code == 507) {
        return 1;
    }

    if (response->status_code != 403 && response->status_code != 409) {
        return 0;
    }

    if (!king_object_store_s3_response_error_code(response, error_code, sizeof(error_code))) {
        return 0;
    }

    return strcasecmp(error_code, "QuotaExceeded") == 0
        || strcasecmp(error_code, "StorageLimitExceeded") == 0
        || strcasecmp(error_code, "ServiceQuotaExceededException") == 0
        || strcasecmp(error_code, "InsufficientStorage") == 0;
}

static void king_object_store_s3_format_response_error(
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

    if (king_object_store_s3_response_is_quota_exhausted(response)) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_s3 %s for '%s' was rejected because the configured endpoint reported exhausted quota (HTTP %ld).",
                safe_operation,
                object_id,
                status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_s3 %s was rejected because the configured endpoint reported exhausted quota (HTTP %ld).",
                safe_operation,
                status_code
            );
        }
        return;
    }

    if (status_code == 401 || status_code == 403) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_s3 credentials were rejected during %s for '%s' (HTTP %ld).",
                safe_method,
                object_id,
                status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_s3 credentials were rejected during %s (HTTP %ld).",
                safe_operation,
                status_code
            );
        }
        return;
    }

    if (king_object_store_s3_response_is_throttled(response)) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_s3 %s for '%s' was throttled by the configured endpoint (HTTP %ld).",
                safe_operation,
                object_id,
                status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_s3 %s was throttled by the configured endpoint (HTTP %ld).",
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
            "cloud_s3 %s for '%s' returned HTTP %ld.",
            safe_method,
            object_id,
            status_code
        );
        return;
    }

    snprintf(
        error,
        error_size,
        "cloud_s3 %s returned HTTP %ld.",
        safe_operation,
        status_code
    );
}

static void king_object_store_s3_format_transport_error(
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
                "cloud_s3 %s request failed: could not resolve the configured endpoint host.",
                safe_method
            );
            return;
        case CURLE_COULDNT_CONNECT:
            snprintf(
                error,
                error_size,
                "cloud_s3 %s request failed: could not connect to the configured endpoint.",
                safe_method
            );
            return;
        case CURLE_OPERATION_TIMEDOUT:
            snprintf(
                error,
                error_size,
                "cloud_s3 %s request failed: timed out while contacting the configured endpoint.",
                safe_method
            );
            return;
        case CURLE_SEND_ERROR:
        case CURLE_RECV_ERROR:
        case CURLE_GOT_NOTHING:
            snprintf(
                error,
                error_size,
                "cloud_s3 %s request failed: network I/O with the configured endpoint failed.",
                safe_method
            );
            return;
        default:
            snprintf(
                error,
                error_size,
                "cloud_s3 %s request failed: %s",
                safe_method,
                king_object_store_libcurl.curl_easy_strerror_fn(curl_code)
            );
            return;
    }
}

static zend_result king_object_store_s3_http_request(
    const king_object_store_s3_runtime_config_t *config,
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
    char auth_mode[128];
    char userpwd[512];
    CURLcode curl_code;

    if (config == NULL || method == NULL || response == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_ensure_libcurl_ready() != SUCCESS) {
        snprintf(error, error_size, "%s", king_object_store_libcurl.load_error);
        return FAILURE;
    }

    if (king_object_store_s3_build_url(config, object_id, list_bucket, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }

    easy = king_object_store_libcurl.curl_easy_init_fn();
    if (easy == NULL) {
        smart_str_free(&url);
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

    if (body != NULL || strcmp(method, "PUT") == 0) {
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
                smart_str_free(&url);
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
            smart_str_free(&url);
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
            smart_str_free(&url);
            return header_result;
        }
    }
    if (range_header != NULL && range_header[0] != '\0') {
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, range_header);
    }

    snprintf(auth_mode, sizeof(auth_mode), "aws:amz:%s:s3", config->region);
    snprintf(userpwd, sizeof(userpwd), "%s:%s", config->access_key, config->secret_key);

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
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_USERPWD, userpwd);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_AWS_SIGV4, auth_mode);
#ifdef CURLAUTH_AWS_SIGV4
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPAUTH, (long) CURLAUTH_AWS_SIGV4);
#endif

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
            snprintf(error, error_size, "cloud_s3 response body exceeded the object-store size guard.");
        } else if (response->header_limit_exceeded) {
            snprintf(error, error_size, "cloud_s3 response headers exceeded the object-store size guard.");
        } else {
            king_object_store_s3_format_transport_error(method, curl_code, error, error_size);
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
