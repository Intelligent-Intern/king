#include "main/php_streams.h"
#include "ext/standard/base64.h"
#include "include/mcp/mcp.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/config/mcp_and_orchestrator/default.h"
#include <time.h>


ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_MCP___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_request, 0, 3, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_uploadFromStream, 0, 4, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, streamIdentifier, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_downloadToStream, 0, 4, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

static zend_result king_mcp_set_transfer_error(
    zend_class_entry *exception_ce,
    const char *message)
{
    king_set_error(message);

    if (exception_ce != NULL) {
        zend_throw_exception_ex(exception_ce, 0, "%s", message);
    }

    return FAILURE;
}

static uint64_t king_monotonic_time_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return ((uint64_t) ts.tv_sec * 1000ULL) + ((uint64_t) ts.tv_nsec / 1000000ULL);
}

static zend_result king_mcp_validate_runtime_control_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name,
    zend_class_entry *exception_ce)
{
    char message[KING_ERR_LEN];

    if (Z_TYPE_P(value) != IS_LONG) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (Z_LVAL_P(value) <= 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_mcp_runtime_control_parse(
    zval *options,
    zval *explicit_cancel,
    const char *function_name,
    zend_class_entry *exception_ce,
    king_mcp_runtime_control_t *control)
{
    zval *option_value;
    zval *options_cancel = NULL;
    char message[KING_ERR_LEN];
    zend_long deadline_ms = 0;

    if (control == NULL) {
        return FAILURE;
    }

    control->timeout_ms = king_mcp_orchestrator_config.mcp_default_request_timeout_ms > 0
        ? king_mcp_orchestrator_config.mcp_default_request_timeout_ms
        : 0;
    control->deadline_ms = 0;
    control->started_at_ms = king_monotonic_time_ms();
    control->cancel_token = NULL;

    if (options != NULL && Z_TYPE_P(options) == IS_ARRAY) {
        option_value = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "timeout_ms",
            sizeof("timeout_ms") - 1
        );
        if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
            if (king_mcp_validate_runtime_control_option(
                    option_value,
                    "timeout_ms",
                    &control->timeout_ms,
                    function_name,
                    exception_ce
                ) != SUCCESS) {
                return FAILURE;
            }
        }

        option_value = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "deadline_ms",
            sizeof("deadline_ms") - 1
        );
        if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
            if (king_mcp_validate_runtime_control_option(
                    option_value,
                    "deadline_ms",
                    &deadline_ms,
                    function_name,
                    exception_ce
                ) != SUCCESS) {
                return FAILURE;
            }
            control->deadline_ms = (uint64_t) deadline_ms;
        }

        option_value = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "cancel",
            sizeof("cancel") - 1
        );
        if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
            if (
                Z_TYPE_P(option_value) != IS_OBJECT
                || !instanceof_function(Z_OBJCE_P(option_value), king_ce_cancel_token)
            ) {
                snprintf(
                    message,
                    sizeof(message),
                    "%s() option 'cancel' must be null or King\\CancelToken.",
                    function_name
                );
                return king_mcp_set_transfer_error(exception_ce, message);
            }

            options_cancel = option_value;
        }
    }

    if (explicit_cancel != NULL && Z_TYPE_P(explicit_cancel) != IS_NULL) {
        if (options_cancel != NULL) {
            snprintf(
                message,
                sizeof(message),
                "%s() must not receive a CancelToken in both the dedicated argument and options['cancel'].",
                function_name
            );
            return king_mcp_set_transfer_error(exception_ce, message);
        }

        control->cancel_token = explicit_cancel;
    } else {
        control->cancel_token = options_cancel;
    }

    return SUCCESS;
}

static zend_result king_mcp_runtime_control_check(
    king_mcp_runtime_control_t *control,
    const char *function_name,
    zend_class_entry *timeout_exception_ce,
    zend_class_entry *cancel_exception_ce)
{
    char message[KING_ERR_LEN];
    uint64_t now_ms;

    if (control == NULL) {
        return SUCCESS;
    }

    king_process_pending_interrupts();

    if (king_transport_cancel_token_is_cancelled(control->cancel_token)) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active MCP operation via CancelToken.",
            function_name
        );
        return king_mcp_set_transfer_error(cancel_exception_ce, message);
    }

    now_ms = king_monotonic_time_ms();
    if (
        control->timeout_ms > 0
        && now_ms - control->started_at_ms >= (uint64_t) control->timeout_ms
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active MCP timeout budget.",
            function_name
        );
        return king_mcp_set_transfer_error(timeout_exception_ce, message);
    }

    if (control->deadline_ms > 0 && now_ms >= control->deadline_ms) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active MCP deadline budget.",
            function_name
        );
        return king_mcp_set_transfer_error(timeout_exception_ce, message);
    }

    return SUCCESS;
}

