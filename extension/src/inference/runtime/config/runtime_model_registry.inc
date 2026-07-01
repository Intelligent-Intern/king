/*
 * Runtime model registry config for OpenAI-compatible model listing.
 */

static bool king_inference_runtime_registry_non_empty(const char *value)
{
    return value != NULL && value[0] != '\0';
}

static char *king_inference_runtime_registry_trim(char *value)
{
    char *end;

    while (*value != '\0' && isspace((unsigned char) *value)) {
        value++;
    }

    end = value + strlen(value);
    while (end > value && isspace((unsigned char) *(end - 1))) {
        end--;
    }
    *end = '\0';
    return value;
}

static char *king_inference_runtime_registry_next_token(char **cursor, const char *delimiters)
{
    char *token;
    char *separator;

    if (cursor == NULL || *cursor == NULL) {
        return NULL;
    }

    token = *cursor;
    separator = strpbrk(token, delimiters);
    if (separator == NULL) {
        *cursor = NULL;
        return token;
    }

    *separator = '\0';
    *cursor = separator + 1;
    return token;
}

static const char *king_inference_runtime_registry_backend_name(
    const char *backend,
    const kg_high_perf_compute_ai_config_t *compute
) {
    const char *profile;

    if (backend != NULL && backend[0] != '\0') {
        if (strcasecmp(backend, "cpu") == 0 || strcasecmp(backend, "king_native_cpu") == 0) {
            return "king_native_cpu";
        }
        if (strcasecmp(backend, "gpu") == 0
            || strcasecmp(backend, "cuda") == 0
            || strcasecmp(backend, "king_native_gpu") == 0) {
            return "king_native_gpu";
        }
        if (strcasecmp(backend, "local") == 0) {
            return "local";
        }
        return NULL;
    }

    profile = king_inference_runtime_non_empty(compute->inference_preferred_model_profile)
        ? compute->inference_preferred_model_profile
        : "auto";
    return strcasecmp(profile, "gpu") == 0 ? "king_native_gpu" : "king_native_cpu";
}

static char *king_inference_runtime_registry_name_from_artifact(char *artifact_path)
{
    char *name;

    name = strrchr(artifact_path, '/');
    name = name != NULL ? name + 1 : artifact_path;

    return name[0] != '\0' ? name : artifact_path;
}

static void king_inference_runtime_registry_add_aliases(
    zval *entry,
    const char *aliases
) {
    zval alias_array;
    char *copy;
    char *part;
    char *cursor;

    array_init(&alias_array);
    if (aliases != NULL && aliases[0] != '\0') {
        copy = estrdup(aliases);
        cursor = copy;
        while ((part = king_inference_runtime_registry_next_token(&cursor, "+,")) != NULL) {
            part = king_inference_runtime_registry_trim(part);
            if (part[0] != '\0') {
                add_next_index_string(&alias_array, part);
            }
        }
        efree(copy);
    }
    add_assoc_zval(entry, "aliases", &alias_array);
}

static void king_inference_runtime_registry_add_shared_config(
    const kg_high_perf_compute_ai_config_t *compute,
    zval *entry
) {
    zval artifact;
    zval kv_cache;
    zval llm_cache;

    add_assoc_string(entry, "owned_by", "king-runtime");
    add_assoc_long(entry, "context_tokens", compute->inference_context_tokens);
    add_assoc_bool(entry, "with_memory", compute->inference_with_memory);

    array_init(&kv_cache);
    add_assoc_long(&kv_cache, "max_context_tokens", compute->inference_context_tokens);
    add_assoc_long(&kv_cache, "page_tokens", compute->inference_kv_page_tokens);
    add_assoc_long(&kv_cache, "element_bytes", compute->inference_kv_element_bytes);
    add_assoc_zval(entry, "kv_cache", &kv_cache);

    array_init(&llm_cache);
    add_assoc_bool(&llm_cache, "enabled", compute->inference_llm_cache_enable);
    add_assoc_string(
        &llm_cache,
        "path",
        king_inference_runtime_non_empty(compute->inference_llm_cache_path)
            ? compute->inference_llm_cache_path
            : "/tmp/king-llm-cache"
    );
    add_assoc_long(&llm_cache, "min_free_mb", compute->inference_llm_cache_min_free_mb);
    add_assoc_bool(&llm_cache, "fail_closed", compute->inference_llm_cache_fail_closed);
    add_assoc_string(
        &llm_cache,
        "disk_alert_webhook",
        compute->inference_llm_cache_disk_alert_webhook != NULL
            ? compute->inference_llm_cache_disk_alert_webhook
            : ""
    );
    add_assoc_string(
        &llm_cache,
        "disk_alert_mcp_service",
        compute->inference_llm_cache_disk_alert_mcp_service != NULL
            ? compute->inference_llm_cache_disk_alert_mcp_service
            : ""
    );
    add_assoc_string(
        &llm_cache,
        "disk_alert_mcp_method",
        compute->inference_llm_cache_disk_alert_mcp_method != NULL
            ? compute->inference_llm_cache_disk_alert_mcp_method
            : ""
    );
    add_assoc_zval(entry, "llm_cache", &llm_cache);

    array_init(&artifact);
    add_assoc_zval(entry, "artifact", &artifact);
}

