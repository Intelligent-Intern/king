/*
 * =========================================================================
 * FILENAME:   src/client/http2/request/dispatch/configure.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * libcurl easy-handle configuration for the active HTTP/2 dispatch path.
 * This file applies the runtime HTTP version, transport and timeout knobs,
 * request headers and body wiring, and the optional TLS verification and
 * client-certificate settings inherited from the current config snapshot.
 * =========================================================================
 */

static bool king_http2_has_path_option(const char *value)
{
    return value != NULL && value[0] != '\0';
}

static zend_result king_http2_request_configure_tls_handle(
    CURL *easy_handle,
    const king_http2_request_options_t *options,
    const char *function_name
)
{
    if (king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_SSL_ENABLE_ALPN, 1L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(
            easy_handle,
            CURLOPT_SSL_VERIFYPEER,
            options->tls_verify_peer ? 1L : 0L
        ) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(
            easy_handle,
            CURLOPT_SSL_VERIFYHOST,
            options->tls_verify_peer ? 2L : 0L
        ) != CURLE_OK) {
        king_http2_throw(
            king_ce_tls_exception,
            "%s() failed to configure libcurl TLS verification for the active HTTP/2 handle.",
            function_name
        );
        return FAILURE;
    }

    if (king_http2_has_path_option(options->tls_default_ca_file)
        && king_http2_libcurl.curl_easy_setopt_fn(
            easy_handle,
            CURLOPT_CAINFO,
            options->tls_default_ca_file
        ) != CURLE_OK) {
        king_http2_throw(
            king_ce_tls_exception,
            "%s() failed to configure the HTTP/2 TLS CA file.",
            function_name
        );
        return FAILURE;
    }

    if (king_http2_has_path_option(options->tls_default_cert_file)
        && king_http2_libcurl.curl_easy_setopt_fn(
            easy_handle,
            CURLOPT_SSLCERT,
            options->tls_default_cert_file
        ) != CURLE_OK) {
        king_http2_throw(
            king_ce_tls_exception,
            "%s() failed to configure the HTTP/2 client certificate.",
            function_name
        );
        return FAILURE;
    }

    if (king_http2_has_path_option(options->tls_default_key_file)
        && king_http2_libcurl.curl_easy_setopt_fn(
            easy_handle,
            CURLOPT_SSLKEY,
            options->tls_default_key_file
        ) != CURLE_OK) {
        king_http2_throw(
            king_ce_tls_exception,
            "%s() failed to configure the HTTP/2 client key.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_http2_request_configure_handle(
    CURL *easy_handle,
    const char *url_str,
    const char *method_str,
    bool secure_transport,
    const king_http2_request_options_t *options,
    struct curl_slist *request_headers,
    bool body_was_supplied,
    zend_string *body_string,
    king_http2_response_buffer_t *response,
    const char *function_name
)
{
    king_http2_libcurl.curl_easy_reset_fn(easy_handle);

    if (king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_URL, url_str) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_CUSTOMREQUEST, method_str) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(
            easy_handle,
            CURLOPT_HTTP_VERSION,
            (long) (secure_transport ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE)
        ) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_NOSIGNAL, 1L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_NOPROGRESS, 1L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_PIPEWAIT, 1L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_FORBID_REUSE, 0L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_FRESH_CONNECT, 0L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_CONNECTTIMEOUT_MS, options->connect_timeout_ms) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_TIMEOUT_MS, options->timeout_ms) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_TCP_KEEPALIVE, options->tcp_keepalive_enable ? 1L : 0L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_TCP_NODELAY, options->tcp_nodelay_enable ? 1L : 0L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_WRITEFUNCTION, king_http2_write_callback) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_WRITEDATA, response) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_HEADERFUNCTION, king_http2_header_callback) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_HEADERDATA, response) != CURLE_OK) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() failed to configure the active libcurl HTTP/2 handle.",
            function_name
        );
        return FAILURE;
    }

    if (secure_transport
        && king_http2_request_configure_tls_handle(easy_handle, options, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (request_headers != NULL
        && king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_HTTPHEADER, request_headers) != CURLE_OK) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() failed to attach HTTP/2 request headers to libcurl.",
            function_name
        );
        return FAILURE;
    }

    if (body_was_supplied) {
        if (king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_POSTFIELDS, ZSTR_VAL(body_string)) != CURLE_OK
            || king_http2_libcurl.curl_easy_setopt_fn(
                easy_handle,
                CURLOPT_POSTFIELDSIZE_LARGE,
                (curl_off_t) ZSTR_LEN(body_string)
            ) != CURLE_OK) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() failed to configure the HTTP/2 request body.",
                function_name
            );
            return FAILURE;
        }
    } else if (strcasecmp(method_str, "HEAD") == 0) {
        if (king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_NOBODY, 1L) != CURLE_OK) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() failed to configure the HTTP/2 HEAD request.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}
