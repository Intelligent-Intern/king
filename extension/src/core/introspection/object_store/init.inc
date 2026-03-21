PHP_FUNCTION(king_object_store_init)
{
    zval *config;
    zval *value_zv;
    king_object_store_runtime_state new_state;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    king_object_store_runtime_assign_defaults(&new_state);
    new_state.initialized = true;

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "primary_backend", sizeof("primary_backend") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_STRING) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'primary_backend' must be of type string."
            );
            RETURN_THROWS();
        }
        if (!king_object_store_runtime_backend_supported(Z_STR_P(value_zv))) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'primary_backend' must be one of: local_fs, distributed, cloud_s3, cloud_gcs, cloud_azure, memory_cache."
            );
            RETURN_THROWS();
        }
        strncpy(new_state.primary_backend, Z_STRVAL_P(value_zv), sizeof(new_state.primary_backend) - 1);
        new_state.primary_backend[sizeof(new_state.primary_backend) - 1] = '\0';
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "storage_root_path", sizeof("storage_root_path") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_STRING) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'storage_root_path' must be of type string."
            );
            RETURN_THROWS();
        }
        if (Z_STRLEN_P(value_zv) == 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'storage_root_path' cannot be empty."
            );
            RETURN_THROWS();
        }
        strncpy(new_state.storage_root_path, Z_STRVAL_P(value_zv), sizeof(new_state.storage_root_path) - 1);
        new_state.storage_root_path[sizeof(new_state.storage_root_path) - 1] = '\0';
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "max_storage_size_bytes", sizeof("max_storage_size_bytes") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_LONG) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'max_storage_size_bytes' must be of type int."
            );
            RETURN_THROWS();
        }
        if (Z_LVAL_P(value_zv) < 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'max_storage_size_bytes' cannot be negative."
            );
            RETURN_THROWS();
        }
        new_state.max_storage_size_bytes = Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "replication_factor", sizeof("replication_factor") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_LONG) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'replication_factor' must be of type int."
            );
            RETURN_THROWS();
        }
        if (Z_LVAL_P(value_zv) <= 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'replication_factor' must be greater than 0."
            );
            RETURN_THROWS();
        }
        new_state.replication_factor = Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "chunk_size_kb", sizeof("chunk_size_kb") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_LONG) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'chunk_size_kb' must be of type int."
            );
            RETURN_THROWS();
        }
        if (Z_LVAL_P(value_zv) <= 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'chunk_size_kb' must be greater than 0."
            );
            RETURN_THROWS();
        }
        new_state.chunk_size_kb = Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "cdn_config", sizeof("cdn_config") - 1);
    if (value_zv != NULL) {
        zval *cdn_value_zv;

        if (Z_TYPE_P(value_zv) != IS_ARRAY) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store init option 'cdn_config' must be of type array."
            );
            RETURN_THROWS();
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "enabled", sizeof("enabled") - 1);
        if (cdn_value_zv != NULL) {
            if (Z_TYPE_P(cdn_value_zv) != IS_TRUE && Z_TYPE_P(cdn_value_zv) != IS_FALSE) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store init option 'cdn_config.enabled' must be of type bool."
                );
                RETURN_THROWS();
            }
            new_state.cdn_enabled = Z_TYPE_P(cdn_value_zv) == IS_TRUE;
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "cache_size_mb", sizeof("cache_size_mb") - 1);
        if (cdn_value_zv != NULL) {
            if (Z_TYPE_P(cdn_value_zv) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store init option 'cdn_config.cache_size_mb' must be of type int."
                );
                RETURN_THROWS();
            }
            if (Z_LVAL_P(cdn_value_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store init option 'cdn_config.cache_size_mb' cannot be negative."
                );
                RETURN_THROWS();
            }
            new_state.cdn_cache_size_mb = Z_LVAL_P(cdn_value_zv);
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "default_ttl_seconds", sizeof("default_ttl_seconds") - 1);
        if (cdn_value_zv != NULL) {
            if (Z_TYPE_P(cdn_value_zv) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store init option 'cdn_config.default_ttl_seconds' must be of type int."
                );
                RETURN_THROWS();
            }
            if (Z_LVAL_P(cdn_value_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store init option 'cdn_config.default_ttl_seconds' cannot be negative."
                );
                RETURN_THROWS();
            }
            new_state.cdn_default_ttl_sec = Z_LVAL_P(cdn_value_zv);
        }
    }

    king_object_store_runtime = new_state;
    RETURN_TRUE;
}
