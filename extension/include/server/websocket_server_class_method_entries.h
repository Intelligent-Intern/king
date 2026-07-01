const zend_function_entry king_ws_server_class_methods[] = {
    PHP_ME(King_WebSocket_Server, __construct, arginfo_class_King_WebSocket_Server___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_WebSocket_Server, accept, arginfo_class_King_WebSocket_Server_accept, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, getConnections, arginfo_class_King_WebSocket_Server_getConnections, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, send, arginfo_class_King_WebSocket_Server_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, sendBinary, arginfo_class_King_WebSocket_Server_sendBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, broadcast, arginfo_class_King_WebSocket_Server_broadcast, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, broadcastBinary, arginfo_class_King_WebSocket_Server_broadcastBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, stop, arginfo_class_King_WebSocket_Server_stop, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
