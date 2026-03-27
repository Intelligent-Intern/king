#include "php_king.h"
#include "include/client/early_hints.h"

#include <zend_exceptions.h>
#include <ctype.h>
#include <string.h>

static king_http1_request_context *king_early_hints_fetch_request_context(
    zval *request_context,
    uint32_t arg_num)
{
    king_http1_request_context *context;

    if (Z_TYPE_P(request_context) != IS_RESOURCE) {
        zend_argument_type_error(
            arg_num,
            "must be a King\\HttpRequestContext resource"
        );
        return NULL;
    }

    context = (king_http1_request_context *) zend_fetch_resource(
        Z_RES_P(request_context),
        "King\\HttpRequestContext",
        le_king_request_context
    );
    if (context == NULL) {
        zend_argument_type_error(
            arg_num,
            "must be a King\\HttpRequestContext resource"
        );
    }

    return context;
}

static void king_early_hints_lowercase_ascii(char *value)
{
    while (*value != '\0') {
        *value = (char) tolower((unsigned char) *value);
        value++;
    }
}

static char *king_early_hints_trim_ascii(char *value)
{
    char *end;

    while (*value != '\0' && isspace((unsigned char) *value)) {
        value++;
    }

    end = value + strlen(value);
    while (end > value && isspace((unsigned char) end[-1])) {
        end--;
    }

    *end = '\0';
    return value;
}

static char *king_early_hints_unquote(char *value)
{
    size_t value_len = strlen(value);

    if (
        value_len >= 2
        && (
            (value[0] == '"' && value[value_len - 1] == '"')
            || (value[0] == '\'' && value[value_len - 1] == '\'')
        )
    ) {
        value[value_len - 1] = '\0';
        return value + 1;
    }

    return value;
}

static zend_result king_early_hints_parse_link_value(
    const char *field_value,
    size_t field_value_len,
    zval *hint_out)
{
    char *field_copy;
    char *cursor;
    char *url_start;
    char *url_end;

    ZVAL_UNDEF(hint_out);

    if (field_value_len == 0) {
        return FAILURE;
    }

    field_copy = estrndup(field_value, field_value_len);
    cursor = king_early_hints_trim_ascii(field_copy);

    if (*cursor == '\0') {
        efree(field_copy);
        return FAILURE;
    }

    url_start = strchr(cursor, '<');
    url_end = url_start != NULL ? strchr(url_start + 1, '>') : NULL;
    if (url_start == NULL || url_end == NULL || url_end == url_start + 1) {
        efree(field_copy);
        return FAILURE;
    }

    array_init(hint_out);
    add_assoc_stringl(
        hint_out,
        "url",
        url_start + 1,
        (size_t) (url_end - url_start - 1)
    );

    cursor = url_end + 1;
    while (*cursor != '\0') {
        char *segment_start;
        char *parameter;
        char *equals;
        char quote = '\0';

        while (*cursor == ';' || isspace((unsigned char) *cursor)) {
            cursor++;
        }

        if (*cursor == '\0') {
            break;
        }

        segment_start = cursor;
        while (*cursor != '\0') {
            if (quote != '\0') {
                if (*cursor == quote) {
                    quote = '\0';
                }
            } else if (*cursor == '"' || *cursor == '\'') {
                quote = *cursor;
            } else if (*cursor == ';') {
                break;
            }

            cursor++;
        }

        if (*cursor == ';') {
            *cursor = '\0';
            cursor++;
        }

        parameter = king_early_hints_trim_ascii(segment_start);
        if (*parameter == '\0') {
            continue;
        }

        equals = strchr(parameter, '=');
        if (equals == NULL) {
            king_early_hints_lowercase_ascii(parameter);
            add_assoc_bool(hint_out, parameter, 1);
            continue;
        }

        *equals = '\0';

        {
            char *key = king_early_hints_trim_ascii(parameter);
            char *value = king_early_hints_trim_ascii(equals + 1);

            if (*key == '\0') {
                continue;
            }

            value = king_early_hints_unquote(value);
            king_early_hints_lowercase_ascii(key);
            add_assoc_string(hint_out, key, value);
        }
    }

    efree(field_copy);
    return SUCCESS;
}

