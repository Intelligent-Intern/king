#include "php.h"
#include "php_king.h"
#include "include/client/websocket.h"
#include "include/config/config.h"
#include "include/config/app_http3_websockets_webtransport/base_layer.h"

#include "Zend/zend_smart_str.h"
#include "zend_exceptions.h"
#include "ext/standard/url.h"

#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>

#define KING_WS_PING_MAX_PAYLOAD_LEN 125
#define KING_WS_CLOSE_REASON_MAX_LEN 123

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_WebSocket_Connection___construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_send, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_sendBinary, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_ping, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, data, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_close, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, code, IS_LONG, 0, "1000")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_getInfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static void king_websocket_set_error(
    const char *format,
    ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static bool king_websocket_string_has_crlf(
    const char *value,
    size_t value_len
)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static king_ws_state *king_websocket_fetch_state(
    zval *websocket,
    uint32_t arg_num
)
{
    king_ws_state *state;

    if (
        Z_TYPE_P(websocket) == IS_OBJECT
        && king_ce_ws_connection != NULL
        && instanceof_function(Z_OBJCE_P(websocket), king_ce_ws_connection)
    ) {
        king_ws_object *intern = php_king_ws_obj_from_zend(Z_OBJ_P(websocket));

        if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
            zend_argument_type_error(
                arg_num,
                "must be a valid King\\WebSocket resource or King\\WebSocket\\Connection object"
            );
            return NULL;
        }

        websocket = &intern->resource;
    }

    if (Z_TYPE_P(websocket) != IS_RESOURCE) {
        zend_argument_type_error(
            arg_num,
            "must be of type resource or King\\WebSocket\\Connection, %s given",
            zend_zval_type_name(websocket)
        );
        return NULL;
    }

    state = (king_ws_state *) zend_fetch_resource(
        Z_RES_P(websocket),
        "King\\WebSocket",
        le_king_ws
    );
    if (state == NULL) {
        zend_argument_type_error(
            arg_num,
            "must be a valid King\\WebSocket resource or King\\WebSocket\\Connection object"
        );
    }

    return state;
}

static king_ws_state *king_websocket_object_fetch_state(
    zval *object,
    const char *function_name
)
{
    king_ws_object *intern = php_king_ws_obj_from_zend(Z_OBJ_P(object));
    king_ws_state *state;

    if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        king_set_error("King\\WebSocket\\Connection object is not initialized.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() requires an initialized King\\WebSocket\\Connection object.",
            function_name
        );
        return NULL;
    }

    state = zend_fetch_resource(
        Z_RES(intern->resource),
        "King\\WebSocket",
        le_king_ws
    );
    if (state == NULL) {
        king_set_error("King\\WebSocket\\Connection object lost its native resource.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() lost its active King\\WebSocket resource.",
            function_name
        );
    }

    return state;
}

static void king_websocket_message_queue_clear(king_ws_state *state)
{
    king_ws_message *message = state->incoming_head;

    while (message != NULL) {
        king_ws_message *next = message->next;

        if (message->payload != NULL) {
            zend_string_release(message->payload);
        }

        efree(message);
        message = next;
    }

    state->incoming_head = NULL;
    state->incoming_tail = NULL;
}

static zend_result king_websocket_message_queue_push(
    king_ws_state *state,
    const char *payload,
    size_t payload_len,
    bool is_binary
)
{
    king_ws_message *message = ecalloc(1, sizeof(*message));

    message->payload = zend_string_init(payload, payload_len, 0);
    message->is_binary = is_binary;

    if (state->incoming_tail != NULL) {
        state->incoming_tail->next = message;
    } else {
        state->incoming_head = message;
    }

    state->incoming_tail = message;
    return SUCCESS;
}

static king_ws_message *king_websocket_message_queue_shift(
    king_ws_state *state
)
{
    king_ws_message *message = state->incoming_head;

    if (message == NULL) {
        return NULL;
    }

    state->incoming_head = message->next;
    if (state->incoming_head == NULL) {
        state->incoming_tail = NULL;
    }

    message->next = NULL;
    return message;
}

static zend_result king_websocket_require_open(
    king_ws_state *state,
    const char *function_name
)
{
    if (state->state == KING_WS_STATE_OPEN && !state->closed) {
        return SUCCESS;
    }

    king_websocket_set_error(
        "%s() cannot run on a closed WebSocket connection.",
        function_name
    );
    return FAILURE;
}

