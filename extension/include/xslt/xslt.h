/*
 * include/xslt/xslt.h - Native SaxonC-backed XSLT runtime surface
 * =========================================================================
 *
 * This subsystem exposes a small PHP-visible XSLT 2.0/3.0 execution primitive
 * backed by SaxonC through the same optional runtime-loader pattern used by
 * the transport and cloud adapter leaves.
 */

#ifndef KING_XSLT_H
#define KING_XSLT_H

#include <php.h>
#include <zend_object_handlers.h>

typedef struct _king_xslt_processor_object {
    zval options;
    zend_object std;
} king_xslt_processor_object;

static inline king_xslt_processor_object *
php_king_xslt_processor_obj_from_zend(zend_object *obj)
{
    return (king_xslt_processor_object *)
        ((char*)obj - XtOffsetOf(king_xslt_processor_object, std));
}

PHP_FUNCTION(king_xslt_engine_status);
PHP_FUNCTION(king_xslt_transform_file);
PHP_FUNCTION(king_xslt_transform_to_file);

void king_xslt_engine_status_array(zval *return_value);
zend_result king_xslt_transform_file_result(
    zend_string *source_path,
    zend_string *stylesheet_path,
    zval *options,
    zval *return_value
);
zend_result king_xslt_transform_to_file_result(
    zend_string *source_path,
    zend_string *stylesheet_path,
    zend_string *output_path,
    zval *options,
    zval *return_value
);
zend_result king_xslt_validate_options(zval *options);
void king_xslt_shutdown_system(void);
void king_xslt_add_component_info(zval *configuration);

#endif
