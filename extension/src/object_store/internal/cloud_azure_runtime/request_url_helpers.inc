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
            "x-ms-meta-",
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
