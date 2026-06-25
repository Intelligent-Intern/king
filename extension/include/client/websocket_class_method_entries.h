const zend_function_entry king_ws_connection_class_methods[] = {
    PHP_ME(King_WebSocket_Connection, __construct, arginfo_class_King_WebSocket_Connection___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_WebSocket_Connection, send, arginfo_class_King_WebSocket_Connection_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, sendAsync, arginfo_class_King_WebSocket_Connection_sendAsync, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, sendBinary, arginfo_class_King_WebSocket_Connection_sendBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, sendBinaryAsync, arginfo_class_King_WebSocket_Connection_sendBinaryAsync, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, receive, arginfo_class_King_WebSocket_Connection_receive, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, receiveAsync, arginfo_class_King_WebSocket_Connection_receiveAsync, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, ping, arginfo_class_King_WebSocket_Connection_ping, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, close, arginfo_class_King_WebSocket_Connection_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, getInfo, arginfo_class_King_WebSocket_Connection_getInfo, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
