/*
 * LLM cache admission policy for native graph inference memory.
 */

typedef struct _king_inference_llm_cache_policy_t {
    bool enabled;
    const char *path;
    zend_long min_free_mb;
    bool fail_closed;
    const char *disk_alert_webhook;
    const char *disk_alert_mcp_service;
    const char *disk_alert_mcp_method;
} king_inference_llm_cache_policy_t;

static const char *king_inference_llm_cache_default_path(void)
{
    return "/tmp/king-llm-cache";
}

static bool king_inference_llm_cache_cstr_non_empty(const char *value)
{
    return value != NULL && value[0] != '\0';
}

static zend_long king_inference_llm_cache_u64_to_long(uint64_t value)
{
    return value > (uint64_t) ZEND_LONG_MAX ? ZEND_LONG_MAX : (zend_long) value;
}

static uint64_t king_inference_llm_cache_min_free_bytes(zend_long min_free_mb)
{
    if (min_free_mb <= 0) {
        return 0;
    }
    if ((uint64_t) min_free_mb > UINT64_MAX / (1024ULL * 1024ULL)) {
        return UINT64_MAX;
    }
    return (uint64_t) min_free_mb * 1024ULL * 1024ULL;
}

static void king_inference_llm_cache_policy_from_compute(
    const kg_high_perf_compute_ai_config_t *compute,
    king_inference_llm_cache_policy_t *policy)
{
    policy->enabled = compute->inference_llm_cache_enable;
    policy->path = king_inference_llm_cache_cstr_non_empty(compute->inference_llm_cache_path)
        ? compute->inference_llm_cache_path
        : king_inference_llm_cache_default_path();
    policy->min_free_mb = compute->inference_llm_cache_min_free_mb;
    policy->fail_closed = compute->inference_llm_cache_fail_closed;
    policy->disk_alert_webhook = compute->inference_llm_cache_disk_alert_webhook;
    policy->disk_alert_mcp_service = compute->inference_llm_cache_disk_alert_mcp_service;
    policy->disk_alert_mcp_method = compute->inference_llm_cache_disk_alert_mcp_method;
}

static zend_result king_inference_llm_cache_apply_bool(
    zval *source,
    const char *field,
    const char *qualified,
    bool *target,
    const char *function_name)
{
    zval *value = king_inference_array_find(source, field);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a boolean when provided.",
            function_name,
            qualified
        );
        return FAILURE;
    }

    *target = Z_TYPE_P(value) == IS_TRUE;
    return SUCCESS;
}

static zend_result king_inference_llm_cache_apply_non_negative_long(
    zval *source,
    const char *field,
    const char *qualified,
    zend_long *target,
    const char *function_name)
{
    zval *value = king_inference_array_find(source, field);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a non-negative integer when provided.",
            function_name,
            qualified
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_inference_llm_cache_apply_string(
    zval *source,
    const char *field,
    const char *qualified,
    const char **target,
    bool allow_empty,
    const char *function_name)
{
    zval *value = king_inference_array_find(source, field);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_STRING || (!allow_empty && Z_STRLEN_P(value) == 0)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a %sstring when provided.",
            function_name,
            qualified,
            allow_empty ? "" : "non-empty "
        );
        return FAILURE;
    }

    *target = Z_STRVAL_P(value);
    return SUCCESS;
}

static zend_result king_inference_llm_cache_overlay(
    king_inference_llm_cache_policy_t *policy,
    zval *source,
    const char *source_name,
    const char *function_name)
{
    zval *cache = king_inference_array_find(source, "llm_cache");
    char qualified[96];

    if (cache == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(cache) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s.llm_cache must be an array when provided.",
            function_name,
            source_name
        );
        return FAILURE;
    }

