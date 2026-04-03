/*
 * =========================================================================
 * FILENAME:   src/server/early_hints.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates the server-side Early Hints slice over the shared King\Session
 * runtime. The current build validates and normalizes the supplied hint
 * headers, stores them on the session snapshot for stats/introspection, and
 * lets the HTTP/1 one-shot listener emit that normalized batch as a real
 * on-wire `103 Early Hints` response for the addressed active stream.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/server/early_hints.h"

#include <stdarg.h>
#include <time.h>

#include "control.inc"

static zend_result king_server_early_hints_append_pair(
    zval *normalized,
    const char *name,
    size_t name_len,
    zval *value,
    const char *function_name
)
{
    zend_string *value_string;
    zval pair;

    if (name_len == 0) {
        king_server_control_set_errorf(
            "%s() early hint header names must be non-empty strings.",
            function_name
        );
        return FAILURE;
    }

    if (king_server_control_string_has_crlf(name, name_len)) {
        king_server_control_set_errorf(
            "%s() early hint header names must not contain line breaks.",
            function_name
        );
        return FAILURE;
    }

    switch (Z_TYPE_P(value)) {
        case IS_STRING:
        case IS_LONG:
        case IS_DOUBLE:
        case IS_TRUE:
        case IS_FALSE:
            break;
        default:
            king_server_control_set_errorf(
                "%s() early hint header values must be scalar strings, ints, floats, or bools.",
                function_name
            );
            return FAILURE;
    }

    value_string = zval_get_string(value);
    if (
        king_server_control_string_has_crlf(
            ZSTR_VAL(value_string),
            ZSTR_LEN(value_string)
        )
    ) {
        zend_string_release(value_string);
        king_server_control_set_errorf(
            "%s() early hint header values must not contain line breaks.",
            function_name
        );
        return FAILURE;
    }

    array_init(&pair);
    add_assoc_stringl(&pair, "name", (char *) name, name_len);
    add_assoc_str(&pair, "value", value_string);
    add_next_index_zval(normalized, &pair);

    return SUCCESS;
}

static zend_result king_server_early_hints_normalize_entry(
    zval *normalized,
    zend_string *key,
    zval *entry,
    const char *function_name
)
{
    if (key != NULL) {
        if (Z_TYPE_P(entry) == IS_ARRAY) {
            zval *subvalue;

            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(entry), subvalue) {
                if (
                    king_server_early_hints_append_pair(
                        normalized,
                        ZSTR_VAL(key),
                        ZSTR_LEN(key),
                        subvalue,
                        function_name
                    ) != SUCCESS
                ) {
                    return FAILURE;
                }
            } ZEND_HASH_FOREACH_END();

            return SUCCESS;
        }

        return king_server_early_hints_append_pair(
            normalized,
            ZSTR_VAL(key),
            ZSTR_LEN(key),
            entry,
            function_name
        );
    }

    if (Z_TYPE_P(entry) != IS_ARRAY) {
        king_server_control_set_errorf(
            "%s() numeric early hint entries must be arrays with name/value pairs.",
            function_name
        );
        return FAILURE;
    }

    {
        zval *name = zend_hash_str_find(
            Z_ARRVAL_P(entry),
            "name",
            sizeof("name") - 1
        );
        zval *value = zend_hash_str_find(
            Z_ARRVAL_P(entry),
            "value",
            sizeof("value") - 1
        );

        if (name == NULL) {
            name = zend_hash_index_find(Z_ARRVAL_P(entry), 0);
        }

        if (value == NULL) {
            value = zend_hash_index_find(Z_ARRVAL_P(entry), 1);
        }

        if (name == NULL || value == NULL || Z_TYPE_P(name) != IS_STRING) {
            king_server_control_set_errorf(
                "%s() numeric early hint entries must provide a string name and a value.",
                function_name
            );
            return FAILURE;
        }

        return king_server_early_hints_append_pair(
            normalized,
            Z_STRVAL_P(name),
            Z_STRLEN_P(name),
            value,
            function_name
        );
    }
}

static zend_result king_server_early_hints_normalize(
    zval *hints,
    zval *normalized,
    const char *function_name
)
{
    zval *entry;
    zend_string *key;

    array_init(normalized);

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(hints), key, entry) {
        if (
            king_server_early_hints_normalize_entry(
                normalized,
                key,
                entry,
                function_name
            ) != SUCCESS
        ) {
            zval_ptr_dtor(normalized);
            ZVAL_UNDEF(normalized);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

PHP_FUNCTION(king_server_send_early_hints)
{
    zval *zsession;
    zend_long stream_id;
    zval *hints;
    king_client_session_t *session;
    zval normalized;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_LONG(stream_id)
        Z_PARAM_ARRAY(hints)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_control_fetch_open_session(
        zsession,
        1,
        "king_server_send_early_hints"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_control_validate_stream_id(
            session,
            stream_id,
            "king_server_send_early_hints"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (
        king_server_early_hints_normalize(
            hints,
            &normalized,
            "king_server_send_early_hints"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (!Z_ISUNDEF(session->server_last_early_hints)) {
        zval_ptr_dtor(&session->server_last_early_hints);
    }

    ZVAL_COPY_VALUE(&session->server_last_early_hints, &normalized);
    session->server_early_hints_count++;
    session->server_last_early_hints_stream_id = stream_id;
    session->server_last_early_hints_hint_count =
        zend_hash_num_elements(Z_ARRVAL(session->server_last_early_hints));
    session->last_activity_at = time(NULL);

    king_set_error("");
    RETURN_TRUE;
}
