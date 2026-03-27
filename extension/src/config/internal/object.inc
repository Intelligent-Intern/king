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

static zend_result king_config_object_snapshot_read(
    king_cfg_t *cfg,
    zend_string *canonical_key,
    zval *return_value)
{
    if (zend_string_equals_cstr(canonical_key, "quic.cc_algorithm", sizeof("quic.cc_algorithm") - 1)) {
        ZVAL_STRING(return_value, cfg->quic.cc_algorithm != NULL ? cfg->quic.cc_algorithm : "");
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "tls.verify_peer", sizeof("tls.verify_peer") - 1)) {
        ZVAL_BOOL(return_value, cfg->tls.tls_verify_peer);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "http2.enable", sizeof("http2.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->http2.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "http2.max_concurrent_streams",
            sizeof("http2.max_concurrent_streams") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->http2.max_concurrent_streams);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "tcp.enable", sizeof("tcp.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->tcp.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "tcp.tls_min_version_allowed",
            sizeof("tcp.tls_min_version_allowed") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->tcp.tls_min_version_allowed != NULL ? cfg->tcp.tls_min_version_allowed : ""
        );
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "storage.enable", sizeof("storage.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->storage.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "storage.default_redundancy_mode",
            sizeof("storage.default_redundancy_mode") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->storage.default_redundancy_mode != NULL
                ? cfg->storage.default_redundancy_mode
                : ""
        );
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "cdn.enable", sizeof("cdn.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->cdn.enable);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "cdn.cache_mode", sizeof("cdn.cache_mode") - 1)) {
        ZVAL_STRING(return_value, cfg->cdn.cache_mode != NULL ? cfg->cdn.cache_mode : "");
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "dns.server_enable",
            sizeof("dns.server_enable") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->dns.server_enable);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "dns.mode", sizeof("dns.mode") - 1)) {
        ZVAL_STRING(return_value, cfg->dns.mode != NULL ? cfg->dns.mode : "");
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "otel.enable", sizeof("otel.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->observability.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "otel.service_name",
            sizeof("otel.service_name") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->observability.service_name != NULL ? cfg->observability.service_name : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "autoscale.provider",
            sizeof("autoscale.provider") - 1
        )
    ) {
        ZVAL_STRING(return_value, cfg->autoscale.provider != NULL ? cfg->autoscale.provider : "");
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "autoscale.max_nodes",
            sizeof("autoscale.max_nodes") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->autoscale.max_nodes);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "mcp.enable_request_caching",
            sizeof("mcp.enable_request_caching") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->mcp.mcp_enable_request_caching);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "mcp.default_request_timeout_ms",
            sizeof("mcp.default_request_timeout_ms") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->mcp.mcp_default_request_timeout_ms);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "mcp.transfer_state_path",
            sizeof("mcp.transfer_state_path") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.mcp_transfer_state_path != NULL ? cfg->mcp.mcp_transfer_state_path : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.enable_distributed_tracing",
            sizeof("orchestrator.enable_distributed_tracing") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->mcp.orchestrator_enable_distributed_tracing);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.execution_backend",
            sizeof("orchestrator.execution_backend") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_execution_backend != NULL
                ? cfg->mcp.orchestrator_execution_backend
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.worker_queue_path",
            sizeof("orchestrator.worker_queue_path") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_worker_queue_path != NULL
                ? cfg->mcp.orchestrator_worker_queue_path
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.remote_host",
            sizeof("orchestrator.remote_host") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_remote_host != NULL
                ? cfg->mcp.orchestrator_remote_host
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.remote_port",
            sizeof("orchestrator.remote_port") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->mcp.orchestrator_remote_port);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.state_path",
            sizeof("orchestrator.state_path") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_state_path != NULL ? cfg->mcp.orchestrator_state_path : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "geometry.default_vector_dimensions",
            sizeof("geometry.default_vector_dimensions") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->semantic_geometry.default_vector_dimensions);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "geometry.calculation_precision",
            sizeof("geometry.calculation_precision") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->semantic_geometry.calculation_precision != NULL
                ? cfg->semantic_geometry.calculation_precision
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "smartcontract.enable",
            sizeof("smartcontract.enable") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->smart_contract.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "smartcontract.dlt_provider",
            sizeof("smartcontract.dlt_provider") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->smart_contract.dlt_provider != NULL ? cfg->smart_contract.dlt_provider : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "ssh.gateway_enable",
            sizeof("ssh.gateway_enable") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->ssh.gateway_enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "ssh.gateway_auth_mode",
            sizeof("ssh.gateway_auth_mode") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->ssh.gateway_auth_mode != NULL ? cfg->ssh.gateway_auth_mode : ""
        );
        return SUCCESS;
    }

    return FAILURE;
}

