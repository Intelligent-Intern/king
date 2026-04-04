/*
 * =========================================================================
 * FILENAME:   src/config/open_telemetry/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the OpenTelemetry config
 * family. Enablement, service naming, exporter endpoint/protocol/headers,
 * batching limits, trace sampler settings, metrics intervals and histogram
 * boundaries, and log batching all land in the single
 * `king_open_telemetry_config` snapshot.
 * =========================================================================
 */

#include "include/config/open_telemetry/base_layer.h"
#include "ext/standard/url.h"
#include "zend_smart_str.h"

#include <string.h>
#include <strings.h>

kg_open_telemetry_config_t king_open_telemetry_config;

static zend_bool king_open_telemetry_string_has_crlf(
    const char *value,
    size_t value_len
)
{
    size_t index;

    if (value == NULL) {
        return 0;
    }

    for (index = 0; index < value_len; index++) {
        if (value[index] == '\r' || value[index] == '\n') {
            return 1;
        }
    }

    return 0;
}

const char *king_open_telemetry_validate_exporter_endpoint_value(
    const char *value,
    size_t value_len
)
{
    php_url *parsed_url = NULL;
    const char *error_message = NULL;

    if (value == NULL || value_len == 0) {
        return "Telemetry exporter endpoints must be non-empty absolute http:// or https:// URLs.";
    }

    if (strlen(value) != value_len) {
        return "Telemetry exporter endpoints must not contain NUL bytes.";
    }

    if (king_open_telemetry_string_has_crlf(value, value_len)) {
        return "Telemetry exporter endpoints must be single-line URLs without CRLF.";
    }

    parsed_url = php_url_parse_ex(value, value_len);
    if (parsed_url == NULL
        || parsed_url->scheme == NULL
        || parsed_url->host == NULL
        || ZSTR_LEN(parsed_url->scheme) == 0
        || ZSTR_LEN(parsed_url->host) == 0) {
        error_message = "Telemetry exporter endpoints must be absolute http:// or https:// URLs.";
        goto cleanup;
    }

    if (strcasecmp(ZSTR_VAL(parsed_url->scheme), "http") != 0
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") != 0) {
        error_message = "Telemetry exporter endpoints currently support only http:// and https:// URLs.";
        goto cleanup;
    }

    if (parsed_url->user != NULL || parsed_url->pass != NULL) {
        error_message = "Telemetry exporter endpoints must not embed credentials in the URL.";
        goto cleanup;
    }

    if (parsed_url->query != NULL || parsed_url->fragment != NULL) {
        error_message = "Telemetry exporter endpoints must not include query strings or fragments.";
        goto cleanup;
    }

cleanup:
    if (parsed_url != NULL) {
        php_url_free(parsed_url);
    }

    return error_message;
}

const char *king_open_telemetry_validate_exporter_headers_value(
    const char *value,
    size_t value_len
)
{
    if (value == NULL || value_len == 0) {
        return NULL;
    }

    if (strlen(value) != value_len) {
        return "Telemetry exporter headers must not contain NUL bytes.";
    }

    if (king_open_telemetry_string_has_crlf(value, value_len)) {
        return "Telemetry exporter headers must stay on one line without CRLF.";
    }

    return NULL;
}

zend_string *king_open_telemetry_build_public_exporter_endpoint(
    const char *value,
    size_t value_len
)
{
    php_url *parsed_url = NULL;
    smart_str public_endpoint = {0};
    zend_bool is_ipv6_host = 0;

    if (value == NULL || value_len == 0) {
        return zend_string_init("", 0, 0);
    }

    parsed_url = php_url_parse_ex(value, value_len);
    if (parsed_url == NULL
        || parsed_url->scheme == NULL
        || parsed_url->host == NULL
        || ZSTR_LEN(parsed_url->scheme) == 0
        || ZSTR_LEN(parsed_url->host) == 0
        || (strcasecmp(ZSTR_VAL(parsed_url->scheme), "http") != 0
            && strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") != 0)) {
        if (parsed_url != NULL) {
            php_url_free(parsed_url);
        }

        return zend_string_init("", 0, 0);
    }

    is_ipv6_host = strchr(ZSTR_VAL(parsed_url->host), ':') != NULL;

    smart_str_appendl(
        &public_endpoint,
        ZSTR_VAL(parsed_url->scheme),
        ZSTR_LEN(parsed_url->scheme)
    );
    smart_str_appends(&public_endpoint, "://");

    if (is_ipv6_host) {
        smart_str_appendc(&public_endpoint, '[');
    }

    smart_str_append(&public_endpoint, parsed_url->host);

    if (is_ipv6_host) {
        smart_str_appendc(&public_endpoint, ']');
    }

    if (parsed_url->port > 0) {
        smart_str_append_printf(&public_endpoint, ":%u", parsed_url->port);
    }

    smart_str_0(&public_endpoint);
    php_url_free(parsed_url);

    if (public_endpoint.s == NULL) {
        return zend_string_init("", 0, 0);
    }

    return public_endpoint.s;
}