static zend_result king_websocket_validate_close_inputs(
    zend_long status_code,
    size_t reason_len,
    const char *function_name
)
{
    if (status_code < 1000 || status_code > 4999) {
        king_websocket_set_error(
            "%s() close status code must be between 1000 and 4999.",
            function_name
        );
        return FAILURE;
    }

    if (reason_len > KING_WS_CLOSE_REASON_MAX_LEN) {
        king_websocket_set_error(
            "%s() close reason cannot exceed %d bytes.",
            function_name,
            KING_WS_CLOSE_REASON_MAX_LEN
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_websocket_validate_positive_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name
)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_websocket_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        king_websocket_set_error(
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_long king_websocket_resolve_default_port(php_url *parsed_url)
{
    if (parsed_url->port > 0) {
        return (zend_long) parsed_url->port;
    }

    if (
        parsed_url->scheme != NULL
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") == 0
    ) {
        return 443;
    }

    return 80;
}

static zend_result king_websocket_parse_url(
    const char *url_str,
    size_t url_len,
    const char *function_name,
    php_url **parsed_url_out
)
{
    php_url *parsed_url;

    if (url_len == 0) {
        king_websocket_set_error(
            "%s() requires a non-empty WebSocket URL.",
            function_name
        );
        return FAILURE;
    }

    parsed_url = php_url_parse_ex(url_str, url_len);
    if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL) {
        if (parsed_url != NULL) {
            php_url_free(parsed_url);
        }

        king_websocket_set_error(
            "%s() requires a valid absolute ws:// or wss:// URL.",
            function_name
        );
        return FAILURE;
    }

    if (
        strcasecmp(ZSTR_VAL(parsed_url->scheme), "ws") != 0
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") != 0
    ) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() currently supports only absolute ws:// and wss:// URLs.",
            function_name
        );
        return FAILURE;
    }

    if (parsed_url->user != NULL || parsed_url->pass != NULL) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() does not support embedding credentials in the connection URL.",
            function_name
        );
        return FAILURE;
    }

    if (parsed_url->fragment != NULL) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() does not support URL fragments in the connection URL.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_websocket_string_has_crlf(
            ZSTR_VAL(parsed_url->host),
            ZSTR_LEN(parsed_url->host)
        )
    ) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() URL host contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    *parsed_url_out = parsed_url;
    return SUCCESS;
}

