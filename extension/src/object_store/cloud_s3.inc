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
            "Failed to load libcurl symbol '%s' for cloud_s3 object-store operations.",
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
            "Failed to load libcurl.so.4 or libcurl.so for cloud_s3 object-store operations."
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
            "curl_global_init() failed for cloud_s3 object-store operations."
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
    king_object_store_http_response_t *response,
    char *error,
    size_t error_size)
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
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Content-Type: application/octet-stream");
    }

    snprintf(auth_mode, sizeof(auth_mode), "aws:amz:%s:s3", config->region);
    snprintf(userpwd, sizeof(userpwd), "%s:%s", config->access_key, config->secret_key);

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
    if (king_object_store_http_header_get(headers, "x-amz-meta-content-length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
    } else if (king_object_store_http_header_get(headers, "Content-Length", value, sizeof(value))) {
        metadata->content_length = (uint64_t) strtoull(value, NULL, 10);
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

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
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

static int king_object_store_s3_read_with_error(
    const char *object_id,
    void **data,
    size_t *data_size,
    char *error,
    size_t error_size)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    void *copy;

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
            &response,
            error,
            error_size
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code == 404) {
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

    if (response.status_code < 200 || response.status_code >= 300) {
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
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error)
    );
}

static int king_object_store_s3_remove(const char *object_id)
{
    king_object_store_s3_runtime_config_t config;
    king_object_store_http_response_t response;
    zend_bool exists = 0;
    king_object_metadata_t existing_metadata;

    if (king_object_store_s3_resolve_config(&config, king_object_store_runtime.primary_adapter_error, sizeof(king_object_store_runtime.primary_adapter_error)) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_s3_head_object(
            object_id,
            &exists,
            &existing_metadata,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (!exists) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_s3_http_request(
            &config,
            "DELETE",
            object_id,
            0,
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
        king_object_store_s3_format_response_error(
            "DELETE",
            object_id,
            "delete",
            &response,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (king_object_store_runtime.current_stored_bytes >= existing_metadata.content_length) {
        king_object_store_runtime.current_stored_bytes -= existing_metadata.content_length;
    }
    if (king_object_store_runtime.current_object_count > 0) {
        king_object_store_runtime.current_object_count--;
    }

    king_object_store_meta_remove(object_id);
    king_object_store_http_response_destroy(&response);
    return SUCCESS;
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

            array_init(&exported_entry);
            add_assoc_string(&exported_entry, "object_id", key);
            add_assoc_long(&exported_entry, "size_bytes", (zend_long) size_bytes);
            add_assoc_long(&exported_entry, "stored_at", (zend_long) stored_at);
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
            &response,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
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
            &response,
            error,
            error_size
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

    if (metadata == NULL || object_id == NULL) {
        return FAILURE;
    }

    if (king_object_store_meta_read(object_id, metadata) == SUCCESS) {
        return SUCCESS;
    }

    if (king_object_store_runtime.config.primary_backend != KING_STORAGE_BACKEND_CLOUD_S3) {
        return FAILURE;
    }

    if (king_object_store_s3_head_object(object_id, &exists, metadata, error, sizeof(error)) != SUCCESS || !exists) {
        return FAILURE;
    }

    (void) king_object_store_meta_write(object_id, metadata);
    return SUCCESS;
}
