/*
 * CDN HTTP-origin readthrough helper. This keeps full-object origin fallback
 * honest with one bounded GET attempt, one bounded response body, and without
 * replaying hidden retries.
 */

typedef struct _king_object_store_cdn_origin_http_stream_sink_t {
    king_object_store_http_response_t *response;
    FILE *stream;
} king_object_store_cdn_origin_http_stream_sink_t;

static const char *king_object_store_cdn_origin_http_effective_endpoint(void)
{
    if (king_object_store_runtime.config.cdn_config.origin_http_endpoint[0] != '\0') {
        return king_object_store_runtime.config.cdn_config.origin_http_endpoint;
    }

    if (king_native_cdn_config.origin_http_endpoint != NULL
        && king_native_cdn_config.origin_http_endpoint[0] != '\0') {
        return king_native_cdn_config.origin_http_endpoint;
    }

    return NULL;
}

static long king_object_store_cdn_origin_http_effective_timeout_ms(void)
{
    if (king_object_store_runtime.config.cdn_config.origin_request_timeout_ms > 0) {
        return (long) king_object_store_runtime.config.cdn_config.origin_request_timeout_ms;
    }

    if (king_native_cdn_config.origin_request_timeout_ms > 0) {
        return (long) king_native_cdn_config.origin_request_timeout_ms;
    }

    return KING_OBJECT_STORE_HTTP_TIMEOUT_MS;
}

