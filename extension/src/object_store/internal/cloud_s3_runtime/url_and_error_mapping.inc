/*
 * Percent-encode a single URL path component and append it to a smart_str.
 *
 * This helper performs RFC 3986-style percent-encoding suitable for path
 * components. Unreserved characters stay unchanged, while every other byte is
 * emitted as uppercase `%HH`. A NULL input is treated as an empty string.
 */
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

    if (config->path_style) {
        smart_str_appends(url, config->endpoint);
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

    if ((zend_long) response->status_code == 429) {
        return 1;
    }

    if ((zend_long) response->status_code != 503) {
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

    if ((zend_long) response->status_code == 507) {
        return 1;
    }

    if ((zend_long) response->status_code != 403 && (zend_long) response->status_code != 409) {
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
                "cloud_s3 %s for '%s' was rejected because the configured endpoint reported exhausted quota (HTTP " ZEND_LONG_FMT ").",
                safe_operation,
                object_id,
                (zend_long) status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_s3 %s was rejected because the configured endpoint reported exhausted quota (HTTP " ZEND_LONG_FMT ").",
                safe_operation,
                (zend_long) status_code
            );
        }
        return;
    }

    if (status_code == 401 || status_code == 403) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_s3 credentials were rejected during %s for '%s' (HTTP " ZEND_LONG_FMT ").",
                safe_method,
                object_id,
                (zend_long) status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_s3 credentials were rejected during %s (HTTP " ZEND_LONG_FMT ").",
                safe_operation,
                (zend_long) status_code
            );
        }
        return;
    }

    if (king_object_store_s3_response_is_throttled(response)) {
        if (object_id != NULL && object_id[0] != '\0') {
            snprintf(
                error,
                error_size,
                "cloud_s3 %s for '%s' was throttled by the configured endpoint (HTTP " ZEND_LONG_FMT ").",
                safe_operation,
                object_id,
                (zend_long) status_code
            );
        } else {
            snprintf(
                error,
                error_size,
                "cloud_s3 %s was throttled by the configured endpoint (HTTP " ZEND_LONG_FMT ").",
                safe_operation,
                (zend_long) status_code
            );
        }
        return;
    }

    if (object_id != NULL && object_id[0] != '\0') {
        snprintf(
            error,
            error_size,
            "cloud_s3 %s for '%s' returned HTTP " ZEND_LONG_FMT ".",
            safe_method,
            object_id,
            (zend_long) status_code
        );
        return;
    }

    snprintf(
        error,
        error_size,
        "cloud_s3 %s returned HTTP " ZEND_LONG_FMT ".",
        safe_operation,
        (zend_long) status_code
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
