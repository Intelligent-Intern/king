/*
 * =========================================================================
 * FILENAME:   src/client/http2/response.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Shared HTTP/2 response callbacks and protocol mapping helpers for the
 * libcurl-backed client path. This file owns bounded header/body capture,
 * status-line and header normalization, curl error-class translation, and
 * the protocol labels persisted into the response snapshot.
 * =========================================================================
 */

static size_t king_http2_write_callback(
    char *ptr,
    size_t size,
    size_t nmemb,
    void *userdata)
{
    king_http2_response_buffer_t *response = (king_http2_response_buffer_t *) userdata;
    size_t total = size * nmemb;

    if (total == 0) {
        return 0;
    }

    if (response->body_bytes + total > KING_HTTP2_MAX_RESPONSE_BYTES) {
        response->body_overflowed = true;
        return 0;
    }

    smart_str_appendl(&response->body, ptr, total);
    response->body_bytes += total;

    return total;
}

static size_t king_http2_header_callback(
    char *ptr,
    size_t size,
    size_t nmemb,
    void *userdata)
{
    king_http2_response_buffer_t *response = (king_http2_response_buffer_t *) userdata;
    size_t total = size * nmemb;
    char *separator;
    size_t line_len;

    if (response->header_bytes + total > KING_HTTP2_MAX_HEADER_BYTES) {
        response->header_overflowed = true;
        return 0;
    }

    response->header_bytes += total;

    line_len = total;
    while (line_len > 0 && (ptr[line_len - 1] == '\r' || ptr[line_len - 1] == '\n')) {
        line_len--;
    }

    if (line_len == 0) {
        return total;
    }

    if (line_len >= 5 && strncasecmp(ptr, "HTTP/", 5) == 0) {
        if (response->status_line != NULL) {
            zend_string_release(response->status_line);
        }
        response->status_line = zend_string_init(ptr, line_len, 0);

        if (response->headers_initialized) {
            zval_ptr_dtor(&response->headers);
            response->headers_initialized = false;
        }
        array_init(&response->headers);
        response->headers_initialized = true;
        return total;
    }

    separator = memchr(ptr, ':', line_len);
    if (separator != NULL) {
        zend_string *header_name;
        const char *value_ptr;
        size_t value_len;

        if (!response->headers_initialized) {
            array_init(&response->headers);
            response->headers_initialized = true;
        }

        header_name = zend_string_init(ptr, (size_t) (separator - ptr), 0);
        zend_str_tolower(ZSTR_VAL(header_name), ZSTR_LEN(header_name));

        value_ptr = separator + 1;
        while ((size_t) (value_ptr - ptr) < line_len && (*value_ptr == ' ' || *value_ptr == '\t')) {
            value_ptr++;
        }

        value_len = line_len - (size_t) (value_ptr - ptr);
        add_assoc_stringl_ex(
            &response->headers,
            ZSTR_VAL(header_name),
            ZSTR_LEN(header_name),
            value_ptr,
            value_len
        );
        zend_string_release(header_name);
    }

    return total;
}

static zend_class_entry *king_http2_map_curl_exception(CURLcode code)
{
    switch (code) {
        case CURLE_OPERATION_TIMEDOUT:
            return king_ce_timeout_exception;

        case CURLE_COULDNT_RESOLVE_HOST:
        case CURLE_COULDNT_CONNECT:
        case CURLE_SEND_ERROR:
        case CURLE_RECV_ERROR:
        case CURLE_GOT_NOTHING:
            return king_ce_network_exception;

        case CURLE_UNSUPPORTED_PROTOCOL:
        case CURLE_HTTP2:
        case CURLE_HTTP2_STREAM:
            return king_ce_protocol_exception;

        case CURLE_SSL_CONNECT_ERROR:
        case CURLE_PEER_FAILED_VERIFICATION:
        case CURLE_SSL_CERTPROBLEM:
        case CURLE_SSL_CACERT_BADFILE:
            return king_ce_tls_exception;

#ifdef CURLE_SSL_CIPHER
        case CURLE_SSL_CIPHER:
            return king_ce_tls_exception;
#endif

#ifdef CURLE_SSL_ISSUER_ERROR
        case CURLE_SSL_ISSUER_ERROR:
            return king_ce_tls_exception;
#endif

#ifdef CURLE_SSL_INVALIDCERTSTATUS
        case CURLE_SSL_INVALIDCERTSTATUS:
            return king_ce_tls_exception;
#endif

        default:
            return king_ce_network_exception;
    }
}

static const char *king_http2_protocol_label(long curl_http_version)
{
    switch (curl_http_version) {
        case CURL_HTTP_VERSION_1_0:
            return "http/1.0";
        case CURL_HTTP_VERSION_1_1:
            return "http/1.1";
        case CURL_HTTP_VERSION_2_0:
        case CURL_HTTP_VERSION_2TLS:
        case CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE:
            return "http/2";
        case CURL_HTTP_VERSION_3:
            return "http/3";
        default:
            return "unknown";
    }
}