static void king_inference_runtime_registry_set_artifact(zval *entry, const char *artifact_path)
{
    zval *artifact = king_inference_array_find(entry, "artifact");

    if (artifact != NULL && Z_TYPE_P(artifact) == IS_ARRAY) {
        add_assoc_string(artifact, "path", artifact_path);
        return;
    }

    add_assoc_string(entry, "artifact_path", artifact_path);
}

static void king_inference_runtime_registry_add_gpu_config(
    const kg_high_perf_compute_ai_config_t *compute,
    zval *entry
) {
    zval gpu;
    zval thermal;
    zval power;

    array_init(&gpu);
    add_assoc_bool(&gpu, "enabled", true);
    add_assoc_long(&gpu, "max_gpu_layers", compute->inference_gpu_max_gpu_layers);
    add_assoc_long(&gpu, "vram_reserve_mb", compute->inference_gpu_vram_reserve_mb);
    add_assoc_long(&gpu, "min_free_vram_mb", compute->inference_gpu_min_free_vram_mb);
    add_assoc_bool(&gpu, "allow_system_ram_offload", compute->inference_gpu_allow_system_ram_offload);
    add_assoc_long(&gpu, "system_ram_offload_max_mb", compute->inference_gpu_system_ram_offload_max_mb);
    add_assoc_long(&gpu, "system_ram_offload_min_free_mb", compute->inference_gpu_system_ram_offload_min_free_mb);
    add_assoc_bool(&gpu, "batch_prefill_experimental_enable", compute->inference_gpu_batch_prefill_experimental_enable);

    array_init(&thermal);
    if (king_inference_runtime_non_empty(compute->inference_gpu_thermal_sensor_path)) {
        add_assoc_string(&thermal, "sensor_path", compute->inference_gpu_thermal_sensor_path);
    }
    if (king_inference_runtime_non_empty(compute->inference_gpu_thermal_sensor_command)) {
        add_assoc_string(&thermal, "sensor_command", compute->inference_gpu_thermal_sensor_command);
    }
    add_assoc_double(&thermal, "max_temperature_c", compute->inference_gpu_thermal_max_temperature_c);
    add_assoc_long(&thermal, "check_interval_seconds", compute->inference_gpu_thermal_check_interval_sec);
    add_assoc_bool(&thermal, "allow_unmonitored_gpu", compute->inference_gpu_allow_unmonitored);
    add_assoc_zval(&gpu, "thermal", &thermal);

    array_init(&power);
    if (king_inference_runtime_non_empty(compute->inference_gpu_power_sensor_command)) {
        add_assoc_string(&power, "sensor_command", compute->inference_gpu_power_sensor_command);
    }
    add_assoc_double(&power, "max_watts", compute->inference_gpu_power_max_watts);
    add_assoc_long(&power, "check_interval_seconds", compute->inference_gpu_power_check_interval_sec);
    add_assoc_zval(&gpu, "power", &power);

    add_assoc_zval(entry, "gpu", &gpu);
}

static zend_result king_inference_runtime_registry_add_entry(
    zval *registry,
    const kg_high_perf_compute_ai_config_t *compute,
    const char *name,
    const char *backend,
    const char *artifact_path,
    const char *aliases,
    const char *function_name
) {
    const char *backend_name = king_inference_runtime_registry_backend_name(backend, compute);
    zval entry;

    if (name == NULL || name[0] == '\0' || artifact_path == NULL || artifact_path[0] == '\0') {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() model registry entries require non-empty name and artifact path.",
            function_name
        );
        return FAILURE;
    }
    if (backend_name == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() model registry backend must be cpu, gpu, king_native_cpu, king_native_gpu, or local.",
            function_name
        );
        return FAILURE;
    }
    if (zend_hash_str_exists(Z_ARRVAL_P(registry), name, strlen(name))) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() model registry contains duplicate model id '%s'.",
            function_name,
            name
        );
        return FAILURE;
    }

    array_init(&entry);
    add_assoc_string(&entry, "id", name);
    add_assoc_string(&entry, "name", name);
    add_assoc_string(&entry, "backend", backend_name);
    add_assoc_string(&entry, "registry_source", "king.inference_models");
    king_inference_runtime_registry_add_shared_config(compute, &entry);
    king_inference_runtime_registry_set_artifact(&entry, artifact_path);
    king_inference_runtime_registry_add_aliases(&entry, aliases);
    if (strcmp(backend_name, "king_native_gpu") == 0) {
        king_inference_runtime_registry_add_gpu_config(compute, &entry);
    }

    add_assoc_zval_ex(registry, name, strlen(name), &entry);
    return SUCCESS;
}

