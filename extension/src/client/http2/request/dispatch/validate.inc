/*
 * =========================================================================
 * FILENAME:   src/client/http2/request/dispatch/validate.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Request-target validation for the libcurl-backed HTTP/2 one-shot path.
 * This file constrains the public surface to absolute `http://` and
 * `https://` URLs, rejects embedded credentials, and hands back the parsed
 * URL object consumed by the later dispatch/configuration slices.
 * =========================================================================
 */

static zend_result king_http2_request_parse_url(
    const char *url_str,
    size_t url_len,
    const char *function_name,
    php_url **parsed_url_out
)
{
    php_url *parsed_url;

    if (url_len == 0) {
        king_http2_set_error("%s() requires a non-empty URL.", function_name);
        return FAILURE;
    }

    parsed_url = php_url_parse_ex(url_str, url_len);
    if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL) {
        if (parsed_url != NULL) {
            php_url_free(parsed_url);
        }

        king_http2_set_error(
            "%s() requires a valid absolute http:// or https:// URL.",
            function_name
        );
        return FAILURE;
    }

    if (strcasecmp(ZSTR_VAL(parsed_url->scheme), "http") != 0
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") != 0) {
        php_url_free(parsed_url);
        king_http2_set_error(
            "%s() currently supports only absolute http:// and https:// URLs.",
            function_name
        );
        return FAILURE;
    }

    if (parsed_url->user != NULL || parsed_url->pass != NULL) {
        php_url_free(parsed_url);
        king_http2_set_error(
            "%s() does not support embedding credentials in the request URL.",
            function_name
        );
        return FAILURE;
    }

    *parsed_url_out = parsed_url;
    return SUCCESS;
}