#define KING_LLM_CACHE_QUAL(field) \
    snprintf(qualified, sizeof(qualified), "%s.llm_cache.%s", source_name, field)

    KING_LLM_CACHE_QUAL("enabled");
    if (king_inference_llm_cache_apply_bool(
            cache,
            "enabled",
            qualified,
            &policy->enabled,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    KING_LLM_CACHE_QUAL("path");
    if (king_inference_llm_cache_apply_string(
            cache,
            "path",
            qualified,
            &policy->path,
            false,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    KING_LLM_CACHE_QUAL("min_free_mb");
    if (king_inference_llm_cache_apply_non_negative_long(
            cache,
            "min_free_mb",
            qualified,
            &policy->min_free_mb,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    KING_LLM_CACHE_QUAL("fail_closed");
    if (king_inference_llm_cache_apply_bool(
            cache,
            "fail_closed",
            qualified,
            &policy->fail_closed,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    KING_LLM_CACHE_QUAL("disk_alert_webhook");
    if (king_inference_llm_cache_apply_string(
            cache,
            "disk_alert_webhook",
            qualified,
            &policy->disk_alert_webhook,
            true,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    KING_LLM_CACHE_QUAL("disk_alert_mcp_service");
    if (king_inference_llm_cache_apply_string(
            cache,
            "disk_alert_mcp_service",
            qualified,
            &policy->disk_alert_mcp_service,
            true,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    KING_LLM_CACHE_QUAL("disk_alert_mcp_method");
    if (king_inference_llm_cache_apply_string(
            cache,
            "disk_alert_mcp_method",
            qualified,
            &policy->disk_alert_mcp_method,
            true,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

#undef KING_LLM_CACHE_QUAL

    return SUCCESS;
}

static zend_result king_inference_llm_cache_policy_from_stream(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph_options,
    king_inference_llm_cache_policy_t *policy,
    const char *function_name)
{
    king_inference_llm_cache_policy_from_compute(&king_high_perf_compute_ai_config, policy);

    if (king_inference_llm_cache_overlay(policy, &model->config, "model", function_name) != SUCCESS
        || king_inference_llm_cache_overlay(policy, &stream->request, "request", function_name) != SUCCESS
        || king_inference_llm_cache_overlay(policy, &stream->options, "options", function_name) != SUCCESS) {
        return FAILURE;
    }
    if (graph_options != NULL
        && king_inference_llm_cache_overlay(policy, graph_options, "graph_options", function_name) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static bool king_inference_llm_cache_has_webhook(
    const king_inference_llm_cache_policy_t *policy)
{
    return king_inference_llm_cache_cstr_non_empty(policy->disk_alert_webhook);
}

static bool king_inference_llm_cache_has_mcp_alert(
    const king_inference_llm_cache_policy_t *policy)
{
    return king_inference_llm_cache_cstr_non_empty(policy->disk_alert_mcp_service)
        && king_inference_llm_cache_cstr_non_empty(policy->disk_alert_mcp_method);
}

static void king_inference_llm_cache_add_alert_config(
    zval *target,
    const king_inference_llm_cache_policy_t *policy,
    bool alert_requested)
{
    zval alert;

    array_init(&alert);
    add_assoc_bool(&alert, "requested", alert_requested);
    add_assoc_bool(&alert, "webhook_configured", king_inference_llm_cache_has_webhook(policy));
    add_assoc_string(
        &alert,
        "webhook",
        policy->disk_alert_webhook != NULL ? policy->disk_alert_webhook : ""
    );
    add_assoc_bool(&alert, "mcp_configured", king_inference_llm_cache_has_mcp_alert(policy));
    add_assoc_string(
        &alert,
        "mcp_service",
        policy->disk_alert_mcp_service != NULL ? policy->disk_alert_mcp_service : ""
    );
    add_assoc_string(
        &alert,
        "mcp_method",
        policy->disk_alert_mcp_method != NULL ? policy->disk_alert_mcp_method : ""
    );
    add_assoc_zval(target, "alert", &alert);
}

static zend_result king_inference_llm_cache_status_array(
    const king_inference_llm_cache_policy_t *policy,
    bool with_memory,
    zval *return_value,
    bool *ok_out)
{
    const char *path = king_inference_llm_cache_cstr_non_empty(policy->path)
        ? policy->path
        : king_inference_llm_cache_default_path();
    bool active = with_memory && policy->enabled;
    bool ok = true;
    bool alert_requested = false;
    uint64_t min_free_bytes = king_inference_llm_cache_min_free_bytes(policy->min_free_mb);

    array_init(return_value);
    add_assoc_string(return_value, "type", "llm_cache_status");
    add_assoc_bool(return_value, "enabled", policy->enabled);
    add_assoc_bool(return_value, "active", active);
    add_assoc_bool(return_value, "memory_required", true);
    add_assoc_bool(return_value, "with_memory", with_memory);
    add_assoc_string(return_value, "path", path);
    add_assoc_long(return_value, "min_free_mb", policy->min_free_mb);
    add_assoc_long(return_value, "min_free_bytes", king_inference_llm_cache_u64_to_long(min_free_bytes));
    add_assoc_bool(return_value, "fail_closed", policy->fail_closed);

    if (!with_memory) {
        add_assoc_bool(return_value, "ok", true);
        add_assoc_bool(return_value, "degraded", false);
        add_assoc_string(return_value, "action", "disabled_without_memory");
        king_inference_llm_cache_add_alert_config(return_value, policy, false);
        *ok_out = true;
        return SUCCESS;
    }
    if (!policy->enabled) {
        add_assoc_bool(return_value, "ok", true);
        add_assoc_bool(return_value, "degraded", false);
        add_assoc_string(return_value, "action", "disabled_by_config");
        king_inference_llm_cache_add_alert_config(return_value, policy, false);
        *ok_out = true;
        return SUCCESS;
    }

    if (mkdir(path, 0775) != 0 && errno != EEXIST) {
        ok = false;
        add_assoc_string(return_value, "action", "cache_path_unavailable");
        add_assoc_string(return_value, "error", strerror(errno));
    } else {
        struct stat st;
        struct statvfs fs;

        errno = 0;
        if (stat(path, &st) != 0) {
            ok = false;
            add_assoc_string(return_value, "action", "cache_path_unavailable");
            add_assoc_string(return_value, "error", strerror(errno));
        } else if (!S_ISDIR(st.st_mode)) {
            ok = false;
            add_assoc_string(return_value, "action", "cache_path_not_directory");
            add_assoc_string(return_value, "error", "configured LLM cache path is not a directory");
        } else if (statvfs(path, &fs) != 0) {
            ok = false;
            add_assoc_string(return_value, "action", "disk_status_unavailable");
            add_assoc_string(return_value, "error", strerror(errno));
        } else {
            uint64_t free_bytes = (uint64_t) fs.f_bavail * (uint64_t) fs.f_frsize;

            add_assoc_long(
                return_value,
                "free_bytes",
                king_inference_llm_cache_u64_to_long(free_bytes)
            );
            add_assoc_long(
                return_value,
                "free_mb",
                king_inference_llm_cache_u64_to_long(free_bytes / (1024ULL * 1024ULL))
            );
            ok = free_bytes >= min_free_bytes;
            add_assoc_string(return_value, "action", ok ? "active" : "disk_floor_failed");
        }
    }

    alert_requested = !ok
        && (king_inference_llm_cache_has_webhook(policy)
            || king_inference_llm_cache_has_mcp_alert(policy));
    add_assoc_bool(return_value, "ok", ok);
    add_assoc_bool(return_value, "degraded", !ok);
    king_inference_llm_cache_add_alert_config(return_value, policy, alert_requested);
    *ok_out = ok;
    return SUCCESS;
}

static zend_result king_inference_llm_cache_prepare_stream(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph_options,
    bool with_memory,
    const char *function_name)
{
    king_inference_llm_cache_policy_t policy;
    zval status;
    bool ok;

    if (king_inference_llm_cache_policy_from_stream(
            model,
            stream,
            graph_options,
            &policy,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    if (!with_memory) {
        return SUCCESS;
    }

    ZVAL_UNDEF(&status);
    if (king_inference_llm_cache_status_array(&policy, with_memory, &status, &ok) != SUCCESS) {
        return FAILURE;
    }

    if (!ok && policy.fail_closed) {
        zval_ptr_dtor(&status);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() refused memory-enabled inference because the LLM cache disk floor was not satisfied.",
            function_name
        );
        return FAILURE;
    }

    if (stream->openai_compatible) {
        zval_ptr_dtor(&status);
        return SUCCESS;
    }

    add_next_index_zval(&stream->native_events, &status);
    return SUCCESS;
}

PHP_FUNCTION(king_inference_llm_cache_status)
{
    zval *zconfig = NULL;
    zval *options = NULL;
    const kg_high_perf_compute_ai_config_t *compute;
    king_inference_llm_cache_policy_t policy;
    bool with_memory;
    bool ok;

    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(zconfig)
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    compute = king_inference_runtime_compute_config(zconfig, "king_inference_llm_cache_status");
    if (compute == NULL) {
        RETURN_THROWS();
    }

    king_inference_llm_cache_policy_from_compute(compute, &policy);
    with_memory = compute->inference_with_memory;
    if (options != NULL) {
        zval *value = king_inference_king_native_find_memory_option(options);

        if (EG(exception)) {
            RETURN_THROWS();
        }
        if (value != NULL) {
            if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "king_inference_llm_cache_status() options.with_memory must be a boolean."
                );
                RETURN_THROWS();
            }
            with_memory = Z_TYPE_P(value) == IS_TRUE;
        }
    }

    if (king_inference_llm_cache_status_array(&policy, with_memory, return_value, &ok) != SUCCESS) {
        RETURN_THROWS();
    }
}