static zend_string *king_mcp_base64url_encode_bytes(
    const unsigned char *value,
    size_t value_len)
{
    zend_string *encoded = php_base64_encode(value, value_len);
    size_t read_index = 0;
    size_t write_index = 0;

    if (encoded == NULL) {
        return NULL;
    }

    for (read_index = 0; read_index < ZSTR_LEN(encoded); read_index++) {
        char current = ZSTR_VAL(encoded)[read_index];

        if (current == '=') {
            continue;
        }
        if (current == '+') {
            current = '-';
        } else if (current == '/') {
            current = '_';
        }

        ZSTR_VAL(encoded)[write_index++] = current;
    }

    ZSTR_VAL(encoded)[write_index] = '\0';
    ZSTR_LEN(encoded) = write_index;

    return encoded;
}

static zend_string *king_mcp_transfer_key_create(
    const char *service_name,
    size_t service_name_len,
    const char *method_name,
    size_t method_name_len,
    const char *transfer_id,
    size_t transfer_id_len)
{
    zend_string *encoded_service = NULL;
    zend_string *encoded_method = NULL;
    zend_string *encoded_id = NULL;
    size_t key_len;
    zend_string *key = NULL;
    char *cursor;

    encoded_service = king_mcp_base64url_encode_bytes((const unsigned char *) service_name, service_name_len);
    encoded_method = king_mcp_base64url_encode_bytes((const unsigned char *) method_name, method_name_len);
    encoded_id = king_mcp_base64url_encode_bytes((const unsigned char *) transfer_id, transfer_id_len);
    if (encoded_service == NULL || encoded_method == NULL || encoded_id == NULL) {
        if (encoded_service != NULL) {
            zend_string_release(encoded_service);
        }
        if (encoded_method != NULL) {
            zend_string_release(encoded_method);
        }
        if (encoded_id != NULL) {
            zend_string_release(encoded_id);
        }
        return NULL;
    }

    key_len = (sizeof("mcp.v1.") - 1)
        + ZSTR_LEN(encoded_service)
        + 1
        + ZSTR_LEN(encoded_method)
        + 1
        + ZSTR_LEN(encoded_id);
    key = zend_string_alloc(key_len, 0);
    cursor = ZSTR_VAL(key);

    memcpy(cursor, "mcp.v1.", sizeof("mcp.v1.") - 1);
    cursor += sizeof("mcp.v1.") - 1;
    memcpy(cursor, ZSTR_VAL(encoded_service), ZSTR_LEN(encoded_service));
    cursor += ZSTR_LEN(encoded_service);
    *cursor++ = '.';
    memcpy(cursor, ZSTR_VAL(encoded_method), ZSTR_LEN(encoded_method));
    cursor += ZSTR_LEN(encoded_method);
    *cursor++ = '.';
    memcpy(cursor, ZSTR_VAL(encoded_id), ZSTR_LEN(encoded_id));
    ZSTR_VAL(key)[key_len] = '\0';

    zend_string_release(encoded_service);
    zend_string_release(encoded_method);
    zend_string_release(encoded_id);

    return key;
}

static bool king_mcp_contains_path_separator(
    const char *value,
    size_t value_len)
{
    size_t i;

    for (i = 0; i < value_len; i++) {
        if (value[i] == '/' || value[i] == '\\') {
            return true;
        }
    }

    return false;
}