static zend_result king_websocket_build_request_target(
    php_url *parsed_url,
    const char *function_name,
    zend_string **request_target_out
)
{
    smart_str request_target = {0};
    const char *path;
    size_t path_len;

    path = parsed_url->path != NULL ? ZSTR_VAL(parsed_url->path) : "/";
    path_len = parsed_url->path != NULL ? ZSTR_LEN(parsed_url->path) : 1;

    if (path_len == 0) {
        path = "/";
        path_len = 1;
    }

    if (king_websocket_string_has_crlf(path, path_len)) {
        king_websocket_set_error(
            "%s() URL path contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    smart_str_appendl(&request_target, path, path_len);

    if (parsed_url->query != NULL && ZSTR_LEN(parsed_url->query) > 0) {
        if (
            king_websocket_string_has_crlf(
                ZSTR_VAL(parsed_url->query),
                ZSTR_LEN(parsed_url->query)
            )
        ) {
            smart_str_free(&request_target);
            king_websocket_set_error(
                "%s() URL query contains invalid line breaks.",
                function_name
            );
            return FAILURE;
        }

        smart_str_appendc(&request_target, '?');
        smart_str_append(&request_target, parsed_url->query);
    }

    smart_str_0(&request_target);
    *request_target_out = request_target.s;
    return SUCCESS;
}

static zend_result king_websocket_apply_options(
    king_ws_state *state,
    zval *options_array,
    const char *function_name
)
{
    king_cfg_t *config = NULL;
    zval *option_value;

    state->max_payload_size =
        king_app_protocols_config.websocket_default_max_payload_size > 0
            ? king_app_protocols_config.websocket_default_max_payload_size
            : 16777216;
    state->ping_interval_ms =
        king_app_protocols_config.websocket_default_ping_interval_ms > 0
            ? king_app_protocols_config.websocket_default_ping_interval_ms
            : 25000;
    state->handshake_timeout_ms =
        king_app_protocols_config.websocket_handshake_timeout_ms > 0
            ? king_app_protocols_config.websocket_handshake_timeout_ms
            : 5000;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connection_config",
        sizeof("connection_config") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        config = (king_cfg_t *) king_fetch_config(option_value);
        if (config == NULL) {
            king_websocket_set_error(
                "%s() option 'connection_config' must be a King\\Config resource or object.",
                function_name
            );
            return FAILURE;
        }

        ZVAL_COPY(&state->config, option_value);
        state->max_payload_size = config->app_protocols.websocket_default_max_payload_size;
        state->ping_interval_ms = config->app_protocols.websocket_default_ping_interval_ms;
        state->handshake_timeout_ms = config->app_protocols.websocket_handshake_timeout_ms;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_payload_size",
        sizeof("max_payload_size") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "max_payload_size",
                &state->max_payload_size,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "ping_interval_ms",
        sizeof("ping_interval_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "ping_interval_ms",
                &state->ping_interval_ms,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "handshake_timeout_ms",
        sizeof("handshake_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "handshake_timeout_ms",
                &state->handshake_timeout_ms,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

static king_ws_state *king_websocket_state_create(
    const char *url_str,
    size_t url_len,
    zval *headers_array,
    zval *options_array,
    const char *function_name
)
{
    php_url *parsed_url = NULL;
    zend_string *request_target = NULL;
    king_ws_state *state = NULL;

    if (
        king_websocket_parse_url(
            url_str,
            url_len,
            function_name,
            &parsed_url
        ) != SUCCESS
    ) {
        return NULL;
    }

    if (
        king_websocket_build_request_target(
            parsed_url,
            function_name,
            &request_target
        ) != SUCCESS
    ) {
        php_url_free(parsed_url);
        return NULL;
    }

    state = ecalloc(1, sizeof(*state));
    state->url = zend_string_init(url_str, url_len, 0);
    state->scheme = zend_string_copy(parsed_url->scheme);
    state->host = zend_string_copy(parsed_url->host);
    state->request_target = request_target;
    state->port = king_websocket_resolve_default_port(parsed_url);
    state->state = KING_WS_STATE_OPEN;
    state->last_close_status_code = 1000;
    state->secure = strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") == 0;
    state->handshake_complete = false;
    state->closed = false;
    ZVAL_UNDEF(&state->config);
    ZVAL_UNDEF(&state->headers);

    if (
        king_websocket_apply_options(
            state,
            options_array,
            function_name
        ) != SUCCESS
    ) {
        king_ws_state_free(state);
        php_url_free(parsed_url);
        return NULL;
    }

    if (headers_array != NULL && Z_TYPE_P(headers_array) != IS_NULL) {
        ZVAL_COPY(&state->headers, headers_array);
    }

    php_url_free(parsed_url);
    king_set_error("");
    return state;
}

void king_ws_state_free(king_ws_state *state)
{
    if (state == NULL) {
        return;
    }

    if (state->url != NULL) {
        zend_string_release(state->url);
    }
    if (state->scheme != NULL) {
        zend_string_release(state->scheme);
    }
    if (state->host != NULL) {
        zend_string_release(state->host);
    }
    if (state->request_target != NULL) {
        zend_string_release(state->request_target);
    }
    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    if (!Z_ISUNDEF(state->config)) {
        zval_ptr_dtor(&state->config);
    }
    if (!Z_ISUNDEF(state->headers)) {
        zval_ptr_dtor(&state->headers);
    }
    king_websocket_message_queue_clear(state);

    efree(state);
}

static void king_websocket_info_append_values(zval *target, zval *value)
{
    if (Z_TYPE_P(value) == IS_ARRAY) {
        zval *entry;

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry)
        {
            add_next_index_str(target, zval_get_string(entry));
        }
        ZEND_HASH_FOREACH_END();
        return;
    }

    add_next_index_str(target, zval_get_string(value));
}

static void king_websocket_build_info_array(zval *return_value, king_ws_state *state)
{
    zval headers;

    array_init(return_value);
    add_assoc_str(return_value, "id", zend_string_copy(state->url));
    add_assoc_str(
        return_value,
        "remote_addr",
        strpprintf(0, "%s:%ld", ZSTR_VAL(state->host), state->port)
    );
    add_assoc_str(return_value, "protocol", zend_string_copy(state->scheme));

    array_init(&headers);
    if (!Z_ISUNDEF(state->headers) && Z_TYPE(state->headers) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(state->headers), header_name, header_value)
        {
            zval normalized;

            if (header_name == NULL) {
                continue;
            }

            array_init(&normalized);
            king_websocket_info_append_values(&normalized, header_value);
            zend_hash_update(Z_ARRVAL(headers), header_name, &normalized);
        }
        ZEND_HASH_FOREACH_END();
    }
    add_assoc_zval(return_value, "headers", &headers);
}

static void king_websocket_throw_last_error(
    zend_class_entry *exception_ce,
    const char *fallback
)
{
    zend_throw_exception_ex(
        exception_ce,
        0,
        "%s",
        king_get_error()[0] != '\0' ? king_get_error() : fallback
    );
}

static zend_result king_websocket_object_send_internal(
    zval *object,
    zend_string *payload,
    bool is_binary,
    const char *function_name
)
{
    king_ws_state *state = king_websocket_object_fetch_state(object, function_name);

    if (state == NULL) {
        return FAILURE;
    }

    if (king_websocket_require_open(state, function_name) != SUCCESS) {
        king_websocket_throw_last_error(
            king_ce_ws_closed,
            "WebSocket connection is closed."
        );
        return FAILURE;
    }

    if (ZSTR_LEN(payload) > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "%s() payload size %zu exceeds max_payload_size %ld.",
            function_name,
            ZSTR_LEN(payload),
            state->max_payload_size
        );
        king_websocket_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket frame exceeds the configured max payload size."
        );
        return FAILURE;
    }

    if (
        king_websocket_message_queue_push(
            state,
            ZSTR_VAL(payload),
            ZSTR_LEN(payload),
            is_binary
        ) != SUCCESS
    ) {
        king_websocket_set_error("%s() failed to queue the local WebSocket frame.", function_name);
        king_websocket_throw_last_error(
            king_ce_system_exception,
            "Failed to queue the local WebSocket frame."
        );
        return FAILURE;
    }

    king_set_error("");
    return SUCCESS;
}

PHP_METHOD(King_WebSocket_Connection, __construct)
{
    char *url_str = NULL;
    size_t url_len = 0;
    zval *headers_array = NULL;
    zval *options_array = NULL;
    king_ws_state *state;
    king_ws_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_state_create(
        url_str,
        url_len,
        headers_array,
        options_array,
        "WebSocket\\Connection::__construct"
    );
    if (state == NULL) {
        king_websocket_throw_last_error(
            king_ce_validation_exception,
            "King\\WebSocket\\Connection construction failed."
        );
        RETURN_THROWS();
    }

    intern = php_king_ws_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(state, le_king_ws));
    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, send)
{
    zend_string *message;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_websocket_object_send_internal(
            ZEND_THIS,
            message,
            false,
            "WebSocket\\Connection::send"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Connection, sendBinary)
{
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_websocket_object_send_internal(
            ZEND_THIS,
            payload,
            true,
            "WebSocket\\Connection::sendBinary"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Connection, ping)
{
    zend_string *payload_str = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(payload_str)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::ping");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_websocket_require_open(state, "WebSocket\\Connection::ping") != SUCCESS) {
        king_websocket_throw_last_error(
            king_ce_ws_closed,
            "WebSocket connection is closed."
        );
        RETURN_THROWS();
    }

    if (payload_str != NULL && ZSTR_LEN(payload_str) > KING_WS_PING_MAX_PAYLOAD_LEN) {
        king_websocket_set_error(
            "WebSocket\\Connection::ping() ping payload cannot exceed %d bytes.",
            KING_WS_PING_MAX_PAYLOAD_LEN
        );
        king_websocket_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket ping payload exceeds the protocol limit."
        );
        RETURN_THROWS();
    }

    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    state->last_ping_payload = payload_str != NULL
        ? zend_string_copy(payload_str)
        : zend_string_init("", 0, 0);
    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, close)
{
    zend_long status_code = 1000;
    zend_string *reason = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(status_code)
        Z_PARAM_STR_OR_NULL(reason)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::close");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->state == KING_WS_STATE_CLOSED || state->closed) {
        king_set_error("");
        return;
    }

    if (
        king_websocket_validate_close_inputs(
            status_code,
            reason != NULL ? ZSTR_LEN(reason) : 0,
            "WebSocket\\Connection::close"
        ) != SUCCESS
    ) {
        king_websocket_throw_last_error(
            king_ce_validation_exception,
            "WebSocket close input validation failed."
        );
        RETURN_THROWS();
    }

    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    state->last_close_reason = reason != NULL
        ? zend_string_copy(reason)
        : zend_string_init("", 0, 0);
    state->last_close_status_code = status_code;
    state->state = KING_WS_STATE_CLOSED;
    state->closed = true;
    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, getInfo)
{
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_NONE();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::getInfo");
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_websocket_build_info_array(return_value, state);
    king_set_error("");
}