static zend_result king_object_store_cdn_origin_http_build_url(
    const char *object_id,
    smart_str *url,
    char *error,
    size_t error_size
)
{
    const char *endpoint;
    size_t endpoint_len;
    zend_string *encoded_object_id;

    if (object_id == NULL || url == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    endpoint = king_object_store_cdn_origin_http_effective_endpoint();
    if (endpoint == NULL || endpoint[0] == '\0') {
        snprintf(error, error_size, "CDN HTTP origin endpoint is not configured.");
        return FAILURE;
    }

    endpoint_len = strlen(endpoint);
    encoded_object_id = php_raw_url_encode(object_id, strlen(object_id));
    if (encoded_object_id == NULL) {
        snprintf(error, error_size, "Failed to encode object id for the CDN HTTP origin request.");
        return FAILURE;
    }

    smart_str_appends(url, endpoint);
    if (endpoint_len == 0 || endpoint[endpoint_len - 1] != '/') {
        smart_str_appendc(url, '/');
    }
    smart_str_append(url, encoded_object_id);
    smart_str_0(url);
    zend_string_release_ex(encoded_object_id, 0);

    return SUCCESS;
}

static void king_object_store_cdn_origin_http_format_status_error(
    const char *object_id,
    long status_code,
    char *error,
    size_t error_size
)
{
    snprintf(
        error,
        error_size,
        "CDN HTTP origin read for '%s' returned HTTP " ZEND_LONG_FMT ".",
        object_id != NULL ? object_id : "",
        (zend_long) status_code
    );
}

static void king_object_store_cdn_origin_http_format_transport_error(
    const char *object_id,
    CURLcode curl_code,
    long timeout_ms,
    char *error,
    size_t error_size
)
{
    if (curl_code == CURLE_OPERATION_TIMEDOUT) {
        snprintf(
            error,
            error_size,
            "CDN HTTP origin read for '%s' timed out after " ZEND_LONG_FMT " ms.",
            object_id != NULL ? object_id : "",
            (zend_long) timeout_ms
        );
        return;
    }

    snprintf(
        error,
        error_size,
        "CDN HTTP origin read for '%s' failed: %s.",
        object_id != NULL ? object_id : "",
        king_object_store_libcurl.curl_easy_strerror_fn != NULL
            ? king_object_store_libcurl.curl_easy_strerror_fn(curl_code)
            : "libcurl transport failure"
    );
}

static size_t king_object_store_cdn_origin_http_stream_write_callback(
    char *contents,
    size_t size,
    size_t nmemb,
    void *userdata
)
{
    king_object_store_cdn_origin_http_stream_sink_t *sink =
        (king_object_store_cdn_origin_http_stream_sink_t *) userdata;
    size_t bytes = size * nmemb;
    size_t written;

    if (sink == NULL || sink->response == NULL || sink->stream == NULL) {
        return 0;
    }

    if (sink->response->body_bytes + bytes > KING_OBJECT_STORE_HTTP_MAX_BODY_BYTES) {
        sink->response->body_limit_exceeded = 1;
        return 0;
    }

    written = fwrite(contents, 1, bytes, sink->stream);
    sink->response->body_bytes += written;

    return written;
}

static int king_object_store_cdn_origin_http_request(
    const char *object_id,
    king_object_store_http_response_t *response,
    char *error,
    size_t error_size,
    FILE *response_body_sink
)
{
    CURL *easy = NULL;
    struct curl_slist *headers = NULL;
    smart_str url = {0};
    king_object_store_cdn_origin_http_stream_sink_t stream_sink = {0};
    CURLcode curl_code;
    long timeout_ms;

    if (object_id == NULL || response == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_ensure_libcurl_ready() != SUCCESS) {
        snprintf(error, error_size, "%s", king_object_store_libcurl.load_error);
        return FAILURE;
    }

    if (king_object_store_cdn_origin_http_build_url(object_id, &url, error, error_size) != SUCCESS) {
        smart_str_free(&url);
        return FAILURE;
    }

    easy = king_object_store_libcurl.curl_easy_init_fn();
    if (easy == NULL) {
        smart_str_free(&url);
        snprintf(error, error_size, "Failed to create libcurl handle for the CDN HTTP origin request.");
        return FAILURE;
    }

    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Accept: */*");
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, "Expect:");
    timeout_ms = king_object_store_cdn_origin_http_effective_timeout_ms();

    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_URL, ZSTR_VAL(url.s));
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_NOSIGNAL, 1L);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_TIMEOUT_MS, (zend_long) timeout_ms);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_USERAGENT, "king-cdn-origin/1");
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPHEADER, headers);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPGET, 1L);
    if (response_body_sink != NULL) {
        stream_sink.response = response;
        stream_sink.stream = response_body_sink;
        king_object_store_libcurl.curl_easy_setopt_fn(
            easy,
            CURLOPT_WRITEFUNCTION,
            king_object_store_cdn_origin_http_stream_write_callback
        );
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEDATA, &stream_sink);
    } else {
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEFUNCTION, king_object_store_http_write_callback);
        king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEDATA, response);
    }
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HEADERFUNCTION, king_object_store_http_header_callback);
    king_object_store_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HEADERDATA, response);

    curl_code = king_object_store_libcurl.curl_easy_perform_fn(easy);
    king_object_store_libcurl.curl_easy_getinfo_fn(easy, CURLINFO_RESPONSE_CODE, &response->status_code);
    smart_str_0(&response->body);
    smart_str_0(&response->headers);

    if (curl_code != CURLE_OK) {
        if (!response->body_limit_exceeded
            && !response->header_limit_exceeded
            && (zend_long) response->status_code >= 400
            && (zend_long) response->status_code < 600) {
            king_object_store_libcurl.curl_slist_free_all_fn(headers);
            king_object_store_libcurl.curl_easy_cleanup_fn(easy);
            smart_str_free(&url);
            return SUCCESS;
        }

        if (response->body_limit_exceeded) {
            snprintf(error, error_size, "CDN HTTP origin response body exceeded the object-store size guard.");
        } else if (response->header_limit_exceeded) {
            snprintf(error, error_size, "CDN HTTP origin response headers exceeded the object-store size guard.");
        } else {
            king_object_store_cdn_origin_http_format_transport_error(
                object_id,
                curl_code,
                (zend_long) timeout_ms,
                error,
                error_size
            );
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

int king_object_store_cdn_origin_http_read(
    const char *object_id,
    void **data,
    size_t *data_size,
    char *error,
    size_t error_size
)
{
    king_object_store_http_response_t response;
    void *copy;
    int rc;

    if (data == NULL || data_size == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    king_object_store_http_response_init(&response);
    rc = king_object_store_cdn_origin_http_request(
        object_id,
        &response,
        error,
        error_size,
        NULL
    );
    if (rc != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return rc;
    }

    if (response.status_code == 404) {
        error[0] = '\0';
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_cdn_origin_http_format_status_error(
            object_id,
            response.status_code,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    copy = pemalloc(response.body_bytes + 1, 1);
    if (copy == NULL) {
        king_object_store_http_response_destroy(&response);
        snprintf(error, error_size, "Failed to allocate the CDN HTTP origin response buffer.");
        return FAILURE;
    }

    if (response.body_bytes > 0 && response.body.s != NULL) {
        memcpy(copy, ZSTR_VAL(response.body.s), response.body_bytes);
    }
    ((char *) copy)[response.body_bytes] = '\0';

    *data = copy;
    *data_size = response.body_bytes;
    error[0] = '\0';
    king_object_store_http_response_destroy(&response);

    return SUCCESS;
}

int king_object_store_cdn_origin_http_read_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    char *error,
    size_t error_size
)
{
    king_object_store_http_response_t response;
    char temp_path[1024];
    FILE *destination_fp = NULL;
    int rc;

    if (destination_stream == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
        snprintf(error, error_size, "Failed to allocate a bounded local sink for the CDN HTTP origin read.");
        return FAILURE;
    }

    destination_fp = fopen(temp_path, "w+b");
    if (destination_fp == NULL) {
        snprintf(error, error_size, "Failed to open the bounded local sink for the CDN HTTP origin read.");
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    rc = king_object_store_cdn_origin_http_request(
        object_id,
        &response,
        error,
        error_size,
        destination_fp
    );
    fclose(destination_fp);

    if (rc != SUCCESS) {
        unlink(temp_path);
        king_object_store_http_response_destroy(&response);
        return rc;
    }

    if (response.status_code == 404) {
        error[0] = '\0';
        unlink(temp_path);
        king_object_store_http_response_destroy(&response);
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_cdn_origin_http_format_status_error(
            object_id,
            response.status_code,
            error,
            error_size
        );
        unlink(temp_path);
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
    unlink(temp_path);
    king_object_store_http_response_destroy(&response);

    if (rc != SUCCESS) {
        snprintf(error, error_size, "Failed to write the CDN HTTP origin payload into the destination stream.");
        return FAILURE;
    }

    error[0] = '\0';
    return SUCCESS;
}