static void king_config_object_snapshot_to_array(zval *target, king_cfg_t *cfg)
{
    array_init(target);

    add_assoc_string(target, "quic.cc_algorithm", cfg->quic.cc_algorithm != NULL ? cfg->quic.cc_algorithm : "");
    add_assoc_bool(target, "tls.verify_peer", cfg->tls.tls_verify_peer);
    add_assoc_bool(target, "http2.enable", cfg->http2.enable);
    add_assoc_long(target, "http2.max_concurrent_streams", cfg->http2.max_concurrent_streams);
    add_assoc_bool(target, "tcp.enable", cfg->tcp.enable);
    add_assoc_string(
        target,
        "tcp.tls_min_version_allowed",
        cfg->tcp.tls_min_version_allowed != NULL ? cfg->tcp.tls_min_version_allowed : ""
    );
    add_assoc_bool(target, "storage.enable", cfg->storage.enable);
    add_assoc_string(
        target,
        "storage.default_redundancy_mode",
        cfg->storage.default_redundancy_mode != NULL ? cfg->storage.default_redundancy_mode : ""
    );
    add_assoc_bool(target, "cdn.enable", cfg->cdn.enable);
    add_assoc_string(target, "cdn.cache_mode", cfg->cdn.cache_mode != NULL ? cfg->cdn.cache_mode : "");
    add_assoc_bool(target, "dns.server_enable", cfg->dns.server_enable);
    add_assoc_string(target, "dns.mode", cfg->dns.mode != NULL ? cfg->dns.mode : "");
    add_assoc_bool(target, "otel.enable", cfg->observability.enable);
    add_assoc_string(
        target,
        "otel.service_name",
        cfg->observability.service_name != NULL ? cfg->observability.service_name : ""
    );
    add_assoc_string(
        target,
        "autoscale.provider",
        cfg->autoscale.provider != NULL ? cfg->autoscale.provider : ""
    );
    add_assoc_long(target, "autoscale.max_nodes", cfg->autoscale.max_nodes);
    add_assoc_bool(target, "mcp.enable_request_caching", cfg->mcp.mcp_enable_request_caching);
    add_assoc_long(
        target,
        "mcp.default_request_timeout_ms",
        cfg->mcp.mcp_default_request_timeout_ms
    );
    add_assoc_string(
        target,
        "mcp.transfer_state_path",
        cfg->mcp.mcp_transfer_state_path != NULL ? cfg->mcp.mcp_transfer_state_path : ""
    );
    add_assoc_bool(
        target,
        "orchestrator.enable_distributed_tracing",
        cfg->mcp.orchestrator_enable_distributed_tracing
    );
    add_assoc_string(
        target,
        "orchestrator.execution_backend",
        cfg->mcp.orchestrator_execution_backend != NULL ? cfg->mcp.orchestrator_execution_backend : ""
    );
    add_assoc_string(
        target,
        "orchestrator.worker_queue_path",
        cfg->mcp.orchestrator_worker_queue_path != NULL ? cfg->mcp.orchestrator_worker_queue_path : ""
    );
    add_assoc_string(
        target,
        "orchestrator.remote_host",
        cfg->mcp.orchestrator_remote_host != NULL ? cfg->mcp.orchestrator_remote_host : ""
    );
    add_assoc_long(
        target,
        "orchestrator.remote_port",
        cfg->mcp.orchestrator_remote_port
    );
    add_assoc_string(
        target,
        "orchestrator.state_path",
        cfg->mcp.orchestrator_state_path != NULL ? cfg->mcp.orchestrator_state_path : ""
    );
    add_assoc_long(
        target,
        "geometry.default_vector_dimensions",
        cfg->semantic_geometry.default_vector_dimensions
    );
    add_assoc_string(
        target,
        "geometry.calculation_precision",
        cfg->semantic_geometry.calculation_precision != NULL
            ? cfg->semantic_geometry.calculation_precision
            : ""
    );
    add_assoc_bool(target, "smartcontract.enable", cfg->smart_contract.enable);
    add_assoc_string(
        target,
        "smartcontract.dlt_provider",
        cfg->smart_contract.dlt_provider != NULL ? cfg->smart_contract.dlt_provider : ""
    );
    add_assoc_bool(target, "ssh.gateway_enable", cfg->ssh.gateway_enable);
    add_assoc_string(
        target,
        "ssh.gateway_auth_mode",
        cfg->ssh.gateway_auth_mode != NULL ? cfg->ssh.gateway_auth_mode : ""
    );
}

static void king_config_object_overlay_explicit_overrides(
    zval *target,
    king_config_object *intern)
{
    zend_string *key;
    zval *value;
    zval value_copy;

    if (Z_ISUNDEF(intern->overrides) || Z_TYPE(intern->overrides) != IS_ARRAY) {
        return;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(intern->overrides), key, value) {
        if (key == NULL) {
            continue;
        }

        ZVAL_COPY(&value_copy, value);
        zend_hash_update(Z_ARRVAL_P(target), key, &value_copy);
    } ZEND_HASH_FOREACH_END();
}

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
