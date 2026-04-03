/*
 * =========================================================================
 * FILENAME:   src/server/cors.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates the current server-side CORS helper slice for the runtime build.
 * The active runtime materializes origin and preflight metadata on listener
 * request snapshots, matches exact configured origins against real request
 * headers, and applies deterministic CORS response defaults on the shared
 * listener response path. Full browser policy automation remains outside this
 * slice, but the live request and response contract is now explicit.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/server/cors.h"

#include <string.h>
#include <time.h>

#include "control.inc"

static void king_server_cors_trim_span(
    const char **start,
    size_t *length
)
{
    while (*length > 0 && (**start == ' ' || **start == '\t')) {
        (*start)++;
        (*length)--;
    }

    while (*length > 0) {
        char c = (*start)[*length - 1];

        if (c != ' ' && c != '\t') {
            break;
        }

        (*length)--;
    }
}

static void king_server_cors_parse_policy(
    zend_string *policy,
    zval *allowed_origins,
    zend_long *count,
    bool *allow_any_origin
)
{
    const char *cursor;
    const char *entry_start;
    const char *comma;
    size_t remaining;
    size_t entry_len;

    *count = 0;
    *allow_any_origin = false;

    array_init(allowed_origins);

    if (policy == NULL || ZSTR_LEN(policy) == 0) {
        return;
    }

    cursor = ZSTR_VAL(policy);
    remaining = ZSTR_LEN(policy);

    while (remaining > 0) {
        comma = memchr(cursor, ',', remaining);
        entry_start = cursor;
        entry_len = comma != NULL
            ? (size_t) (comma - cursor)
            : remaining;

        king_server_cors_trim_span(&entry_start, &entry_len);

        if (entry_len > 0) {
            add_next_index_stringl(
                allowed_origins,
                (char *) entry_start,
                entry_len
            );
            (*count)++;

            if (entry_len == 1 && entry_start[0] == '*') {
                *allow_any_origin = true;
            }
        }

        if (comma == NULL) {
            break;
        }

        remaining -= (size_t) ((comma - cursor) + 1);
        cursor = comma + 1;
    }
}

static zval *king_server_cors_find_header_case_insensitive(
    zval *headers,
    const char *name,
    size_t name_len
)
{
    zend_string *key;
    zval *value;

    if (headers == NULL || Z_TYPE_P(headers) != IS_ARRAY) {
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers), key, value) {
        if (key == NULL) {
            continue;
        }

        if (ZSTR_LEN(key) == name_len && zend_binary_strcasecmp(
                ZSTR_VAL(key),
                ZSTR_LEN(key),
                name,
                name_len
            ) == 0) {
            return value;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_string *king_server_cors_copy_first_header_value(
    zval *headers,
    const char *name,
    size_t name_len
)
{
    zval *value;
    zval *first;

    value = king_server_cors_find_header_case_insensitive(headers, name, name_len);
    if (value == NULL) {
        return NULL;
    }

    if (Z_TYPE_P(value) == IS_ARRAY) {
        first = zend_hash_index_find(Z_ARRVAL_P(value), 0);
        if (first == NULL) {
            return NULL;
        }

        return zval_get_string(first);
    }

    if (Z_TYPE_P(value) == IS_NULL) {
        return NULL;
    }

    return zval_get_string(value);
}

static bool king_server_cors_allowed_origins_contains(
    zval *allowed_origins,
    zend_string *origin
)
{
    zval *value;

    if (allowed_origins == NULL || Z_TYPE_P(allowed_origins) != IS_ARRAY || origin == NULL) {
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(allowed_origins), value) {
        zend_string *candidate = zval_get_string(value);
        bool matches = zend_string_equals(candidate, origin);
        zend_string_release(candidate);

        if (matches) {
            return true;
        }
    } ZEND_HASH_FOREACH_END();

    return false;
}

void king_server_cors_add_request_metadata(
    zval *request,
    king_client_session_t *session
)
{
    zval cors;
    zval allowed_origins;
    zval *headers;
    zval *method;
    zend_long allowed_origin_count;
    bool allow_any_origin;
    bool preflight = false;
    bool enabled = (
        session->config_security_cors_allowed_origins != NULL
        && ZSTR_LEN(session->config_security_cors_allowed_origins) > 0
    );
    zend_string *origin = NULL;
    zend_string *requested_method = NULL;
    zend_string *allow_origin = NULL;

    king_server_cors_parse_policy(
        session->config_security_cors_allowed_origins,
        &allowed_origins,
        &allowed_origin_count,
        &allow_any_origin
    );

    headers = zend_hash_str_find(Z_ARRVAL_P(request), "headers", sizeof("headers") - 1);
    method = zend_hash_str_find(Z_ARRVAL_P(request), "method", sizeof("method") - 1);
    origin = king_server_cors_copy_first_header_value(headers, "origin", sizeof("origin") - 1);
    requested_method = king_server_cors_copy_first_header_value(
        headers,
        "access-control-request-method",
        sizeof("access-control-request-method") - 1
    );

    if (origin != NULL) {
        if (allow_any_origin) {
            allow_origin = zend_string_init("*", sizeof("*") - 1, 0);
        } else if (king_server_cors_allowed_origins_contains(&allowed_origins, origin)) {
            allow_origin = zend_string_copy(origin);
        }
    } else if (allow_any_origin) {
        allow_origin = zend_string_init("*", sizeof("*") - 1, 0);
    }

    if (origin != NULL
        && requested_method != NULL
        && method != NULL
        && Z_TYPE_P(method) == IS_STRING
        && zend_string_equals_literal_ci(Z_STR_P(method), "OPTIONS")) {
        preflight = true;
    }

    array_init(&cors);
    add_assoc_bool(&cors, "enabled", enabled);
    add_assoc_bool(&cors, "allow_any_origin", allow_any_origin);
    add_assoc_bool(&cors, "preflight", preflight);
    if (session->config_security_cors_allowed_origins != NULL) {
        add_assoc_str(
            &cors,
            "policy",
            zend_string_copy(session->config_security_cors_allowed_origins)
        );
    } else {
        add_assoc_string(&cors, "policy", "");
    }
    if (origin != NULL) {
        add_assoc_str(&cors, "origin", zend_string_copy(origin));
    } else {
        add_assoc_null(&cors, "origin");
    }
    if (allow_origin != NULL) {
        add_assoc_str(&cors, "allow_origin", zend_string_copy(allow_origin));
    } else {
        add_assoc_null(&cors, "allow_origin");
    }
    add_assoc_zval(&cors, "allowed_origins", &allowed_origins);
    add_assoc_zval(request, "cors", &cors);

    session->server_cors_active = enabled;
    session->server_cors_allow_any_origin = allow_any_origin;
    session->server_last_cors_preflight = preflight;
    session->server_last_cors_allowed_origin_count = allowed_origin_count;

    if (enabled) {
        session->server_cors_apply_count++;
    }

    king_server_control_set_string_bytes(
        &session->server_last_cors_policy,
        session->config_security_cors_allowed_origins != NULL
            ? ZSTR_VAL(session->config_security_cors_allowed_origins)
            : "",
        session->config_security_cors_allowed_origins != NULL
            ? ZSTR_LEN(session->config_security_cors_allowed_origins)
            : 0
    );
    king_server_control_set_string_bytes(
        &session->server_last_cors_origin,
        origin != NULL ? ZSTR_VAL(origin) : "",
        origin != NULL ? ZSTR_LEN(origin) : 0
    );
    king_server_control_set_string_bytes(
        &session->server_last_cors_allow_origin,
        allow_origin != NULL ? ZSTR_VAL(allow_origin) : "",
        allow_origin != NULL ? ZSTR_LEN(allow_origin) : 0
    );

    if (requested_method != NULL) {
        zend_string_release(requested_method);
    }
    if (origin != NULL) {
        zend_string_release(origin);
    }
    if (allow_origin != NULL) {
        zend_string_release(allow_origin);
    }
}

void king_server_cors_apply_response(
    zval *response,
    king_client_session_t *session
)
{
    zval *headers;
    zval *allow_origin_header;
    zval *vary_header;
    const char *allow_origin_value;
    size_t allow_origin_len;
    const char *vary_value;
    size_t vary_len;
    bool has_origin_in_vary = false;

    if (!session->server_cors_active) {
        return;
    }

    allow_origin_value = session->server_last_cors_allow_origin != NULL
        ? ZSTR_VAL(session->server_last_cors_allow_origin)
        : "";
    allow_origin_len = session->server_last_cors_allow_origin != NULL
        ? ZSTR_LEN(session->server_last_cors_allow_origin)
        : 0;

    SEPARATE_ARRAY(response);

    headers = zend_hash_str_find(
        Z_ARRVAL_P(response),
        "headers",
        sizeof("headers") - 1
    );

    if (headers == NULL || Z_TYPE_P(headers) == IS_NULL) {
        zval new_headers;

        array_init(&new_headers);
        if (allow_origin_len > 0) {
            add_assoc_stringl(
                &new_headers,
                "Access-Control-Allow-Origin",
                (char *) allow_origin_value,
                allow_origin_len
            );
        }
        add_assoc_string(&new_headers, "Vary", "Origin");
        add_assoc_zval(response, "headers", &new_headers);
        session->last_activity_at = time(NULL);
        return;
    }

    SEPARATE_ARRAY(headers);

    if (allow_origin_len > 0) {
        allow_origin_header = king_server_cors_find_header_case_insensitive(
            headers,
            "Access-Control-Allow-Origin",
            sizeof("Access-Control-Allow-Origin") - 1
        );
        if (allow_origin_header == NULL) {
            add_assoc_stringl(
                headers,
                "Access-Control-Allow-Origin",
                (char *) allow_origin_value,
                allow_origin_len
            );
        }
    }

    vary_header = king_server_cors_find_header_case_insensitive(
        headers,
        "Vary",
        sizeof("Vary") - 1
    );
    if (vary_header == NULL) {
        add_assoc_string(headers, "Vary", "Origin");
        session->last_activity_at = time(NULL);
        return;
    }

    if (Z_TYPE_P(vary_header) == IS_STRING) {
        vary_value = Z_STRVAL_P(vary_header);
        vary_len = Z_STRLEN_P(vary_header);
        has_origin_in_vary = php_memnstr(
            vary_value,
            "Origin",
            sizeof("Origin") - 1,
            vary_value + vary_len
        ) != NULL;
        if (!has_origin_in_vary) {
            zend_string *new_vary = strpprintf(0, "%s, Origin", vary_value);
            zval_ptr_dtor(vary_header);
            ZVAL_STR(vary_header, new_vary);
        }
    }

    session->last_activity_at = time(NULL);
}