PHP_FUNCTION(king_client_websocket_connect)
{
    char *url_str = NULL;
    size_t url_len = 0;
    zval *headers_array = NULL;
    zval *options_array = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_state_create(
        url_str,
        url_len,
        headers_array,
        options_array,
        "king_client_websocket_connect"
    );
    if (state == NULL) {
        RETURN_FALSE;
    }

    RETURN_RES(zend_register_resource(state, le_king_ws));
}

PHP_FUNCTION(king_client_websocket_send)
{
    zval *websocket;
    char *data = NULL;
    size_t data_len = 0;
    zend_bool is_binary = false;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_STRING(data, data_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_binary)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_client_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (data_len > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "king_client_websocket_send() payload size %zu exceeds max_payload_size %ld.",
            data_len,
            state->max_payload_size
        );
        RETURN_FALSE;
    }

    if (
        king_websocket_message_queue_push(
            state,
            data,
            data_len,
            is_binary != 0
        ) != SUCCESS
    ) {
        king_websocket_set_error(
            "king_client_websocket_send() failed to queue the local WebSocket frame."
        );
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_websocket_send)
{
    zval *websocket;
    char *message = NULL;
    size_t message_len = 0;
    zend_bool is_binary = false;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_STRING(message, message_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_binary)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (message_len > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "king_websocket_send() payload size %zu exceeds max_payload_size %ld.",
            message_len,
            state->max_payload_size
        );
        RETURN_FALSE;
    }

    if (
        king_websocket_message_queue_push(
            state,
            message,
            message_len,
            is_binary != 0
        ) != SUCCESS
    ) {
        king_websocket_set_error(
            "king_websocket_send() failed to queue the local WebSocket frame."
        );
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_client_websocket_receive)
{
    zval *websocket;
    zend_long timeout_ms = -1;
    king_ws_state *state;
    king_ws_message *message;
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (timeout_ms < -1) {
        king_websocket_set_error(
            "king_client_websocket_receive() timeout_ms must be -1 or >= 0."
        );
        RETURN_FALSE;
    }

    message = king_websocket_message_queue_shift(state);
    if (message != NULL) {
        payload = message->payload;
        efree(message);
        king_set_error("");
        RETURN_STR(payload);
    }

    if (state->state == KING_WS_STATE_OPEN && !state->closed) {
        king_set_error("");
        RETURN_EMPTY_STRING();
    }

    king_websocket_set_error(
        "king_client_websocket_receive() cannot run on a closed WebSocket connection."
    );
    RETURN_FALSE;
}