static zend_result king_mcp_validate_transfer_args(
    const char *service_name,
    size_t service_name_len,
    const char *method_name,
    size_t method_name_len,
    const char *identifier_name,
    const char *identifier_value,
    size_t identifier_value_len,
    const char *function_name,
    zend_class_entry *exception_ce)
{
    char message[KING_ERR_LEN];
    size_t backend_object_id_len;
    zend_string *encoded_key = NULL;

    if (service_name_len == 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() requires a non-empty service name.",
            function_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (method_name_len == 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() requires a non-empty method name.",
            function_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (identifier_value_len == 0 || identifier_value_len > 256) {
        snprintf(
            message,
            sizeof(message),
            "%s() requires a valid %s (1-256 bytes).",
            function_name,
            identifier_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (service_name_len > 128 || method_name_len > 128) {
        snprintf(
            message,
            sizeof(message),
            "%s() service/method names exceed the maximum length (128 bytes).",
            function_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (king_mcp_contains_path_separator(service_name, service_name_len)) {
        snprintf(
            message,
            sizeof(message),
            "%s() service name must not contain path separator characters.",
            function_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (king_mcp_contains_path_separator(method_name, method_name_len)) {
        snprintf(
            message,
            sizeof(message),
            "%s() method name must not contain path separator characters.",
            function_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    if (king_mcp_contains_path_separator(identifier_value, identifier_value_len)) {
        snprintf(
            message,
            sizeof(message),
            "%s() %s must not contain path separator characters.",
            function_name,
            identifier_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    encoded_key = king_mcp_transfer_key_create(
        service_name,
        service_name_len,
        method_name,
        method_name_len,
        identifier_value,
        identifier_value_len
    );
    if (encoded_key == NULL) {
        snprintf(
            message,
            sizeof(message),
            "%s() failed while encoding the MCP transfer identifier.",
            function_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    backend_object_id_len = ZSTR_LEN(encoded_key);
    if (backend_object_id_len > 127) {
        zend_string_release(encoded_key);
        snprintf(
            message,
            sizeof(message),
            "%s() combined service, method, and %s exceed the MCP transfer key limit (127 bytes).",
            function_name,
            identifier_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

    zend_string_release(encoded_key);
    return SUCCESS;
}

static php_stream *king_mcp_fetch_stream_resource(zval *stream_zv, uint32_t arg_num)
{
    php_stream *stream = NULL;

    if (Z_TYPE_P(stream_zv) != IS_RESOURCE) {
        zend_argument_type_error(
            arg_num,
            "must be of type resource, %s given",
            zend_zval_type_name(stream_zv)
        );
        return NULL;
    }

    php_stream_from_zval_no_verify(stream, stream_zv);
    if (stream == NULL) {
        zend_argument_type_error(arg_num, "must be a valid stream resource");
        return NULL;
    }

    return stream;
}

static zend_string *king_mcp_read_stream_payload(
    php_stream *stream,
    const char *function_name,
    zend_class_entry *timeout_exception_ce,
    zend_class_entry *cancel_exception_ce,
    king_mcp_runtime_control_t *control)
{
    char chunk[8192];
    ssize_t bytes_read = 0;
    size_t used = 0;
    zend_string *buffer = NULL;
    char message[KING_ERR_LEN];

    if (king_mcp_runtime_control_check(control, function_name, timeout_exception_ce, cancel_exception_ce) != SUCCESS) {
        return NULL;
    }

    do {
        if (king_mcp_runtime_control_check(control, function_name, timeout_exception_ce, cancel_exception_ce) != SUCCESS) {
            if (buffer != NULL) {
                zend_string_release(buffer);
            }
            return NULL;
        }

        bytes_read = php_stream_read(stream, chunk, sizeof(chunk));
        if (bytes_read > 0) {
            size_t next_used = used + (size_t) bytes_read;

            if (king_mcp_orchestrator_config.mcp_max_message_size_bytes > 0 &&
                next_used > (size_t) king_mcp_orchestrator_config.mcp_max_message_size_bytes) {
                if (buffer != NULL) {
                    zend_string_release(buffer);
                }
                snprintf(
                    message,
                    sizeof(message),
                    "%s() failed: maximum message size (%ld bytes) exceeded.",
                    function_name,
                    king_mcp_orchestrator_config.mcp_max_message_size_bytes
                );
                king_mcp_set_transfer_error(cancel_exception_ce, message);
                return NULL;
            }

            if (buffer == NULL) {
                buffer = zend_string_alloc(next_used, 0);
            } else {
                buffer = zend_string_extend(buffer, next_used, 0);
            }

            memcpy(ZSTR_VAL(buffer) + used, chunk, (size_t) bytes_read);
            used = next_used;
        }
    } while (bytes_read > 0);

    if (bytes_read < 0) {
        if (buffer != NULL) {
            zend_string_release(buffer);
        }
        snprintf(
            message,
            sizeof(message),
            "%s() failed while reading the source stream.",
            function_name
        );
        king_mcp_set_transfer_error(cancel_exception_ce, message);
        return NULL;
    }

    if (buffer == NULL) {
        return zend_string_init("", 0, 0);
    }

    ZSTR_VAL(buffer)[used] = '\0';
    ZSTR_LEN(buffer) = used;

    return buffer;
}

static zend_result king_mcp_write_payload_to_stream(
    php_stream *stream,
    zend_string *payload,
    const char *function_name,
    zend_class_entry *timeout_exception_ce,
    zend_class_entry *cancel_exception_ce,
    king_mcp_runtime_control_t *control)
{
    size_t offset = 0;
    ssize_t bytes_written = 0;
    char message[KING_ERR_LEN];

    while (offset < ZSTR_LEN(payload)) {
        if (king_mcp_runtime_control_check(control, function_name, timeout_exception_ce, cancel_exception_ce) != SUCCESS) {
            return FAILURE;
        }

        bytes_written = php_stream_write(
            stream,
            ZSTR_VAL(payload) + offset,
            ZSTR_LEN(payload) - offset
        );

        if (bytes_written <= 0) {
            snprintf(
                message,
                sizeof(message),
                "%s() failed while writing to the destination stream.",
                function_name
            );
            return king_mcp_set_transfer_error(cancel_exception_ce, message);
        }

        offset += (size_t) bytes_written;
    }

    return SUCCESS;
}

/* Native transitions: delegated to src/mcp/mcp.c */
static king_mcp_state *king_mcp_fetch_state(zval *zconnection, uint32_t arg_num)
{
    if (
        Z_TYPE_P(zconnection) == IS_OBJECT
        && king_ce_mcp != NULL
        && instanceof_function(Z_OBJCE_P(zconnection), king_ce_mcp)
    ) {
        king_mcp_object *intern = php_king_mcp_obj_from_zend(Z_OBJ_P(zconnection));

        if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
            zend_argument_type_error(
                arg_num,
                "must be a valid MCP resource or King\\MCP object"
            );
            return NULL;
        }

        zconnection = &intern->resource;
    }

    if (Z_TYPE_P(zconnection) != IS_RESOURCE) {
        zend_argument_type_error(
            arg_num,
            "must be of type resource or King\\MCP, %s given",
            zend_zval_type_name(zconnection)
        );
        return NULL;
    }

    return zend_fetch_resource(Z_RES_P(zconnection), "King\\MCP", le_king_mcp);
}

static king_mcp_state *king_mcp_object_fetch_state(
    zval *object,
    const char *function_name)
{
    king_mcp_object *intern = php_king_mcp_obj_from_zend(Z_OBJ_P(object));

    if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        king_set_error("King\\MCP object is not initialized.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() requires an initialized King\\MCP object.",
            function_name
        );
        return NULL;
    }

    return zend_fetch_resource(Z_RES(intern->resource), "King\\MCP", le_king_mcp);
}

static zend_result king_mcp_state_require_open(
    king_mcp_state *state,
    const char *function_name)
{
    char message[KING_ERR_LEN];

    if (!state->closed) {
        return SUCCESS;
    }

    snprintf(
        message,
        sizeof(message),
        "%s() cannot run on a closed MCP connection.",
        function_name
    );
    king_set_error(message);
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() cannot run on a closed MCP connection.",
        function_name
    );
    return FAILURE;
}

static zend_class_entry *king_mcp_exception_class_for_state_error(
    king_mcp_state *state,
    zend_class_entry *fallback_ce
)
{
    if (state == NULL) {
        return fallback_ce;
    }

    switch (state->last_error_kind) {
        case KING_MCP_ERROR_TRANSPORT:
            return king_ce_mcp_connection_error;
        case KING_MCP_ERROR_PROTOCOL:
            return king_ce_mcp_protocol_error;
        case KING_MCP_ERROR_BACKEND:
            return king_ce_mcp_data_error;
        case KING_MCP_ERROR_NONE:
        default:
            return fallback_ce;
    }
}

static void king_mcp_throw_state_error(
    king_mcp_state *state,
    zend_class_entry *fallback_ce,
    const char *fallback_message
)
{
    zend_class_entry *exception_ce = king_mcp_exception_class_for_state_error(state, fallback_ce);
    const char *message =
        king_get_error()[0] != '\0'
            ? king_get_error()
            : fallback_message;

    zend_throw_exception_ex(exception_ce, 0, "%s", message);
}

#include "include/king_globals.h"

PHP_FUNCTION(king_mcp_connect)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *options = NULL;
    king_mcp_state *state;

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_TYPE_P(config) == IS_ARRAY) {
        if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config)) > 0) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "Configuration override is disabled by system policy."
            );
            RETURN_THROWS();
        }
    }

    (void) options;

    state = king_mcp_state_create(
        host,
        host_len,
        port,
        config
    );
    if (state == NULL) {
        RETURN_FALSE;
    }

    RETURN_RES(zend_register_resource(state, le_king_mcp));
}

PHP_FUNCTION(king_mcp_request)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    zend_string *request_payload_str;
    zend_string *response_payload;
    zval *options = NULL;
    king_mcp_state *state;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STR(request_payload_str)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    (void) options;

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->closed) {
        king_set_error("king_mcp_request() cannot run on a closed MCP connection.");
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_parse(
            options,
            NULL,
            "king_mcp_request",
            NULL,
            &control
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_request",
            NULL,
            NULL
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_request(
            state,
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            request_payload_str,
            &response_payload,
            &control
        ) != SUCCESS) {
        if (king_mcp_runtime_control_check(
                &control,
                "king_mcp_request",
                NULL,
                NULL
            ) != SUCCESS) {
            RETURN_FALSE;
        }
        RETURN_FALSE;
    }

    if (response_payload == NULL) {
        state->last_error_kind = KING_MCP_ERROR_PROTOCOL;
        king_set_error("king_mcp_request() received an invalid empty response payload from the remote MCP peer.");
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_request",
            NULL,
            NULL
        ) != SUCCESS) {
        zend_string_release(response_payload);
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_STR(response_payload);
}

PHP_FUNCTION(king_mcp_close)
{
    zval *connection;
    king_mcp_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(connection)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_mcp_state_close(state);
    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_mcp_upload_from_stream)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    char *stream_identifier = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    size_t stream_identifier_len = 0;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(5, 6)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STRING(stream_identifier, stream_identifier_len)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->closed) {
        king_set_error("king_mcp_upload_from_stream() cannot run on a closed MCP connection.");
        RETURN_FALSE;
    }

    if (
        king_mcp_validate_transfer_args(
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            "stream identifier",
            stream_identifier,
            stream_identifier_len,
            "king_mcp_upload_from_stream",
            NULL
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 5);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            NULL,
            "king_mcp_upload_from_stream",
            NULL,
            &control
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    payload = king_mcp_read_stream_payload(stream, "king_mcp_upload_from_stream", NULL, NULL, &control);
    if (payload == NULL) {
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_upload_from_stream",
            NULL,
            NULL
        ) != SUCCESS) {
        zend_string_release(payload);
        RETURN_FALSE;
    }

    if (king_mcp_transfer_store(
        state,
        service_name,
        service_name_len,
        method_name,
        method_name_len,
        stream_identifier,
        stream_identifier_len,
        payload,
        &control
    ) != SUCCESS) {
        zend_string_release(payload);
        if (king_mcp_runtime_control_check(
                &control,
                "king_mcp_upload_from_stream",
                NULL,
                NULL
            ) != SUCCESS) {
            RETURN_FALSE;
        }
        if (king_get_error()[0] == '\0') {
            king_set_error("king_mcp_upload_from_stream() failed to persist the MCP transfer state.");
        }
        RETURN_FALSE;
    }

    zend_string_release(payload);
    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_mcp_download_to_stream)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    char *request_payload = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    size_t request_payload_len = 0;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(5, 6)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STRING(request_payload, request_payload_len)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->closed) {
        king_set_error("king_mcp_download_to_stream() cannot run on a closed MCP connection.");
        RETURN_FALSE;
    }

    if (
        king_mcp_validate_transfer_args(
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            "request payload",
            request_payload,
            request_payload_len,
            "king_mcp_download_to_stream",
            NULL
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 5);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            NULL,
            "king_mcp_download_to_stream",
            NULL,
            &control
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_download_to_stream",
            NULL,
            NULL
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    payload = king_mcp_transfer_find(
        state,
        service_name,
        service_name_len,
        method_name,
        method_name_len,
        request_payload,
        request_payload_len,
        &control
    );
    if (payload == NULL) {
        if (king_mcp_runtime_control_check(
                &control,
                "king_mcp_download_to_stream",
                NULL,
                NULL
            ) != SUCCESS) {
            RETURN_FALSE;
        }
        if (king_get_error()[0] == '\0') {
            king_set_error(
                "king_mcp_download_to_stream() could not find an MCP transfer for the requested payload identifier."
            );
        }
        RETURN_FALSE;
    }

    if (
        king_mcp_write_payload_to_stream(
            stream,
            payload,
            "king_mcp_download_to_stream",
            NULL,
            NULL,
            &control
        ) != SUCCESS
    ) {
        zend_string_release(payload);
        RETURN_FALSE;
    }

    if (king_mcp_transfer_acknowledge(
            state,
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            request_payload,
            request_payload_len
        ) != SUCCESS) {
        zend_string_release(payload);
        RETURN_FALSE;
    }

    zend_string_release(payload);
    king_set_error("");
    RETURN_TRUE;
}

PHP_METHOD(King_MCP, __construct)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config = NULL;
    king_mcp_state *state;
    king_mcp_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(config, king_ce_config)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_state_create(
        host,
        host_len,
        port,
        config
    );
    if (state == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s",
            king_get_error()[0] != '\0'
                ? king_get_error()
                : "King\\MCP construction failed."
        );
        RETURN_THROWS();
    }

    intern = php_king_mcp_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(state, le_king_mcp));
    king_set_error("");
}

