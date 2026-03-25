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

    memset(&metadata, 0, sizeof(metadata));
    if (object_id) {
        strncpy(metadata.object_id, ZSTR_VAL(object_id), sizeof(metadata.object_id) - 1);
    }
    metadata.content_length = ZSTR_LEN(payload);
    metadata.created_at = time(NULL);
    metadata.modified_at = metadata.created_at;

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    if (ZSTR_LEN(object_id) == 0 || ZSTR_LEN(object_id) > 127) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID must be between 1 and 127 bytes.");
        RETURN_THROWS();
    }
    if (king_object_store_object_id_validate(ZSTR_VAL(object_id)) != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Object ID is invalid for object-store paths."
        );
        RETURN_THROWS();
    }

    (void) options;

    if (king_object_store_runtime.config.max_storage_size_bytes > 0) {
        uint64_t new_size = ZSTR_LEN(payload);
        uint64_t current_total = king_object_store_runtime.current_stored_bytes;
        
        /* Check if it's an overwrite to avoid double counting */
        char file_path[1024];
        struct stat st_old;
        king_object_store_build_path(file_path, sizeof(file_path), ZSTR_VAL(object_id));
        if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
            if (current_total >= (uint64_t)st_old.st_size) {
                current_total -= (uint64_t)st_old.st_size;
            }
        }

        if (current_total + new_size > king_object_store_runtime.config.max_storage_size_bytes) {
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

    if (ZSTR_LEN(object_id) == 0 || ZSTR_LEN(object_id) > 127) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID must be between 1 and 127 bytes.");
        RETURN_THROWS();
    }
    if (king_object_store_object_id_validate(ZSTR_VAL(object_id)) != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Object ID is invalid for object-store paths."
        );
        RETURN_THROWS();
    }

    (void) options;

    if (king_object_store_read_object(ZSTR_VAL(object_id), &data, &data_size, &metadata) != SUCCESS) {
        RETURN_FALSE;
    }

    zend_string *ret_str = zend_string_init(data, data_size, 0);
    if (data_size > 0) {
        memset(data, 0, data_size);
    }
    pefree(data, 1);
    RETURN_STR(ret_str);
}

PHP_FUNCTION(king_object_store_delete)
{
    zend_string *object_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0 || ZSTR_LEN(object_id) > 127) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID must be between 1 and 127 bytes.");
        RETURN_THROWS();
    }
    if (king_object_store_object_id_validate(ZSTR_VAL(object_id)) != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Object ID is invalid for object-store paths."
        );
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

    king_object_store_list_object(return_value);
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

PHP_FUNCTION(king_object_store_get_metadata)
{
    zend_string *object_id;
    king_object_metadata_t metadata;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        return;
    }
    if (ZSTR_LEN(object_id) == 0 || ZSTR_LEN(object_id) > 127) {
        return;
    }
    if (king_object_store_object_id_validate(ZSTR_VAL(object_id)) != NULL) {
        return;
    }

    if (king_object_store_meta_read(ZSTR_VAL(object_id), &metadata) != SUCCESS) {
        RETURN_NULL();
    }

    array_init(return_value);
    add_assoc_string(return_value, "object_id",          metadata.object_id);
    add_assoc_long  (return_value, "content_length",     (zend_long) metadata.content_length);
    add_assoc_long  (return_value, "created_at",         (zend_long) metadata.created_at);
    add_assoc_long  (return_value, "is_backed_up",       (zend_long) metadata.is_backed_up);
    add_assoc_long  (return_value, "replication_status", (zend_long) metadata.replication_status);
    add_assoc_long  (return_value, "is_distributed",     (zend_long) metadata.is_distributed);
    add_assoc_long  (return_value, "distribution_peer_count", (zend_long) metadata.distribution_peer_count);
}

PHP_FUNCTION(king_object_store_backup_object)
{
    zend_string *object_id;
    zend_string *destination_path;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(destination_path)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0 || ZSTR_LEN(object_id) > 127) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Object ID must be between 1 and 127 bytes."
        );
        RETURN_THROWS();
    }
    if (king_object_store_object_id_validate(ZSTR_VAL(object_id)) != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Object ID is invalid for object-store paths."
        );
        RETURN_THROWS();
    }
    if (ZSTR_LEN(destination_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Destination path must be a non-empty string."
        );
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

    RETURN_BOOL(
        king_object_store_backup_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(destination_path)
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_restore_object)
{
    zend_string *object_id;
    zend_string *source_path;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(source_path)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0 || ZSTR_LEN(object_id) > 127) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Object ID must be between 1 and 127 bytes."
        );
        RETURN_THROWS();
    }
    if (king_object_store_object_id_validate(ZSTR_VAL(object_id)) != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Object ID is invalid for object-store paths."
        );
        RETURN_THROWS();
    }
    if (ZSTR_LEN(source_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Source path must be a non-empty string."
        );
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

    RETURN_BOOL(
        king_object_store_restore_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(source_path)
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_backup_all_objects)
{
    zend_string *destination_path;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(destination_path)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(destination_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Destination path must be a non-empty string."
        );
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

    RETURN_BOOL(
        king_object_store_backup_all_objects(ZSTR_VAL(destination_path)) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_restore_all_objects)
{
    zend_string *source_path;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(source_path)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(source_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Source path must be a non-empty string."
        );
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

    RETURN_BOOL(
        king_object_store_restore_all_objects(ZSTR_VAL(source_path)) == SUCCESS
    );
}
