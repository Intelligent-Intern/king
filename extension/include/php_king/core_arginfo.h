/*
 * Shared reusable arginfo blocks for the King extension bootstrap and module
 * binding fragments.
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_no_args, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_one_string, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_one_long, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, value, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_new_config, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_optional_headers, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_headers, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_config_array, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, config, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