PHP_METHOD(King_MCP, request)
{
    char *service = NULL;
    char *method = NULL;
    size_t service_len = 0;
    size_t method_len = 0;
    zend_string *response_payload;
    king_mcp_state *state;
    zend_string *payload;
    zval *cancel = NULL;
    zval *options = NULL;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(3, 5)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STR(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::request");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_state_require_open(state, "MCP::request") != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            cancel,
            "MCP::request",
            king_ce_validation_exception,
            &control
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::request",
            king_ce_mcp_timeout,
            king_ce_runtime_exception
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_request(
            state,
            service,
            service_len,
            method,
            method_len,
            payload,
            &response_payload,
            &control
        ) != SUCCESS) {
        if (king_mcp_runtime_control_check(
                &control,
                "MCP::request",
                king_ce_mcp_timeout,
                king_ce_runtime_exception
            ) != SUCCESS) {
            RETURN_THROWS();
        }
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_connection_error,
            "MCP::request() failed to exchange the remote MCP request."
        );
        RETURN_THROWS();
    }

    if (response_payload == NULL) {
        state->last_error_kind = KING_MCP_ERROR_PROTOCOL;
        king_set_error("MCP::request() received an invalid empty response payload from the remote MCP peer.");
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_protocol_error,
            "MCP::request() received an invalid empty response payload from the remote MCP peer."
        );
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::request",
            king_ce_mcp_timeout,
            king_ce_runtime_exception
        ) != SUCCESS) {
        zend_string_release(response_payload);
        RETURN_THROWS();
    }

    king_set_error("");
    RETURN_STR(response_payload);
}

