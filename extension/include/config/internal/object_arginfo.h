/*
 * include/config/internal/object_arginfo.h - King\Config OO arginfo
 */

#ifndef KING_CONFIG_INTERNAL_OBJECT_ARGINFO_H
#define KING_CONFIG_INTERNAL_OBJECT_ARGINFO_H

#include <php.h>

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Config___construct, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Config_new, 0, 0, King\\Config, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Config_get, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Config_set, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Config_toArray, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#endif /* KING_CONFIG_INTERNAL_OBJECT_ARGINFO_H */
