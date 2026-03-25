#include "src/object_store/object_store_internal.h"

#include "include/king_globals.h"

static int king_object_store_parse_backend_config(
    zval *value_zv,
    const char *config_key,
    king_storage_backend_t *backend_out
)
{
    if (Z_TYPE_P(value_zv) == IS_LONG) {
        zend_long backend = Z_LVAL_P(value_zv);

        if (backend < KING_STORAGE_BACKEND_LOCAL_FS || backend > KING_STORAGE_BACKEND_MEMORY_CACHE) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config '%s' must be a valid storage backend.",
                config_key
            );
            return FAILURE;
        }

        *backend_out = (king_storage_backend_t) backend;
        return SUCCESS;
    }

    if (Z_TYPE_P(value_zv) == IS_STRING) {
        const char *backend_name = Z_STRVAL_P(value_zv);

        if (strcmp(backend_name, "local_fs") == 0) {
            *backend_out = KING_STORAGE_BACKEND_LOCAL_FS;
            return SUCCESS;
        }
        if (strcmp(backend_name, "distributed") == 0) {
            *backend_out = KING_STORAGE_BACKEND_DISTRIBUTED;
            return SUCCESS;
        }
        if (strcmp(backend_name, "cloud_s3") == 0) {
            *backend_out = KING_STORAGE_BACKEND_CLOUD_S3;
            return SUCCESS;
        }
        if (strcmp(backend_name, "cloud_gcs") == 0) {
            *backend_out = KING_STORAGE_BACKEND_CLOUD_GCS;
            return SUCCESS;
        }
        if (strcmp(backend_name, "cloud_azure") == 0) {
            *backend_out = KING_STORAGE_BACKEND_CLOUD_AZURE;
            return SUCCESS;
        }
        if (strcmp(backend_name, "memory_cache") == 0) {
            *backend_out = KING_STORAGE_BACKEND_MEMORY_CACHE;
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "Object-store config '%s' must be a valid storage backend.",
        config_key
    );
    return FAILURE;
}

PHP_FUNCTION(king_object_store_init)
{
    zval *config;
    zval *value_zv;
    king_object_store_config_t new_config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config)) > 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        RETURN_THROWS();
    }

    memset(&new_config, 0, sizeof(new_config));
    ZVAL_UNDEF(&new_config.cloud_credentials);
    new_config.primary_backend = KING_STORAGE_BACKEND_LOCAL_FS;
    new_config.backup_backend = KING_STORAGE_BACKEND_LOCAL_FS;

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "primary_backend", sizeof("primary_backend") - 1);
    if (value_zv != NULL) {
        if (king_object_store_parse_backend_config(value_zv, "primary_backend", &new_config.primary_backend) != SUCCESS) {
            RETURN_THROWS();
        }
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "storage_root_path", sizeof("storage_root_path") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_STRING || Z_STRLEN_P(value_zv) == 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config 'storage_root_path' must be a non-empty string."
            );
            RETURN_THROWS();
        }
        if ((size_t) Z_STRLEN_P(value_zv) >= sizeof(new_config.storage_root_path)) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config 'storage_root_path' exceeds the maximum supported path length."
            );
            RETURN_THROWS();
        }
        strncpy(new_config.storage_root_path, Z_STRVAL_P(value_zv), sizeof(new_config.storage_root_path) - 1);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "max_storage_size_bytes", sizeof("max_storage_size_bytes") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_LONG || Z_LVAL_P(value_zv) < 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config 'max_storage_size_bytes' must be a non-negative integer."
            );
            RETURN_THROWS();
        }
        new_config.max_storage_size_bytes = (uint64_t) Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "backup_backend", sizeof("backup_backend") - 1);
    if (value_zv != NULL) {
        if (king_object_store_parse_backend_config(value_zv, "backup_backend", &new_config.backup_backend) != SUCCESS) {
            RETURN_THROWS();
        }
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "cloud_credentials", sizeof("cloud_credentials") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_ARRAY) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config 'cloud_credentials' must be an array."
            );
            RETURN_THROWS();
        }
        ZVAL_COPY(&new_config.cloud_credentials, value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "replication_factor", sizeof("replication_factor") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_LONG || Z_LVAL_P(value_zv) <= 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config 'replication_factor' must be a positive integer."
            );
            RETURN_THROWS();
        }
        new_config.replication_factor = (uint32_t) Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "chunk_size_kb", sizeof("chunk_size_kb") - 1);
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_LONG || Z_LVAL_P(value_zv) <= 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config 'chunk_size_kb' must be a positive integer."
            );
            RETURN_THROWS();
        }
        new_config.chunk_size_kb = (uint32_t) Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "cdn_config", sizeof("cdn_config") - 1);
    if (value_zv != NULL) {
        zval *cdn_value_zv;

        if (Z_TYPE_P(value_zv) != IS_ARRAY) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store config 'cdn_config' must be an array."
            );
            RETURN_THROWS();
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "enabled", sizeof("enabled") - 1);
        if (cdn_value_zv != NULL) {
            if (Z_TYPE_P(cdn_value_zv) != IS_TRUE && Z_TYPE_P(cdn_value_zv) != IS_FALSE) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store config 'cdn_config.enabled' must be a boolean."
                );
                RETURN_THROWS();
            }
            new_config.cdn_config.enabled = (Z_TYPE_P(cdn_value_zv) == IS_TRUE) ? 1 : 0;
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "edge_node_count", sizeof("edge_node_count") - 1);
        if (cdn_value_zv != NULL) {
            if (Z_TYPE_P(cdn_value_zv) != IS_LONG || Z_LVAL_P(cdn_value_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store config 'cdn_config.edge_node_count' must be a non-negative integer."
                );
                RETURN_THROWS();
            }
            new_config.cdn_config.edge_node_count = (uint32_t) Z_LVAL_P(cdn_value_zv);
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "cache_size_mb", sizeof("cache_size_mb") - 1);
        if (cdn_value_zv != NULL) {
            if (Z_TYPE_P(cdn_value_zv) != IS_LONG || Z_LVAL_P(cdn_value_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store config 'cdn_config.cache_size_mb' must be a non-negative integer."
                );
                RETURN_THROWS();
            }
            new_config.cdn_config.cache_size_mb = (uint32_t) Z_LVAL_P(cdn_value_zv);
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "default_ttl_seconds", sizeof("default_ttl_seconds") - 1);
        if (cdn_value_zv != NULL) {
            if (Z_TYPE_P(cdn_value_zv) != IS_LONG || Z_LVAL_P(cdn_value_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Object-store config 'cdn_config.default_ttl_seconds' must be a non-negative integer."
                );
                RETURN_THROWS();
            }
            new_config.cdn_config.default_ttl_seconds = (uint32_t) Z_LVAL_P(cdn_value_zv);
        }
    }

    if (king_object_store_init_system(&new_config) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to initialize native object store."
        );
        RETURN_THROWS();
    }

    RETURN_TRUE;
}