static void king_early_hints_store_link_segment(
    king_http1_request_context *context,
    const char *segment_start,
    const char *segment_end)
{
    size_t segment_len;
    zval hint;

    while (
        segment_start < segment_end
        && isspace((unsigned char) *segment_start)
    ) {
        segment_start++;
    }

    while (
        segment_end > segment_start
        && isspace((unsigned char) segment_end[-1])
    ) {
        segment_end--;
    }

    segment_len = (size_t) (segment_end - segment_start);
    if (segment_len == 0) {
        return;
    }

    ZVAL_UNDEF(&hint);
    if (king_early_hints_parse_link_value(segment_start, segment_len, &hint) != SUCCESS) {
        return;
    }

    king_http1_request_context_append_early_hint(context, &hint);
}

static void king_early_hints_process_link_header(
    king_http1_request_context *context,
    const char *field_value,
    size_t field_value_len)
{
    const char *cursor = field_value;
    const char *segment_start = field_value;
    const char *end = field_value + field_value_len;
    bool in_uri = false;
    char quote = '\0';

    while (cursor < end) {
        if (quote != '\0') {
            if (*cursor == quote) {
                quote = '\0';
            }
            cursor++;
            continue;
        }

        if (*cursor == '"' || *cursor == '\'') {
            quote = *cursor;
            cursor++;
            continue;
        }

        if (*cursor == '<') {
            in_uri = true;
            cursor++;
            continue;
        }

        if (*cursor == '>') {
            in_uri = false;
            cursor++;
            continue;
        }

        if (*cursor == ',' && !in_uri) {
            king_early_hints_store_link_segment(context, segment_start, cursor);
            segment_start = cursor + 1;
        }

        cursor++;
    }

    king_early_hints_store_link_segment(context, segment_start, end);
}

static void king_early_hints_process_header_value(
    king_http1_request_context *context,
    zval *header_value)
{
    zval *nested_value;

    if (header_value == NULL) {
        return;
    }

    if (Z_TYPE_P(header_value) == IS_STRING) {
        king_early_hints_process_link_header(
            context,
            Z_STRVAL_P(header_value),
            Z_STRLEN_P(header_value)
        );
        return;
    }

    if (Z_TYPE_P(header_value) != IS_ARRAY) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), nested_value) {
        if (Z_TYPE_P(nested_value) != IS_STRING) {
            continue;
        }

        king_early_hints_process_link_header(
            context,
            Z_STRVAL_P(nested_value),
            Z_STRLEN_P(nested_value)
        );
    } ZEND_HASH_FOREACH_END();
}

void king_client_early_hints_process_headers(
    king_http1_request_context *context,
    zval *headers)
{
    zval *header_value;
    zend_string *header_name;

    if (
        context == NULL
        || headers == NULL
        || Z_TYPE_P(headers) != IS_ARRAY
    ) {
        return;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers), header_name, header_value) {
        if (
            header_name == NULL
            || !zend_string_equals_literal_ci(header_name, "link")
        ) {
            continue;
        }

        king_early_hints_process_header_value(context, header_value);
    } ZEND_HASH_FOREACH_END();
}

PHP_FUNCTION(king_client_early_hints_process)
{
    zval *request_context;
    zval *headers;
    king_http1_request_context *context;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(request_context)
        Z_PARAM_ARRAY(headers)
    ZEND_PARSE_PARAMETERS_END();

    context = king_early_hints_fetch_request_context(request_context, 1);
    if (context == NULL) {
        RETURN_THROWS();
    }

    king_client_early_hints_process_headers(context, headers);

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_client_early_hints_get_pending)
{
    zval *request_context;
    king_http1_request_context *context;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(request_context)
    ZEND_PARSE_PARAMETERS_END();

    context = king_early_hints_fetch_request_context(request_context, 1);
    if (context == NULL) {
        RETURN_THROWS();
    }

    if (king_http1_request_context_get_pending_early_hints(context, return_value) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "king_client_early_hints_get_pending() failed to materialize the pending hint list."
        );
        RETURN_THROWS();
    }

    king_set_error("");
}
