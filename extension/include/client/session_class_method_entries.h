const zend_function_entry king_session_class_methods[] = {
    PHP_ME(King_Session, __construct, arginfo_class_King_Session___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Session, isConnected, arginfo_class_King_Session_isConnected, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, sendRequest, arginfo_class_King_Session_sendRequest, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, poll, arginfo_class_King_Session_poll, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, close, arginfo_class_King_Session_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, stats, arginfo_class_King_Session_stats, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, alpn, arginfo_class_King_Session_alpn, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, enableEarlyHints, arginfo_class_King_Session_enableEarlyHints, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry king_stream_class_methods[] = {
    PHP_ME(King_Stream, receiveResponse, arginfo_class_King_Stream_receiveResponse, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, send, arginfo_class_King_Stream_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, finish, arginfo_class_King_Stream_finish, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, isClosed, arginfo_class_King_Stream_isClosed, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, close, arginfo_class_King_Stream_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
