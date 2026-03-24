#include "main/php_streams.h"
#include "include/mcp/mcp.h"


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
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_uploadFromStream, 0, 4, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, streamIdentifier, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_MCP_downloadToStream, 0, 4, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, service, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
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

static zend_string *king_mcp_transfer_key_create(
    const char *service_name,
    size_t service_name_len,
    const char *method_name,
    size_t method_name_len,
    const char *transfer_id,
    size_t transfer_id_len)
{
    size_t key_len = service_name_len + 1 + method_name_len + 1 + transfer_id_len;
    zend_string *key = zend_string_alloc(key_len, 0);
    char *cursor = ZSTR_VAL(key);

    memcpy(cursor, service_name, service_name_len);
    cursor += service_name_len;
    *cursor++ = '\n';

    memcpy(cursor, method_name, method_name_len);
    cursor += method_name_len;
    *cursor++ = '\n';

    memcpy(cursor, transfer_id, transfer_id_len);
    ZSTR_VAL(key)[key_len] = '\0';

    return key;
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

    (void) identifier_value;

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

    if (identifier_value_len == 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() requires a non-empty %s.",
            function_name,
            identifier_name
        );
        return king_mcp_set_transfer_error(exception_ce, message);
    }

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
    zend_class_entry *exception_ce)
{
    char chunk[8192];
    ssize_t bytes_read = 0;
    size_t used = 0;
    zend_string *buffer = NULL;
    char message[KING_ERR_LEN];

    do {
        bytes_read = php_stream_read(stream, chunk, sizeof(chunk));
        if (bytes_read > 0) {
            size_t next_used = used + (size_t) bytes_read;

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
        king_mcp_set_transfer_error(exception_ce, message);
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
    zend_class_entry *exception_ce)
{
    size_t offset = 0;
    ssize_t bytes_written = 0;
    char message[KING_ERR_LEN];

    while (offset < ZSTR_LEN(payload)) {
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
            return king_mcp_set_transfer_error(exception_ce, message);
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

    if (king_mcp_request(state, service_name, method_name, request_payload_str, &response_payload) != SUCCESS) {
        RETURN_FALSE;
    }
    
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

    state->closed = true;
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
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STRING(stream_identifier, stream_identifier_len)
        Z_PARAM_ZVAL(stream_zv)
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

    payload = king_mcp_read_stream_payload(stream, "king_mcp_upload_from_stream", NULL);
    if (payload == NULL) {
        RETURN_FALSE;
    }

    king_mcp_transfer_store(
        state,
        service_name,
        method_name,
        stream_identifier,
        payload
    );

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
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STRING(request_payload, request_payload_len)
        Z_PARAM_ZVAL(stream_zv)
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

    payload = king_mcp_transfer_find(
        state,
        service_name,
        method_name,
        request_payload
    );
    if (payload == NULL) {
        king_set_error(
            "king_mcp_download_to_stream() could not find a local MCP transfer for the requested payload identifier."
        );
        RETURN_FALSE;
    }

    if (
        king_mcp_write_payload_to_stream(
            stream,
            payload,
            "king_mcp_download_to_stream",
            NULL
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

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

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STR(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::request");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_state_require_open(state, "MCP::request") != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_request(state, service, method, payload, &response_payload) != SUCCESS) {
        RETURN_THROWS();
    }
    
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

    state->closed = true;
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
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STRING(stream_identifier, stream_identifier_len)
        Z_PARAM_ZVAL(stream_zv)
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

    payload = king_mcp_read_stream_payload(
        stream,
        "MCP::uploadFromStream",
        king_ce_mcp_data_error
    );
    if (payload == NULL) {
        RETURN_THROWS();
    }

    king_mcp_transfer_store(
        state,
        service,
        method,
        stream_identifier,
        payload
    );

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
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STR(request_payload)
        Z_PARAM_ZVAL(stream_zv)
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

    payload = king_mcp_transfer_find(
        state,
        service,
        method,
        ZSTR_VAL(request_payload)
    );
    if (payload == NULL) {
        king_set_error(
            "MCP::downloadToStream() could not find a local MCP transfer for the requested payload identifier."
        );
        zend_throw_exception_ex(
            king_ce_mcp_data_error,
            0,
            "MCP::downloadToStream() could not find a local MCP transfer for the requested payload identifier."
        );
        RETURN_THROWS();
    }

    if (
        king_mcp_write_payload_to_stream(
            stream,
            payload,
            "MCP::downloadToStream",
            king_ce_mcp_data_error
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }

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
