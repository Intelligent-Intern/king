ZEND_BEGIN_ARG_INFO_EX(arginfo_king_semantic_dns_query, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, query, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, max_response_bytes, IS_LONG, 0, "256")
ZEND_END_ARG_INFO()