PHP_METHOD(King_MCP, close)
{
    king_mcp_state *state;

    ZEND_PARSE_PARAMETERS_NONE();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::close");
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_mcp_state_close(state);
    king_set_error("");
}

PHP_METHOD(King_MCP, uploadFromStream)
{
    char *service = NULL;
    char *method = NULL;
    char *stream_identifier = NULL;
    size_t service_len = 0;
    size_t method_len = 0;
    size_t stream_identifier_len = 0;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STRING(stream_identifier, stream_identifier_len)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::uploadFromStream");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_state_require_open(state, "MCP::uploadFromStream") != SUCCESS) {
        RETURN_THROWS();
    }

    if (
        king_mcp_validate_transfer_args(
            service,
            service_len,
            method,
            method_len,
            "stream identifier",
            stream_identifier,
            stream_identifier_len,
            "MCP::uploadFromStream",
            king_ce_validation_exception
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 4);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            NULL,
            "MCP::uploadFromStream",
            king_ce_validation_exception,
            &control
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    payload = king_mcp_read_stream_payload(
        stream,
        "MCP::uploadFromStream",
        king_ce_mcp_timeout,
        king_ce_mcp_data_error,
        &control
    );
    if (payload == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::uploadFromStream",
            king_ce_mcp_timeout,
            king_ce_mcp_data_error
        ) != SUCCESS) {
        zend_string_release(payload);
        RETURN_THROWS();
    }

    if (king_mcp_transfer_store(
        state,
        service,
        service_len,
        method,
        method_len,
        stream_identifier,
        stream_identifier_len,
        payload,
        &control
    ) != SUCCESS) {
        zend_string_release(payload);
        if (king_mcp_runtime_control_check(
                &control,
                "MCP::uploadFromStream",
                king_ce_mcp_timeout,
                king_ce_mcp_data_error
            ) != SUCCESS) {
            RETURN_THROWS();
        }
        if (king_get_error()[0] == '\0') {
            king_set_error("MCP::uploadFromStream() failed to persist the MCP transfer state.");
        }
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_data_error,
            king_get_error()
        );
        RETURN_THROWS();
    }

    zend_string_release(payload);
    king_set_error("");
}

