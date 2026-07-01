const zend_function_entry king_rtp_socket_class_methods[] = {
    PHP_ME(King_RTP_Socket, __construct, arginfo_class_King_RTP_Socket___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_RTP_Socket, iceCredentials, arginfo_class_King_RTP_Socket_iceCredentials, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, dtlsFingerprint, arginfo_class_King_RTP_Socket_dtlsFingerprint, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, acceptDtls, arginfo_class_King_RTP_Socket_acceptDtls, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, receive, arginfo_class_King_RTP_Socket_receive, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, send, arginfo_class_King_RTP_Socket_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, close, arginfo_class_King_RTP_Socket_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, isClosed, arginfo_class_King_RTP_Socket_isClosed, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
