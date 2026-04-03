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

