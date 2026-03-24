static int king_config_collect_userland_overrides(
    king_config_override_state_t *state,
    zval *zopts)
{
    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(zopts), key, value) {
        zend_string *normalized_key = NULL;
        king_config_override_module_t module;
        king_config_override_bucket_t *bucket;

        if (key == NULL) {
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException,
                0,
                "Configuration overrides must use string keys."
            );
            return FAILURE;
        }

        module = king_config_route_override_key(key, &normalized_key);
        if (module == KING_CONFIG_OVERRIDE_NONE) {
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException,
                0,
                "Unsupported runtime config override '%s'. Use namespaced keys under quic., tls., http2., tcp., autoscale., mcp., orchestrator., geometry., smartcontract., ssh., storage., cdn., dns., or otel.",
                ZSTR_VAL(key)
            );
            return FAILURE;
        }

        bucket = king_config_override_bucket_for_module(state, module);
        if (bucket == NULL) {
            if (normalized_key != NULL) {
                zend_string_release(normalized_key);
            }
            zend_throw_exception_ex(
                spl_ce_RuntimeException,
                0,
                "Internal runtime config routing error for override '%s'.",
                ZSTR_VAL(key)
            );
            return FAILURE;
        }

        if (king_config_add_override_to_bucket(bucket, key, normalized_key, value) != SUCCESS) {
            zend_string_release(normalized_key);
            return FAILURE;
        }

        zend_string_release(normalized_key);
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
