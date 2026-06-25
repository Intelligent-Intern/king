ZEND_BEGIN_ARG_INFO_EX(arginfo_king_service_discovery, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, service_type, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, criteria, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_service_lookup, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, client_info, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_register_service, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, service_info, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_register_mother_node, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, mother_node_info, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_update_service_status, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, service_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, status, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metrics, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_semantic_dns_query, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, query, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, max_response_bytes, IS_LONG, 0, "256")
ZEND_END_ARG_INFO()
