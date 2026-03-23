#include "src/object_store/object_store_internal.h"

PHP_FUNCTION(king_object_store_init)
{
    zval *config;
    zval *value_zv;
    king_object_store_config_t new_config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    memset(&new_config, 0, sizeof(new_config));
    ZVAL_UNDEF(&new_config.cloud_credentials);
    
    new_config.primary_backend = KING_STORAGE_BACKEND_LOCAL_FS;

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "storage_root_path", sizeof("storage_root_path") - 1);
    if (value_zv != NULL && Z_TYPE_P(value_zv) == IS_STRING) {
        if (Z_STRLEN_P(value_zv) > 0) {
            strncpy(new_config.storage_root_path, Z_STRVAL_P(value_zv), sizeof(new_config.storage_root_path) - 1);
        }
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "max_storage_size_bytes", sizeof("max_storage_size_bytes") - 1);
    if (value_zv != NULL && Z_TYPE_P(value_zv) == IS_LONG && Z_LVAL_P(value_zv) >= 0) {
        new_config.max_storage_size_bytes = Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "replication_factor", sizeof("replication_factor") - 1);
    if (value_zv != NULL && Z_TYPE_P(value_zv) == IS_LONG && Z_LVAL_P(value_zv) > 0) {
        new_config.replication_factor = Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "chunk_size_kb", sizeof("chunk_size_kb") - 1);
    if (value_zv != NULL && Z_TYPE_P(value_zv) == IS_LONG && Z_LVAL_P(value_zv) > 0) {
        new_config.chunk_size_kb = Z_LVAL_P(value_zv);
    }

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), "cdn_config", sizeof("cdn_config") - 1);
    if (value_zv != NULL && Z_TYPE_P(value_zv) == IS_ARRAY) {
        zval *cdn_value_zv;

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "enabled", sizeof("enabled") - 1);
        if (cdn_value_zv != NULL && (Z_TYPE_P(cdn_value_zv) == IS_TRUE || Z_TYPE_P(cdn_value_zv) == IS_FALSE)) {
            new_config.cdn_config.enabled = (Z_TYPE_P(cdn_value_zv) == IS_TRUE) ? 1 : 0;
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "cache_size_mb", sizeof("cache_size_mb") - 1);
        if (cdn_value_zv != NULL && Z_TYPE_P(cdn_value_zv) == IS_LONG && Z_LVAL_P(cdn_value_zv) >= 0) {
            new_config.cdn_config.cache_size_mb = Z_LVAL_P(cdn_value_zv);
        }

        cdn_value_zv = zend_hash_str_find(Z_ARRVAL_P(value_zv), "default_ttl_seconds", sizeof("default_ttl_seconds") - 1);
        if (cdn_value_zv != NULL && Z_TYPE_P(cdn_value_zv) == IS_LONG && Z_LVAL_P(cdn_value_zv) >= 0) {
            new_config.cdn_config.default_ttl_seconds = Z_LVAL_P(cdn_value_zv);
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
