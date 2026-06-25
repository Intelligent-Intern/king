/*
 * include/config/object.h - PHP-visible Config object contract
 */

#ifndef KING_CONFIG_OBJECT_H
#define KING_CONFIG_OBJECT_H

#include <php.h>
#include <zend_object_handlers.h>

typedef struct _king_config_object {
    zval resource;
    zval overrides;
    zend_object std;
} king_config_object;

static inline king_config_object *
php_king_config_obj_from_zend(zend_object *obj)
{
    return (king_config_object *)
        ((char*)obj - XtOffsetOf(king_config_object, std));
}

#endif /* KING_CONFIG_OBJECT_H */
