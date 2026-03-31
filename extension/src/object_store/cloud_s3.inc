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
        char content_type_header[160];

        if (request_metadata != NULL && request_metadata->content_type[0] != '\0') {
            snprintf(
                content_type_header,
                sizeof(content_type_header),
                "Content-Type: %s",
                request_metadata->content_type
            );
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, content_type_header);
        } else {
            headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Content-Type: application/octet-stream");
        }
    }
    if (request_metadata != NULL && request_metadata->content_encoding[0] != '\0') {
        char content_encoding_header[128];

        snprintf(
            content_encoding_header,
            sizeof(content_encoding_header),
            "Content-Encoding: %s",
            request_metadata->content_encoding
        );
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, content_encoding_header);
    }
    if (request_metadata != NULL) {
        headers = king_object_store_append_metadata_headers(headers, "x-amz-meta-", request_metadata);
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
        headers = king_object_store_append_metadata_headers(headers, "x-amz-meta-", request_metadata);
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

static int king_object_store_s3_rehydrate_stats(char *error, size_t error_size);

static int king_object_store_s3_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    king_object_metadata_t final_metadata;
    const king_object_metadata_t *metadata_source = metadata;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;
    uint64_t old_size = 0;

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata_source != NULL) {
        final_metadata = *metadata_source;
    }
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_CLOUD_S3,
        1
    );
    /*
     * Do not silently clear a source local_fs presence marker here.
     * Export/restore migration and local_fs-primary backup copies can
     * legitimately leave both real backends populated at the same time.
     */

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_s3_head_object(
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
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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
            "cloud_s3 write for '%s' succeeded but local metadata sidecar write failed.",
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

static int king_object_store_s3_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    king_object_metadata_t final_metadata;
    const king_object_metadata_t *metadata_source = metadata;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;
    uint64_t old_size = 0;
    uint64_t source_size = 0;
    char computed_sha256[65];
    FILE *source_fp = NULL;

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_compute_sha256_hex_for_path(source_path, computed_sha256, &source_size) != SUCCESS) {
        snprintf(error, error_size, "cloud_s3 could not hash staged upload content for '%s'.", object_id);
        return FAILURE;
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata_source != NULL) {
        final_metadata = *metadata_source;
    }
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_CLOUD_S3,
        1
    );

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_s3_head_object(
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
        snprintf(error, error_size, "cloud_s3 could not open the staged upload for '%s'.", object_id);
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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
            "cloud_s3 write for '%s' succeeded but local metadata sidecar write failed.",
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

static int king_object_store_s3_read_with_error(
    const char *object_id,
    void **data,
    size_t *data_size,
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *error,
    size_t error_size)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    void *copy;
    char range_header[96];

    if (data == NULL || data_size == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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
            "cloud_s3 range read for '%s' requested range starts past the end of the object.",
            object_id
        );
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (response.status_code < 200 || response.status_code >= 300 || ((offset > 0 || has_length) && response.status_code != 206)) {
        king_object_store_s3_format_response_error(
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

static int king_object_store_s3_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_s3_read_with_error(
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

static int king_object_store_s3_read_range(
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

    rc = king_object_store_s3_read_with_error(
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

    (void) has_length;
    return SUCCESS;
}

static int king_object_store_s3_read_to_path_with_error(
    const char *object_id,
    const char *destination_path,
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *error,
    size_t error_size
)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    char range_header[96];
    FILE *destination_fp = NULL;

    if (destination_path == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    destination_fp = fopen(destination_path, "w+b");
    if (destination_fp == NULL) {
        snprintf(error, error_size, "cloud_s3 could not open the bounded-memory download sink for '%s'.", object_id);
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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
            "cloud_s3 range read for '%s' requested range starts past the end of the object.",
            object_id
        );
        unlink(destination_path);
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (response.status_code < 200 || response.status_code >= 300 || ((offset > 0 || has_length) && response.status_code != 206)) {
        king_object_store_s3_format_response_error(
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

static int king_object_store_s3_delete_with_error(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool update_counters,
    zend_bool remove_sidecar,
    zend_bool allow_missing,
    zend_bool *removed_out)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;

    if (removed_out != NULL) {
        *removed_out = 0;
    }

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_s3_head_object(
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
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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

static int king_object_store_s3_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out)
{
    return king_object_store_s3_delete_with_error(
        object_id,
        error,
        error_size,
        0,
        0,
        1,
        removed_out
    );
}

static int king_object_store_s3_remove(const char *object_id)
{
    return king_object_store_s3_delete_with_error(
        object_id,
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error),
        1,
        1,
        0,
        NULL
    );
}

static zend_result king_object_store_s3_parse_list_xml(
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
    while ((cursor = strstr(cursor, "<Contents>")) != NULL) {
        const char *entry_end = strstr(cursor, "</Contents>");
        const char *key_start;
        const char *key_end;
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

        key_start = strstr(cursor, "<Key>");
        key_end = key_start != NULL ? strstr(key_start, "</Key>") : NULL;
        size_start = strstr(cursor, "<Size>");
        size_end = size_start != NULL ? strstr(size_start, "</Size>") : NULL;
        modified_start = strstr(cursor, "<LastModified>");
        modified_end = modified_start != NULL ? strstr(modified_start, "</LastModified>") : NULL;

        if (key_start == NULL || key_end == NULL || size_start == NULL || size_end == NULL) {
            return FAILURE;
        }

        key_start += strlen("<Key>");
        size_start += strlen("<Size>");
        if (modified_start != NULL) {
            modified_start += strlen("<LastModified>");
        }

        if ((size_t) (key_end - key_start) >= sizeof(key) || (size_t) (size_end - size_start) >= sizeof(size_value)) {
            return FAILURE;
        }

        memcpy(key, key_start, (size_t) (key_end - key_start));
        key[key_end - key_start] = '\0';
        memcpy(size_value, size_start, (size_t) (size_end - size_start));
        size_value[size_end - size_start] = '\0';

        if (modified_start != NULL && modified_end != NULL && modified_end > modified_start) {
            size_t modified_len = (size_t) (modified_end - modified_start);
            if (modified_len >= sizeof(modified_value)) {
                return FAILURE;
            }
            memcpy(modified_value, modified_start, modified_len);
            modified_value[modified_len] = '\0';
            stored_at = king_object_store_parse_iso8601_utc(modified_value);
        }

        size_bytes = (uint64_t) strtoull(size_value, NULL, 10);

        if (return_array != NULL) {
            zval exported_entry;
            king_object_metadata_t metadata;
            king_object_metadata_t *metadata_ptr = NULL;

            if (king_object_store_backend_read_metadata(key, &metadata) == SUCCESS) {
                if (king_object_store_metadata_is_expired_now(&metadata)) {
                    cursor = entry_end + strlen("</Contents>");
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

        cursor = entry_end + strlen("</Contents>");
    }

    return SUCCESS;
}

static int king_object_store_s3_list(zval *return_array)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;

    if (king_object_store_s3_resolve_config(&config, king_object_store_runtime.primary_adapter_error, sizeof(king_object_store_runtime.primary_adapter_error)) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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
            "Failed to parse cloud_s3 inventory XML."
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

static int king_object_store_s3_rehydrate_stats(char *error, size_t error_size)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    uint64_t object_count = 0;
    uint64_t stored_bytes = 0;
    time_t latest_object_at = 0;

    if (king_object_store_s3_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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
        king_object_store_s3_format_response_error(
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
        snprintf(error, error_size, "Failed to parse cloud_s3 inventory XML during rehydrate.");
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_runtime.current_object_count = object_count;
    king_object_store_runtime.current_stored_bytes = stored_bytes;
    king_object_store_runtime.latest_object_at = latest_object_at;

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}

int king_object_store_backend_read_metadata(
    const char *object_id,
    king_object_metadata_t *metadata)
{
    char error[256];
    zend_bool exists = 0;
    king_storage_backend_t active_backend;
    char payload_path[1024];
    struct stat payload_stat;
    struct stat root_stat;

    if (metadata == NULL || object_id == NULL) {
        return FAILURE;
    }

    error[0] = '\0';

    active_backend = king_object_store_runtime.config.primary_backend;
    if (active_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        active_backend = KING_STORAGE_BACKEND_LOCAL_FS;
    }

    if ((active_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || active_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && (king_object_store_runtime.config.storage_root_path[0] == '\0'
            || stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0
            || !S_ISDIR(root_stat.st_mode))) {
        snprintf(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "local_fs metadata read failed: %s",
            king_object_store_runtime.config.storage_root_path[0] == '\0'
                ? "the configured storage_root_path is empty."
                : (
                    stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0
                        ? strerror(errno)
                        : "the configured storage_root_path is not a directory."
                )
        );
        return FAILURE;
    }
    if (active_backend == KING_STORAGE_BACKEND_DISTRIBUTED
        && !king_object_store_distributed_root_available(
            "metadata read",
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        )) {
        return FAILURE;
    }

    if (king_object_store_meta_read(object_id, metadata) == SUCCESS) {
        if (king_object_store_metadata_matches_backend(object_id, metadata, active_backend)) {
            return SUCCESS;
        }

        if (active_backend != KING_STORAGE_BACKEND_CLOUD_S3
            && active_backend != KING_STORAGE_BACKEND_CLOUD_GCS
            && active_backend != KING_STORAGE_BACKEND_CLOUD_AZURE) {
            if (active_backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
                if (king_object_store_build_distributed_path(payload_path, sizeof(payload_path), object_id) != SUCCESS) {
                    return FAILURE;
                }
            } else {
                king_object_store_build_path(payload_path, sizeof(payload_path), object_id);
            }
            if (stat(payload_path, &payload_stat) == 0 && S_ISREG(payload_stat.st_mode)) {
                snprintf(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "%s metadata for '%s' is unavailable even though the payload still exists.",
                    active_backend == KING_STORAGE_BACKEND_DISTRIBUTED ? "distributed" : "local_fs",
                    object_id
                );
                return FAILURE;
            }
            return KING_OBJECT_STORE_RESULT_MISS;
        }
    }

    /*
     * For local_fs primary plus cloud_s3 backup, the process-local metadata
     * cache is the live bridge during a temporary primary-root outage. It is
     * not a restart contract; init_system repopulates it only from real
     * durable sidecars.
     */
    if ((king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && king_object_store_runtime.config.backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
        && king_object_store_runtime_metadata_cache_read(object_id, metadata) == SUCCESS
        && king_object_store_metadata_matches_backend(
            object_id,
            metadata,
            KING_STORAGE_BACKEND_LOCAL_FS
        )) {
        return SUCCESS;
    }

    if (active_backend == KING_STORAGE_BACKEND_CLOUD_S3) {
        if (king_object_store_s3_head_object(object_id, &exists, metadata, error, sizeof(error)) != SUCCESS) {
            snprintf(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "%s",
                error
            );
            return FAILURE;
        }
        if (!exists) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }

        king_object_store_metadata_mark_backend_present(
            metadata,
            KING_STORAGE_BACKEND_CLOUD_S3,
            1
        );
        (void) king_object_store_meta_write(object_id, metadata);
        return SUCCESS;
    }

    if (active_backend == KING_STORAGE_BACKEND_CLOUD_GCS) {
        if (king_object_store_gcs_head_object(object_id, &exists, metadata, error, sizeof(error)) != SUCCESS) {
            snprintf(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "%s",
                error
            );
            return FAILURE;
        }
        if (!exists) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }

        king_object_store_metadata_mark_backend_present(
            metadata,
            KING_STORAGE_BACKEND_CLOUD_GCS,
            1
        );
        (void) king_object_store_meta_write(object_id, metadata);
        return SUCCESS;
    }

    if (active_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        if (king_object_store_azure_head_object(object_id, &exists, metadata, error, sizeof(error)) != SUCCESS) {
            snprintf(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "%s",
                error
            );
            return FAILURE;
        }
        if (!exists) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }

        king_object_store_metadata_mark_backend_present(
            metadata,
            KING_STORAGE_BACKEND_CLOUD_AZURE,
            1
        );
        (void) king_object_store_meta_write(object_id, metadata);
        return SUCCESS;
    }

    if (active_backend != KING_STORAGE_BACKEND_CLOUD_S3
        && active_backend != KING_STORAGE_BACKEND_CLOUD_GCS
        && active_backend != KING_STORAGE_BACKEND_CLOUD_AZURE) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    return FAILURE;
}
