ZEND_BEGIN_ARG_INFO_EX(arginfo_king_optional_instances, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, instances, IS_LONG, 0, "1")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_autoscaling_register_node, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, server_id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, name, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()
