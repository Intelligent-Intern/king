/*
 * =========================================================================
 * FILENAME:   src/server/cors.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates a first local server-side CORS helper slice for the skeleton
 * build. The current runtime materializes the configured allowlist on local
 * listener request snapshots and applies deterministic wildcard response
 * defaults (`Access-Control-Allow-Origin: *`, `Vary: Origin`) when the
 * policy is globally open. Origin-aware allowlist matching and preflight I/O
 * remain outside this leaf.
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

void king_server_cors_add_request_metadata(
    zval *request,
    king_client_session_t *session
)
{
    zval cors;
    zval allowed_origins;
    zend_long allowed_origin_count;
    bool allow_any_origin;
    bool enabled = (
        session->config_security_cors_allowed_origins != NULL
        && ZSTR_LEN(session->config_security_cors_allowed_origins) > 0
    );

    king_server_cors_parse_policy(
        session->config_security_cors_allowed_origins,
        &allowed_origins,
        &allowed_origin_count,
        &allow_any_origin
    );

    array_init(&cors);
    add_assoc_bool(&cors, "enabled", enabled);
    add_assoc_bool(&cors, "allow_any_origin", allow_any_origin);
    add_assoc_bool(&cors, "preflight", 0);
    if (session->config_security_cors_allowed_origins != NULL) {
        add_assoc_str(
            &cors,
            "policy",
            zend_string_copy(session->config_security_cors_allowed_origins)
        );
    } else {
        add_assoc_string(&cors, "policy", "");
    }
    add_assoc_null(&cors, "origin");
    add_assoc_zval(&cors, "allowed_origins", &allowed_origins);
    add_assoc_zval(request, "cors", &cors);

    session->server_cors_active = enabled;
    session->server_cors_allow_any_origin = allow_any_origin;
    session->server_last_cors_preflight = false;
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
        "",
        0
    );
    king_server_control_set_string_bytes(
        &session->server_last_cors_allow_origin,
        allow_any_origin ? "*" : "",
        allow_any_origin ? 1 : 0
    );
}

void king_server_cors_apply_response(
    zval *response,
    king_client_session_t *session
)
{
    zval *headers;

    if (!session->server_cors_active || !session->server_cors_allow_any_origin) {
        return;
    }

    SEPARATE_ARRAY(response);

    headers = zend_hash_str_find(
        Z_ARRVAL_P(response),
        "headers",
        sizeof("headers") - 1
    );

    if (headers == NULL || Z_TYPE_P(headers) == IS_NULL) {
        zval new_headers;

        array_init(&new_headers);
        add_assoc_string(&new_headers, "Access-Control-Allow-Origin", "*");
        add_assoc_string(&new_headers, "Vary", "Origin");
        add_assoc_zval(response, "headers", &new_headers);
    } else {
        SEPARATE_ARRAY(headers);

        if (zend_hash_str_find(
                Z_ARRVAL_P(headers),
                "Access-Control-Allow-Origin",
                sizeof("Access-Control-Allow-Origin") - 1
            ) == NULL) {
            add_assoc_string(headers, "Access-Control-Allow-Origin", "*");
        }

        if (zend_hash_str_find(
                Z_ARRVAL_P(headers),
                "Vary",
                sizeof("Vary") - 1
            ) == NULL) {
            add_assoc_string(headers, "Vary", "Origin");
        }
    }

    king_server_control_set_string_bytes(
        &session->server_last_cors_allow_origin,
        "*",
        1
    );
    session->last_activity_at = time(NULL);
}
