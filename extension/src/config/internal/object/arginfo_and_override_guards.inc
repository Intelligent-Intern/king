/*
 * =========================================================================
 * FILENAME:   src/config/internal/object.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * `King\\Config` object surface on top of the native config resource. This
 * file declares arginfo, resolves object/resource handles, canonicalizes and
 * tracks namespaced override keys, enforces freeze and policy gates, and
 * implements the OO `new/get/set/toArray` behavior for runtime config
 * snapshots.
 * =========================================================================
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Config___construct, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Config_new, 0, 0, King\\Config, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Config_get, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Config_set, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Config_toArray, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static void king_config_object_throw_unsupported_key(
    const char *function_name,
    zend_string *key)
{
    king_set_error("Unsupported runtime config override.");
    zend_throw_exception_ex(
        spl_ce_InvalidArgumentException,
        0,
        "Unsupported runtime config override '%s'. Use namespaced keys under quic., tls., http2., tcp., autoscale., mcp., orchestrator., geometry., smartcontract., ssh., storage., cdn., dns., or otel.",
        ZSTR_VAL(key)
    );
}

static king_cfg_t *king_config_object_fetch_native(
    zval *zobject,
    const char *function_name)
{
    king_config_object *intern = php_king_config_obj_from_zend(Z_OBJ_P(zobject));
    king_cfg_t *cfg;

    if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        king_set_error("King\\Config object is not initialized.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() requires an initialized King\\Config object.",
            function_name
        );
        return NULL;
    }

    cfg = zend_fetch_resource(Z_RES(intern->resource), "King\\Config", le_king_cfg);
    if (cfg == NULL) {
        king_set_error("King\\Config object does not hold a valid native config resource.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() requires a valid native King\\Config resource.",
            function_name
        );
        return NULL;
    }

    return cfg;
}

static zend_result king_config_object_require_mutable(
    king_cfg_t *cfg,
    const char *function_name)
{
    if (cfg->frozen) {
        king_set_error("Cannot modify a frozen King\\Config snapshot.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() cannot modify a frozen King\\Config snapshot.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_config_object_require_override_policy(const char *function_name)
{
    if (!king_globals.is_userland_override_allowed) {
        king_set_error("Configuration override is disabled by system policy.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_string *king_config_object_build_prefixed_key(
    const char *export_prefix,
    const char *strip_prefix,
    zend_string *normalized_key)
{
    const char *suffix = ZSTR_VAL(normalized_key);
    size_t suffix_len = ZSTR_LEN(normalized_key);
    size_t export_prefix_len = strlen(export_prefix);
    size_t strip_prefix_len = strip_prefix != NULL ? strlen(strip_prefix) : 0;
    zend_string *result;

    if (
        strip_prefix != NULL
        && suffix_len >= strip_prefix_len
        && memcmp(suffix, strip_prefix, strip_prefix_len) == 0
    ) {
        suffix += strip_prefix_len;
        suffix_len -= strip_prefix_len;
    }

    result = zend_string_alloc(export_prefix_len + suffix_len, 0);
    memcpy(ZSTR_VAL(result), export_prefix, export_prefix_len);
    memcpy(ZSTR_VAL(result) + export_prefix_len, suffix, suffix_len);
    ZSTR_VAL(result)[export_prefix_len + suffix_len] = '\0';

    return result;
}

static zend_string *king_config_object_build_canonical_key(
    king_config_override_module_t module,
    zend_string *normalized_key)
{
    switch (module) {
        case KING_CONFIG_OVERRIDE_TLS:
            return king_config_object_build_prefixed_key("tls.", "tls_", normalized_key);
        case KING_CONFIG_OVERRIDE_QUIC:
            return king_config_object_build_prefixed_key("quic.", NULL, normalized_key);
        case KING_CONFIG_OVERRIDE_HTTP2:
            return king_config_object_build_prefixed_key("http2.", NULL, normalized_key);
        case KING_CONFIG_OVERRIDE_TCP:
            return king_config_object_build_prefixed_key("tcp.", "tcp_", normalized_key);
        case KING_CONFIG_OVERRIDE_AUTOSCALE:
            return king_config_object_build_prefixed_key("autoscale.", NULL, normalized_key);
        case KING_CONFIG_OVERRIDE_MCP_ORCHESTRATOR:
            if (
                ZSTR_LEN(normalized_key) >= sizeof("mcp_") - 1
                && memcmp(ZSTR_VAL(normalized_key), "mcp_", sizeof("mcp_") - 1) == 0
            ) {
                return king_config_object_build_prefixed_key("mcp.", "mcp_", normalized_key);
            }
            if (
                ZSTR_LEN(normalized_key) >= sizeof("orchestrator_") - 1
                && memcmp(
                    ZSTR_VAL(normalized_key),
                    "orchestrator_",
                    sizeof("orchestrator_") - 1
                ) == 0
            ) {
                return king_config_object_build_prefixed_key(
                    "orchestrator.",
                    "orchestrator_",
                    normalized_key
                );
            }
            return NULL;
        case KING_CONFIG_OVERRIDE_GEOMETRY:
            return king_config_object_build_prefixed_key("geometry.", NULL, normalized_key);
        case KING_CONFIG_OVERRIDE_SMART_CONTRACT:
            return king_config_object_build_prefixed_key("smartcontract.", NULL, normalized_key);
        case KING_CONFIG_OVERRIDE_SSH:
            return king_config_object_build_prefixed_key("ssh.", "ssh_", normalized_key);
        case KING_CONFIG_OVERRIDE_STORAGE:
            return king_config_object_build_prefixed_key("storage.", "storage_", normalized_key);
        case KING_CONFIG_OVERRIDE_CDN:
            return king_config_object_build_prefixed_key("cdn.", "cdn_", normalized_key);
        case KING_CONFIG_OVERRIDE_DNS:
            return king_config_object_build_prefixed_key("dns.", "dns_", normalized_key);
        case KING_CONFIG_OVERRIDE_OTEL:
            return king_config_object_build_prefixed_key("otel.", "otel_", normalized_key);
        case KING_CONFIG_OVERRIDE_NONE:
        default:
            return NULL;
    }
}

static zend_string *king_config_object_canonicalize_key(
    zend_string *key,
    king_config_override_module_t *module_out)
{
    zend_string *normalized_key = NULL;
    zend_string *canonical_key;
    king_config_override_module_t module;

    module = king_config_route_override_key(key, &normalized_key);
    if (module == KING_CONFIG_OVERRIDE_NONE || normalized_key == NULL) {
        return NULL;
    }

    canonical_key = king_config_object_build_canonical_key(module, normalized_key);
    zend_string_release(normalized_key);

    if (canonical_key != NULL && module_out != NULL) {
        *module_out = module;
    }

    return canonical_key;
}

static void king_config_object_reset_overrides(king_config_object *intern)
{
    if (!Z_ISUNDEF(intern->overrides)) {
        zval_ptr_dtor(&intern->overrides);
    }

    array_init(&intern->overrides);
}

static zend_result king_config_object_track_override(
    king_config_object *intern,
    zend_string *key,
    zval *value)
{
    zend_string *canonical_key;
    zval value_copy;

    canonical_key = king_config_object_canonicalize_key(key, NULL);
    if (canonical_key == NULL) {
        return FAILURE;
    }

    if (Z_ISUNDEF(intern->overrides) || Z_TYPE(intern->overrides) != IS_ARRAY) {
        array_init(&intern->overrides);
    }

    ZVAL_COPY(&value_copy, value);
    zend_hash_update(Z_ARRVAL(intern->overrides), canonical_key, &value_copy);
    zend_string_release(canonical_key);

    return SUCCESS;
}

static zend_result king_config_object_import_overrides_from_array(
    king_config_object *intern,
    zval *zopts)
{
    zend_string *key;
    zval *value;

    king_config_object_reset_overrides(intern);

    if (
        zopts == NULL
        || Z_TYPE_P(zopts) != IS_ARRAY
        || zend_hash_num_elements(Z_ARRVAL_P(zopts)) == 0
    ) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(zopts), key, value) {
        if (key == NULL) {
            continue;
        }

        if (king_config_object_track_override(intern, key, value) != SUCCESS) {
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static void king_config_object_sync_override_meta(
    king_config_object *intern,
    king_cfg_t *cfg)
{
    zend_long override_count = 0;

    if (!Z_ISUNDEF(intern->overrides) && Z_TYPE(intern->overrides) == IS_ARRAY) {
        override_count = zend_hash_num_elements(Z_ARRVAL(intern->overrides));
    }

    cfg->override_count = override_count;
    cfg->userland_overrides_applied = override_count > 0 ? 1 : 0;
}