PHP_METHOD(King_MCP, downloadToStream)
{
    char *service = NULL;
    char *method = NULL;
    size_t service_len = 0;
    size_t method_len = 0;
    zend_string *request_payload;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STR(request_payload)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::downloadToStream");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_state_require_open(state, "MCP::downloadToStream") != SUCCESS) {
        RETURN_THROWS();
    }

    if (
        king_mcp_validate_transfer_args(
            service,
            service_len,
            method,
            method_len,
            "request payload",
            ZSTR_VAL(request_payload),
            ZSTR_LEN(request_payload),
            "MCP::downloadToStream",
            king_ce_validation_exception
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 4);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            NULL,
            "MCP::downloadToStream",
            king_ce_validation_exception,
            &control
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::downloadToStream",
            king_ce_mcp_timeout,
            king_ce_mcp_data_error
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    payload = king_mcp_transfer_find(
        state,
        service,
        service_len,
        method,
        method_len,
        ZSTR_VAL(request_payload),
        ZSTR_LEN(request_payload),
        &control
    );
    if (payload == NULL) {
        if (king_mcp_runtime_control_check(
                &control,
                "MCP::downloadToStream",
                king_ce_mcp_timeout,
                king_ce_mcp_data_error
            ) != SUCCESS) {
            RETURN_THROWS();
        }
        if (king_get_error()[0] == '\0') {
            king_set_error(
                "MCP::downloadToStream() could not find an MCP transfer for the requested payload identifier."
            );
        }
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_data_error,
            king_get_error()
        );
        RETURN_THROWS();
    }

    if (
        king_mcp_write_payload_to_stream(
            stream,
            payload,
            "MCP::downloadToStream",
            king_ce_mcp_timeout,
            king_ce_mcp_data_error,
            &control
        ) != SUCCESS
    ) {
        zend_string_release(payload);
        RETURN_THROWS();
    }

    if (king_mcp_transfer_acknowledge(
            state,
            service,
            service_len,
            method,
            method_len,
            ZSTR_VAL(request_payload),
            ZSTR_LEN(request_payload)
        ) != SUCCESS) {
        zend_string_release(payload);
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_data_error,
            king_get_error()[0] != '\0'
                ? king_get_error()
                : "MCP::downloadToStream() failed to finalize the local MCP transfer state."
        );
        RETURN_THROWS();
    }

    zend_string_release(payload);
    king_set_error("");
}

const zend_function_entry king_mcp_class_methods[] = {
    PHP_ME(King_MCP, __construct, arginfo_class_King_MCP___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_MCP, request, arginfo_class_King_MCP_request, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, uploadFromStream, arginfo_class_King_MCP_uploadFromStream, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, downloadToStream, arginfo_class_King_MCP_downloadToStream, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, close, arginfo_class_King_MCP_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
