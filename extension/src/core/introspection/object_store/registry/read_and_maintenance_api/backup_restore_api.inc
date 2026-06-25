PHP_FUNCTION(king_object_store_backup_object)
{
    zend_string *object_id;
    zend_string *destination_path;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(destination_path)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s",
                object_id_error
            );
            RETURN_THROWS();
        }
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

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s",
                object_id_error
            );
            RETURN_THROWS();
        }
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
    zval *options = NULL;
    zval *mode_zv;
    zval *base_snapshot_path_zv;
    const char *mode = "full";
    const char *base_snapshot_path = NULL;
    zend_bool incremental = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(destination_path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
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
    if (options != NULL) {
        mode_zv = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "mode",
            sizeof("mode") - 1
        );
        if (mode_zv != NULL) {
            if (Z_TYPE_P(mode_zv) != IS_STRING || Z_STRLEN_P(mode_zv) == 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "king_object_store_backup_all_objects() option 'mode' must be a non-empty string when provided."
                );
                RETURN_THROWS();
            }

            mode = Z_STRVAL_P(mode_zv);
        }

        if (strcmp(mode, "full") == 0) {
            incremental = 0;
        } else if (strcmp(mode, "incremental") == 0) {
            incremental = 1;
        } else {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "king_object_store_backup_all_objects() option 'mode' must be either 'full' or 'incremental'."
            );
            RETURN_THROWS();
        }

        base_snapshot_path_zv = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "base_snapshot_path",
            sizeof("base_snapshot_path") - 1
        );
        if (base_snapshot_path_zv != NULL) {
            if (Z_TYPE_P(base_snapshot_path_zv) != IS_STRING || Z_STRLEN_P(base_snapshot_path_zv) == 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "king_object_store_backup_all_objects() option 'base_snapshot_path' must be a non-empty string when provided."
                );
                RETURN_THROWS();
            }

            base_snapshot_path = Z_STRVAL_P(base_snapshot_path_zv);
        }
    }
    if (incremental && base_snapshot_path == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_object_store_backup_all_objects() incremental mode requires option 'base_snapshot_path'."
        );
        RETURN_THROWS();
    }
    if (!incremental && base_snapshot_path != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_object_store_backup_all_objects() option 'base_snapshot_path' is only valid when mode='incremental'."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_backup_all_objects(
            ZSTR_VAL(destination_path),
            incremental,
            base_snapshot_path
        ) == SUCCESS
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
