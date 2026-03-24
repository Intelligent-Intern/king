#include "src/object_store/object_store_internal.h"

PHP_FUNCTION(king_object_store_put)
{
    zend_string *object_id;
    zend_string *payload;
    zval *options = NULL;
    king_object_metadata_t metadata;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    (void) options;

    if (king_object_store_runtime.config.max_storage_size_bytes > 0) {
        zend_long effective_stored_bytes = king_object_store_runtime.current_stored_bytes + ZSTR_LEN(payload);
        if ((uint64_t)effective_stored_bytes > king_object_store_runtime.config.max_storage_size_bytes) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store runtime capacity exceeded."
            );
            RETURN_THROWS();
        }
    }

    if (king_object_store_write_object(ZSTR_VAL(object_id), ZSTR_VAL(payload), ZSTR_LEN(payload), &metadata) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to store object '%s'.",
            ZSTR_VAL(object_id)
        );
        RETURN_THROWS();
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_get)
{
    zend_string *object_id;
    zval *options = NULL;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    (void) options;

    if (king_object_store_read_object(ZSTR_VAL(object_id), &data, &data_size, &metadata) != SUCCESS) {
        RETURN_FALSE;
    }

    zend_string *ret_str = zend_string_init(data, data_size, 0);
    pefree(data, 1);
    RETURN_STR(ret_str);
}

PHP_FUNCTION(king_object_store_delete)
{
    zend_string *object_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(king_object_store_remove_object(ZSTR_VAL(object_id)) == SUCCESS);
}

PHP_FUNCTION(king_object_store_list)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    if (!king_object_store_runtime.initialized) {
        return;
    }

    king_object_store_local_fs_list(return_value);
}

PHP_FUNCTION(king_object_store_cleanup_expired_objects)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_object_store_runtime.initialized) {
        return;
    }

    king_object_store_cleanup_expired_objects();
    RETURN_TRUE;
}
