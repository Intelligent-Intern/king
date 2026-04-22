/*
 * =========================================================================
 * FILENAME:   src/client/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Protocol-aware request dispatcher for the active client runtime.
 * AUTO currently routes onto the local HTTP/1 path by default; explicit
 * `preferred_protocol` can force the libcurl-backed HTTP/2 or the
 * LSQUIC-backed HTTP/3 leaves. The optional `response_stream` surface remains
 * bound to the HTTP/1 runtime.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/http1.h"
#include "include/client/http2.h"
#include "include/client/http3.h"
#include "include/client/index.h"

#include <zend_exceptions.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>

static void king_client_dispatch_set_error(
    const char *function_name,
    const char *message)
{
    char buffer[KING_ERR_LEN];

    snprintf(
        buffer,
        sizeof(buffer),
        "%s() %s",
        function_name,
        message
    );

    king_set_error(buffer);
}

static zend_result king_client_dispatch_parse_protocol_preference(
    zval *options_array,
    const char *function_name,
    king_client_protocol_preference_t *protocol_preference)
{
    zval *option_value;
    zend_string *preference_string;

    *protocol_preference = KING_CLIENT_PROTOCOL_AUTO;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "preferred_protocol",
        sizeof("preferred_protocol") - 1
    );
    if (option_value == NULL || Z_TYPE_P(option_value) == IS_NULL) {
        return SUCCESS;
    }

    preference_string = zval_get_string(option_value);

    if (
        strcasecmp(ZSTR_VAL(preference_string), "auto") == 0
        || strcasecmp(ZSTR_VAL(preference_string), "http") == 0
        || strcasecmp(ZSTR_VAL(preference_string), "http1") == 0
        || strcasecmp(ZSTR_VAL(preference_string), "http1.1") == 0
    ) {
        *protocol_preference = KING_CLIENT_PROTOCOL_HTTP1;
    } else if (
        strcasecmp(ZSTR_VAL(preference_string), "http2") == 0
        || strcasecmp(ZSTR_VAL(preference_string), "http2.0") == 0
    ) {
        *protocol_preference = KING_CLIENT_PROTOCOL_HTTP2;
    } else if (
        strcasecmp(ZSTR_VAL(preference_string), "http3") == 0
        || strcasecmp(ZSTR_VAL(preference_string), "http3.0") == 0
    ) {
        *protocol_preference = KING_CLIENT_PROTOCOL_HTTP3;
    } else {
        king_client_dispatch_set_error(
            function_name,
            "received an unsupported preferred_protocol option."
        );
        zend_string_release(preference_string);
        return FAILURE;
    }

    zend_string_release(preference_string);
    return SUCCESS;
}

static const char *king_client_protocol_preference_name(
    king_client_protocol_preference_t protocol_preference)
{
    switch (protocol_preference) {
        case KING_CLIENT_PROTOCOL_HTTP1:
            return "http1";
        case KING_CLIENT_PROTOCOL_HTTP2:
            return "http2";
        case KING_CLIENT_PROTOCOL_HTTP3:
            return "http3";
        case KING_CLIENT_PROTOCOL_AUTO:
        default:
            return "auto";
    }
}

static zend_result king_client_dispatch_parse_streaming_mode(
    zval *options_array,
    const char *function_name,
    bool *response_stream)
{
    zval *option_value;

    *response_stream = false;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "response_stream",
        sizeof("response_stream") - 1
    );
    if (option_value == NULL || Z_TYPE_P(option_value) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(option_value) != IS_TRUE && Z_TYPE_P(option_value) != IS_FALSE) {
        king_client_dispatch_set_error(
            function_name,
            "option 'response_stream' must be provided as a boolean."
        );
        return FAILURE;
    }

    *response_stream = Z_TYPE_P(option_value) == IS_TRUE;
    return SUCCESS;
}

static king_http1_request_context *king_request_context_fetch_resource(
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

static zend_result king_client_send_request_dispatch(
    zval *return_value,
    const char *url_str,
    size_t url_len,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zval *body_zval,
    zval *options_array,
    const char *function_name)
{
    king_client_protocol_preference_t protocol_preference;
    bool response_stream = false;

    if (url_len == 0) {
        king_client_dispatch_set_error(function_name, "requires a non-empty URL.");
        return FAILURE;
    }

    if (king_client_dispatch_parse_protocol_preference(
            options_array,
            function_name,
            &protocol_preference
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_client_dispatch_parse_streaming_mode(
            options_array,
            function_name,
            &response_stream
        ) != SUCCESS) {
        return FAILURE;
    }

    if (protocol_preference == KING_CLIENT_PROTOCOL_HTTP2) {
        if (response_stream) {
            king_set_error("HTTP/1 response_stream mode is not available on the active HTTP/2 runtime.");
            zend_throw_exception_ex(
                king_ce_protocol_exception,
                0,
                "HTTP/1 response_stream mode is not available on the active HTTP/2 runtime."
            );
            return FAILURE;
        }

        if (king_http2_request_dispatch(
                return_value,
                url_str,
                url_len,
                method_str,
                method_len,
                headers_array,
                body_zval,
                options_array,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }

        return SUCCESS;
    }

    if (protocol_preference == KING_CLIENT_PROTOCOL_HTTP3) {
        if (response_stream) {
            king_set_error("HTTP/1 response_stream mode is not available on the active HTTP/3 runtime.");
            zend_throw_exception_ex(
                king_ce_protocol_exception,
                0,
                "HTTP/1 response_stream mode is not available on the active HTTP/3 runtime."
            );
            return FAILURE;
        }

        if (king_http3_request_dispatch(
                return_value,
                url_str,
                url_len,
                method_str,
                method_len,
                headers_array,
                body_zval,
                options_array,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }

        return SUCCESS;
    }

    if (king_http1_request_dispatch(
            return_value,
            url_str,
            url_len,
            method_str,
            method_len,
            headers_array,
            body_zval,
            options_array,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static void king_client_send_request_internal(
    INTERNAL_FUNCTION_PARAMETERS,
    const char *function_name)
{
    char *url_str;
    size_t url_len;
    char *method_str = "GET";
    size_t method_len = sizeof("GET") - 1;
    zval *headers_array = NULL;
    zval *body_zval = NULL;
    zval *options_array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 5)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(method_str, method_len)
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ZVAL_OR_NULL(body_zval)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    if (king_client_send_request_dispatch(
            return_value,
            url_str,
            url_len,
            method_str,
            method_len,
            headers_array,
            body_zval,
            options_array,
            function_name
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }
}

#include "object.inc"

PHP_FUNCTION(king_client_send_request)
{
    king_client_send_request_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_client_send_request"
    );
}

PHP_FUNCTION(king_send_request)
{
    king_client_send_request_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_send_request"
    );
}

PHP_FUNCTION(king_receive_response)
{
    zval *request_context;
    king_http1_request_context *context;
    zval payload;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(request_context)
    ZEND_PARSE_PARAMETERS_END();

    context = king_request_context_fetch_resource(request_context, 1);
    if (context == NULL) {
        RETURN_THROWS();
    }

    ZVAL_UNDEF(&payload);
    if (king_http1_request_context_build_payload(
            context,
            &payload,
            "king_receive_response"
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_response_object_init_from_context(return_value, &payload, request_context) != SUCCESS) {
        zval_ptr_dtor(&payload);
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "king_receive_response() failed to initialize the streaming response object."
        );
        RETURN_THROWS();
    }

    zval_ptr_dtor(&payload);
}
