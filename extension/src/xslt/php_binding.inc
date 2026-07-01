/*
 * King\XSLT\Processor PHP object surface. The XSLT translation unit includes
 * this object boundary beside the native SaxonC runtime used by king_xslt_*.
 */

#include "xslt/xslt.h"

static zend_object_handlers king_xslt_processor_object_handlers;

static void king_xslt_processor_object_free(zend_object *object)
{
    king_xslt_processor_object *intern = php_king_xslt_processor_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->options)) {
        zval_ptr_dtor(&intern->options);
        ZVAL_UNDEF(&intern->options);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_xslt_processor_object_create(zend_class_entry *ce)
{
    king_xslt_processor_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->options);
    array_init(&intern->options);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_xslt_processor_object_handlers;

    return &intern->std;
}

static void king_xslt_processor_object_handlers_init(void)
{
    memcpy(
        &king_xslt_processor_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_xslt_processor_object_handlers.offset = XtOffsetOf(king_xslt_processor_object, std);
    king_xslt_processor_object_handlers.free_obj = king_xslt_processor_object_free;
    king_xslt_processor_object_handlers.clone_obj = NULL;
    king_ce_xslt_processor->create_object = king_xslt_processor_object_create;
}

static void king_xslt_processor_merge_nested_properties(
    zval *merged,
    zval *base_options,
    zval *call_options
)
{
    zval *base_properties;
    zval *call_properties;
    zval properties;

    base_properties = zend_hash_str_find(
        Z_ARRVAL_P(base_options),
        "properties",
        sizeof("properties") - 1
    );
    call_properties = zend_hash_str_find(
        Z_ARRVAL_P(call_options),
        "properties",
        sizeof("properties") - 1
    );

    if (base_properties == NULL
        || call_properties == NULL
        || Z_TYPE_P(base_properties) != IS_ARRAY
        || Z_TYPE_P(call_properties) != IS_ARRAY) {
        return;
    }

    array_init(&properties);
    zend_hash_copy(Z_ARRVAL(properties), Z_ARRVAL_P(base_properties), zval_add_ref);
    zend_hash_merge(Z_ARRVAL(properties), Z_ARRVAL_P(call_properties), zval_add_ref, 1);
    zend_hash_str_update(Z_ARRVAL_P(merged), "properties", sizeof("properties") - 1, &properties);
}

static zval *king_xslt_processor_effective_options(
    king_xslt_processor_object *intern,
    zval *options,
    zval *merged
)
{
    if (Z_ISUNDEF(intern->options)
        || Z_TYPE(intern->options) != IS_ARRAY
        || zend_hash_num_elements(Z_ARRVAL(intern->options)) == 0) {
        return options;
    }

    if (options == NULL || Z_TYPE_P(options) == IS_NULL) {
        return &intern->options;
    }

    array_init(merged);
    zend_hash_copy(Z_ARRVAL_P(merged), Z_ARRVAL(intern->options), zval_add_ref);
    zend_hash_merge(Z_ARRVAL_P(merged), Z_ARRVAL_P(options), zval_add_ref, 1);
    king_xslt_processor_merge_nested_properties(merged, &intern->options, options);

    return merged;
}

PHP_METHOD(King_XSLT_Processor, __construct)
{
    zval *options = NULL;
    king_xslt_processor_object *intern;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (options == NULL || Z_TYPE_P(options) == IS_NULL) {
        return;
    }
    if (king_xslt_validate_options(options) != SUCCESS) {
        RETURN_THROWS();
    }

    intern = php_king_xslt_processor_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    zval_ptr_dtor(&intern->options);
    ZVAL_COPY(&intern->options, options);
}

PHP_METHOD(King_XSLT_Processor, getOptions)
{
    king_xslt_processor_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_xslt_processor_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_ZVAL(&intern->options, 1, 0);
}

PHP_METHOD(King_XSLT_Processor, engineStatus)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_xslt_engine_status_array(return_value);
}

PHP_METHOD(King_XSLT_Processor, transformFile)
{
    zend_string *source_path;
    zend_string *stylesheet_path;
    zval *options = NULL;
    zval merged;
    zval *effective_options;
    king_xslt_processor_object *intern;

    ZVAL_UNDEF(&merged);

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(source_path)
        Z_PARAM_STR(stylesheet_path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_xslt_processor_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    effective_options = king_xslt_processor_effective_options(intern, options, &merged);

    if (king_xslt_transform_file_result(source_path, stylesheet_path, effective_options, return_value) != SUCCESS) {
        if (effective_options == &merged) {
            zval_ptr_dtor(&merged);
        }
        RETURN_THROWS();
    }

    if (effective_options == &merged) {
        zval_ptr_dtor(&merged);
    }
}

PHP_METHOD(King_XSLT_Processor, transformToFile)
{
    zend_string *source_path;
    zend_string *stylesheet_path;
    zend_string *output_path;
    zval *options = NULL;
    zval merged;
    zval *effective_options;
    king_xslt_processor_object *intern;

    ZVAL_UNDEF(&merged);

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STR(source_path)
        Z_PARAM_STR(stylesheet_path)
        Z_PARAM_STR(output_path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_xslt_processor_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    effective_options = king_xslt_processor_effective_options(intern, options, &merged);

    if (king_xslt_transform_to_file_result(source_path, stylesheet_path, output_path, effective_options, return_value) != SUCCESS) {
        if (effective_options == &merged) {
            zval_ptr_dtor(&merged);
        }
        RETURN_THROWS();
    }

    if (effective_options == &merged) {
        zval_ptr_dtor(&merged);
    }
}

#include "xslt/class_method_entries.h"
