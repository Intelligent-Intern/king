const zend_function_entry king_response_class_methods[] = {
    PHP_ME(King_Response, getStatusCode, arginfo_class_King_Response_getStatusCode_ret, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, getHeaders, arginfo_class_King_Response_getHeaders, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, getBody, arginfo_class_King_Response_getBody, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, read, arginfo_class_King_Response_read, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, isEndOfBody, arginfo_class_King_Response_isEndOfBody, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry king_http_client_class_methods[] = {
    PHP_ME(King_Client_HttpClient, __construct, arginfo_class_King_Client_HttpClient___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Client_HttpClient, request, arginfo_class_King_Client_HttpClient_request, ZEND_ACC_PUBLIC)
    PHP_ME(King_Client_HttpClient, requestAsync, arginfo_class_King_Client_HttpClient_requestAsync, ZEND_ACC_PUBLIC)
    PHP_ME(King_Client_HttpClient, close, arginfo_class_King_Client_HttpClient_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