PHP_FUNCTION(king_client_websocket_ping)
{
    zval *websocket;
    char *payload = "";
    size_t payload_len = 0;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(payload, payload_len)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_client_websocket_ping"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (payload_len > KING_WS_PING_MAX_PAYLOAD_LEN) {
        king_websocket_set_error(
            "king_client_websocket_ping() ping payload cannot exceed %d bytes.",
            KING_WS_PING_MAX_PAYLOAD_LEN
        );
        RETURN_FALSE;
    }

    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    state->last_ping_payload = zend_string_init(payload, payload_len, 0);

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_client_websocket_get_status)
{
    zval *websocket;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(websocket)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_set_error("");
    RETURN_LONG((zend_long) state->state);
}

PHP_FUNCTION(king_client_websocket_close)
{
    zval *websocket;
    zend_long status_code = 1000;
    char *reason = "";
    size_t reason_len = 0;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(status_code)
        Z_PARAM_STRING(reason, reason_len)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->state == KING_WS_STATE_CLOSED || state->closed) {
        king_set_error("");
        RETURN_TRUE;
    }

    if (
        king_websocket_validate_close_inputs(
            status_code,
            reason_len,
            "king_client_websocket_close"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    state->last_close_reason = zend_string_init(reason, reason_len, 0);
    state->last_close_status_code = status_code;
    state->state = KING_WS_STATE_CLOSED;
    state->closed = true;

    king_set_error("");
    RETURN_TRUE;
}

const zend_function_entry king_ws_connection_class_methods[] = {
    PHP_ME(King_WebSocket_Connection, __construct, arginfo_class_King_WebSocket_Connection___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_WebSocket_Connection, send, arginfo_class_King_WebSocket_Connection_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, sendBinary, arginfo_class_King_WebSocket_Connection_sendBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, ping, arginfo_class_King_WebSocket_Connection_ping, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, close, arginfo_class_King_WebSocket_Connection_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, getInfo, arginfo_class_King_WebSocket_Connection_getInfo, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
