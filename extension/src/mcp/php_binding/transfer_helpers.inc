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
                    "%s() failed: maximum message size (" ZEND_LONG_FMT " bytes) exceeded.",
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

#include "php_king/globals.h"
