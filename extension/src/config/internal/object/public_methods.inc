PHP_METHOD(King_Config, __construct)
{
    zval *zopts = NULL;
    king_cfg_t *cfg;
    king_config_object *intern;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(zopts)
    ZEND_PARSE_PARAMETERS_END();

    cfg = king_config_new_from_options(zopts);
    if (cfg == NULL) {
        RETURN_THROWS();
    }

    intern = php_king_config_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(cfg, le_king_cfg));

    if (king_config_object_import_overrides_from_array(intern, zopts) != SUCCESS) {
        king_set_error("King\\Config failed to initialize OO override tracking.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "King\\Config failed to initialize OO override tracking."
        );
        RETURN_THROWS();
    }

    king_config_object_sync_override_meta(intern, cfg);
    king_set_error("");
}

PHP_METHOD(King_Config, new)
{
    zval *zopts = NULL;
    king_cfg_t *cfg;
    king_config_object *intern;
    zend_class_entry *called_scope;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(zopts)
    ZEND_PARSE_PARAMETERS_END();

    called_scope = zend_get_called_scope(execute_data);
    if (called_scope == NULL || !instanceof_function(called_scope, king_ce_config)) {
        called_scope = king_ce_config;
    }

    object_init_ex(return_value, called_scope);

    cfg = king_config_new_from_options(zopts);
    if (cfg == NULL) {
        zval_ptr_dtor(return_value);
        RETURN_THROWS();
    }

    intern = php_king_config_obj_from_zend(Z_OBJ_P(return_value));
    ZVAL_RES(&intern->resource, zend_register_resource(cfg, le_king_cfg));

    if (king_config_object_import_overrides_from_array(intern, zopts) != SUCCESS) {
        zval_ptr_dtor(return_value);
        king_set_error("King\\Config::new() failed to initialize OO override tracking.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "King\\Config::new() failed to initialize OO override tracking."
        );
        RETURN_THROWS();
    }

    king_config_object_sync_override_meta(intern, cfg);
    king_set_error("");
}

PHP_METHOD(King_Config, get)
{
    zend_string *key;
    zend_string *canonical_key;
    king_cfg_t *cfg;
    king_config_object *intern;
    zval *explicit_value;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();

    cfg = king_config_object_fetch_native(ZEND_THIS, "King\\Config::get");
    if (cfg == NULL) {
        RETURN_THROWS();
    }

    canonical_key = king_config_object_canonicalize_key(key, NULL);
    if (canonical_key == NULL) {
        king_config_object_throw_unsupported_key("King\\Config::get", key);
        RETURN_THROWS();
    }

    if (king_config_object_snapshot_read(cfg, canonical_key, return_value) == SUCCESS) {
        zend_string_release(canonical_key);
        king_set_error("");
        return;
    }

    intern = php_king_config_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    explicit_value = (
        !Z_ISUNDEF(intern->overrides) && Z_TYPE(intern->overrides) == IS_ARRAY
    ) ? zend_hash_find(Z_ARRVAL(intern->overrides), canonical_key) : NULL;

    if (explicit_value != NULL) {
        zend_string_release(canonical_key);
        king_set_error("");
        RETURN_COPY(explicit_value);
    }

    king_set_error("Requested config key is outside the active OO parity slice.");
    zend_throw_exception_ex(
        spl_ce_InvalidArgumentException,
        0,
        "Config::get() does not yet expose runtime key '%s' outside the active OO parity slice.",
        ZSTR_VAL(canonical_key)
    );
    zend_string_release(canonical_key);
    RETURN_THROWS();
}

PHP_METHOD(King_Config, set)
{
    zend_string *key;
    zval *value;
    king_cfg_t *cfg;
    king_config_object *intern;
    zval options_array;
    zval value_copy;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(key)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();

    cfg = king_config_object_fetch_native(ZEND_THIS, "King\\Config::set");
    if (cfg == NULL) {
        RETURN_THROWS();
    }

    if (king_config_object_require_mutable(cfg, "King\\Config::set") != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_config_object_require_override_policy("King\\Config::set") != SUCCESS) {
        RETURN_THROWS();
    }

    array_init(&options_array);
    ZVAL_COPY(&value_copy, value);
    zend_hash_update(Z_ARRVAL(options_array), key, &value_copy);

    if (king_config_apply_userland_overrides(cfg, &options_array) != SUCCESS) {
        zval_ptr_dtor(&options_array);
        RETURN_THROWS();
    }

    zval_ptr_dtor(&options_array);

    intern = php_king_config_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_config_object_track_override(intern, key, value) != SUCCESS) {
        king_set_error("King\\Config::set() failed to synchronize OO override tracking.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "King\\Config::set() failed to synchronize OO override tracking."
        );
        RETURN_THROWS();
    }

    king_config_object_sync_override_meta(intern, cfg);
    king_set_error("");
}

PHP_METHOD(King_Config, toArray)
{
    king_cfg_t *cfg;
    king_config_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    cfg = king_config_object_fetch_native(ZEND_THIS, "King\\Config::toArray");
    if (cfg == NULL) {
        RETURN_THROWS();
    }

    intern = php_king_config_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_config_object_snapshot_to_array(return_value, cfg);
    king_config_object_overlay_explicit_overrides(return_value, intern);
    king_set_error("");
}

const zend_function_entry king_config_class_methods[] = {
    PHP_ME(King_Config, __construct, arginfo_class_King_Config___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Config, new, arginfo_class_King_Config_new, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(King_Config, get, arginfo_class_King_Config_get, ZEND_ACC_PUBLIC)
    PHP_ME(King_Config, set, arginfo_class_King_Config_set, ZEND_ACC_PUBLIC)
    PHP_ME(King_Config, toArray, arginfo_class_King_Config_toArray, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
