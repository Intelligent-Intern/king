ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_getConnections, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_send, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, connectionId, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_sendBinary, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, connectionId, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_broadcast, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_broadcastBinary, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_broadcastBatch, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, encodedBatch, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_stop, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

PHP_METHOD(King_WebSocket_Server, getConnections)
{
    king_ws_server_object *intern;
    zend_string *connection_id;
    zval *entry;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_websocket_server_registry_prune_closed(intern);

    array_init(return_value);
    ZEND_HASH_FOREACH_STR_KEY_VAL(&intern->connections, connection_id, entry)
    {
        king_ws_state *state = Z_TYPE_P(entry) == IS_PTR
            ? (king_ws_state *) Z_PTR_P(entry)
            : NULL;
        zval info;

        if (connection_id == NULL || state == NULL) {
            continue;
        }

        king_websocket_state_build_info_array(&info, state);
        zend_hash_update(Z_ARRVAL_P(return_value), connection_id, &info);
    }
    ZEND_HASH_FOREACH_END();

    king_set_error("");
}

PHP_METHOD(King_WebSocket_Server, send)
{
    zend_string *connection_id;
    zend_string *message;
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(connection_id)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (
        king_websocket_server_send_internal(
            intern,
            connection_id,
            message,
            false,
            "WebSocket\\Server::send"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Server, sendBinary)
{
    zend_string *connection_id;
    zend_string *payload;
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(connection_id)
        Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (
        king_websocket_server_send_internal(
            intern,
            connection_id,
            payload,
            true,
            "WebSocket\\Server::sendBinary"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Server, broadcast)
{
    zend_string *message;
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (
        king_websocket_server_broadcast_internal(
            intern,
            message,
            false,
            "WebSocket\\Server::broadcast"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Server, broadcastBinary)
{
    zend_string *payload;
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (
        king_websocket_server_broadcast_internal(
            intern,
            payload,
            true,
            "WebSocket\\Server::broadcastBinary"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Server, broadcastBatch)
{
    zend_string *encoded_batch;
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(encoded_batch)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (
        king_websocket_server_broadcast_batch(
            intern,
            encoded_batch,
            "WebSocket\\Server::broadcastBatch"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Server, stop)
{
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (intern->closed) {
        king_set_error("");
        return;
    }

    intern->closed = true;
    if (intern->listener_fd >= 0) {
        close(intern->listener_fd);
        intern->listener_fd = -1;
    }

    king_websocket_server_close_active_connections(
        intern,
        "WebSocket\\Server::stop"
    );
    king_set_error("");
}

const zend_function_entry king_ws_server_class_methods[] = {
    PHP_ME(King_WebSocket_Server, __construct, arginfo_class_King_WebSocket_Server___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_WebSocket_Server, accept, arginfo_class_King_WebSocket_Server_accept, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, getConnections, arginfo_class_King_WebSocket_Server_getConnections, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, send, arginfo_class_King_WebSocket_Server_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, sendBinary, arginfo_class_King_WebSocket_Server_sendBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, broadcast, arginfo_class_King_WebSocket_Server_broadcast, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, broadcastBinary, arginfo_class_King_WebSocket_Server_broadcastBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, broadcastBatch, arginfo_class_King_WebSocket_Server_broadcastBatch, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, stop, arginfo_class_King_WebSocket_Server_stop, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