static zend_result king_inference_runtime_registry_parse(
    const kg_high_perf_compute_ai_config_t *compute,
    zval *registry,
    const char *function_name
) {
    char *copy;
    char *cursor;
    char *raw_entry;

    copy = estrdup(compute->inference_models);
    cursor = copy;
    while ((raw_entry = king_inference_runtime_registry_next_token(&cursor, "@\n")) != NULL) {
        char *fields[4] = {NULL, NULL, NULL, NULL};
        char *field_cursor;
        char *part;
        char *entry;
        const char *field_delimiters;
        int field_count = 0;
        const char *name;
        const char *backend;
        const char *artifact;
        const char *aliases;

        entry = king_inference_runtime_registry_trim(raw_entry);
        if (entry[0] == '\0') {
            continue;
        }

        field_delimiters = strchr(entry, '|') != NULL ? "|" : ",";
        field_cursor = entry;
        while (field_count < 4
            && (part = king_inference_runtime_registry_next_token(&field_cursor, field_delimiters)) != NULL) {
            fields[field_count++] = king_inference_runtime_registry_trim(part);
        }
        if (field_cursor != NULL && field_cursor[0] != '\0') {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() model registry entries use id,backend,artifact,alias1+alias2.",
                function_name
            );
            efree(copy);
            return FAILURE;
        }

        if (field_count == 1) {
            name = king_inference_runtime_registry_name_from_artifact(fields[0]);
            backend = "";
            artifact = fields[0];
            aliases = "";
        } else if (field_count == 2) {
            name = fields[0];
            backend = "";
            artifact = fields[1];
            aliases = "";
        } else {
            name = fields[0];
            backend = fields[1];
            artifact = fields[2];
            aliases = field_count >= 4 ? fields[3] : "";
        }

        if (king_inference_runtime_registry_add_entry(
                registry,
                compute,
                name,
                backend,
                artifact,
                aliases,
                function_name
            ) != SUCCESS) {
            efree(copy);
            return FAILURE;
        }
    }
    efree(copy);

    return zend_hash_num_elements(Z_ARRVAL_P(registry)) > 0 ? SUCCESS : FAILURE;
}

static zend_result king_inference_runtime_model_registry_config_array(
    const kg_high_perf_compute_ai_config_t *compute,
    zval *return_value,
    const char *function_name
) {
    zval selected;
    zval *name;

    array_init(return_value);
    if (king_inference_runtime_registry_non_empty(compute->inference_models)) {
        if (king_inference_runtime_registry_parse(compute, return_value, function_name) != SUCCESS) {
            if (!EG(exception)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "%s() king.inference_models did not contain any valid model entries.",
                    function_name
                );
            }
            zval_ptr_dtor(return_value);
            return FAILURE;
        }
        return SUCCESS;
    }

    ZVAL_UNDEF(&selected);
    if (king_inference_runtime_model_config_array(compute, &selected, function_name) != SUCCESS) {
        zval_ptr_dtor(return_value);
        return FAILURE;
    }
    add_assoc_string(&selected, "registry_source", "selected_runtime_profile");
    name = king_inference_array_find(&selected, "name");
    add_assoc_zval_ex(
        return_value,
        name != NULL && Z_TYPE_P(name) == IS_STRING && Z_STRLEN_P(name) > 0 ? Z_STRVAL_P(name) : "king-runtime",
        name != NULL && Z_TYPE_P(name) == IS_STRING && Z_STRLEN_P(name) > 0
            ? Z_STRLEN_P(name)
            : sizeof("king-runtime") - 1,
        &selected
    );
    return SUCCESS;
}

PHP_FUNCTION(king_inference_runtime_model_registry_config)
{
    zval *zconfig = NULL;
    const kg_high_perf_compute_ai_config_t *compute;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(zconfig)
    ZEND_PARSE_PARAMETERS_END();

    compute = king_inference_runtime_compute_config(
        zconfig,
        "king_inference_runtime_model_registry_config"
    );
    if (compute == NULL) {
        RETURN_THROWS();
    }

    if (king_inference_runtime_model_registry_config_array(
            compute,
            return_value,
            "king_inference_runtime_model_registry_config"
        ) != SUCCESS) {
        RETURN_THROWS();
    }
}
