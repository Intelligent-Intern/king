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

#include "server/websocket_server_class_method_entries.h"
