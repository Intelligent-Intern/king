static void king_system_reset_drain_state(void)
{
    king_system_shutdown_requested = false;
    king_system_recovery_requested = false;
    king_system_drain_reason = KING_SYSTEM_DRAIN_REASON_NONE;
}

static void king_system_reset_coordinator_state_runtime(void)
{
    king_system_coordinator_state_present = false;
    king_system_coordinator_state_recovered = false;
    king_system_coordinator_state_version = 0;
    king_system_coordinator_generation = 0;
    king_system_coordinator_created_at = 0;
    king_system_coordinator_last_loaded_at = 0;
    king_system_coordinator_state_path[0] = '\0';
    king_system_coordinator_state_error[0] = '\0';
    king_system_recovery_source_node_id[0] = '\0';
    king_system_recovery_mode = KING_SYSTEM_RECOVERY_MODE_NONE;
    king_system_recovery_plan_id[0] = '\0';
    king_system_recovery_plan_requested_at = 0;
    king_system_recovery_plan_window_seconds = 0;
    snprintf(
        king_system_coordinator_state_status,
        sizeof(king_system_coordinator_state_status),
        "%s",
        "inactive"
    );
}

static void king_system_apply_default_config(king_system_config_t *config)
{
    memset(config, 0, sizeof(king_system_config_t));
    config->enabled = true;
    config->max_concurrent_requests = 1024;
    config->health_check_interval_seconds = 5;
    config->component_timeout_seconds = 1;
    config->enable_cross_component_tracing = true;
    config->enable_performance_monitoring = true;
    config->enable_auto_scaling = true;
    config->enable_circuit_breaker = true;
    config->cluster_id[0] = '\0';
    config->node_id[0] = '\0';
    config->state_root_path[0] = '\0';
    strncpy(config->environment, "development", sizeof(config->environment) - 1);
    ZVAL_UNDEF(&config->global_configuration);
}

static const char *king_system_drain_reason_to_string(
    king_system_drain_reason_t reason
)
{
    switch (reason) {
        case KING_SYSTEM_DRAIN_REASON_COMPONENT_RESTART:
            return "component_restart";
        case KING_SYSTEM_DRAIN_REASON_COMPONENT_RECOVERY:
            return "component_recovery";
        case KING_SYSTEM_DRAIN_REASON_SYSTEM_SHUTDOWN:
            return "system_shutdown";
        case KING_SYSTEM_DRAIN_REASON_NONE:
        default:
            return "none";
    }
}

static uint32_t king_system_recovery_window_seconds(
    king_system_recovery_mode_t mode
)
{
    switch (mode) {
        case KING_SYSTEM_RECOVERY_MODE_NODE:
            return 30;
        case KING_SYSTEM_RECOVERY_MODE_COMPONENT:
            return 15;
        case KING_SYSTEM_RECOVERY_MODE_NONE:
        default:
            return 0;
    }
}

static const char *king_system_recovery_mode_to_string(
    king_system_recovery_mode_t mode
)
{
    switch (mode) {
        case KING_SYSTEM_RECOVERY_MODE_NODE:
            return "node_failure";
        case KING_SYSTEM_RECOVERY_MODE_COMPONENT:
            return "component_failure";
        case KING_SYSTEM_RECOVERY_MODE_NONE:
        default:
            return "none";
    }
}

static void king_system_start_recovery_plan(
    king_system_recovery_mode_t mode,
    const char *source_node_id
)
{
    king_system_recovery_mode = mode;
    king_system_recovery_plan_requested_at = time(NULL);
    king_system_recovery_plan_window_seconds = king_system_recovery_window_seconds(mode);
    if (source_node_id != NULL && source_node_id[0] != '\0') {
        snprintf(
            king_system_recovery_source_node_id,
            sizeof(king_system_recovery_source_node_id),
            "%s",
            source_node_id
        );
    } else {
        king_system_recovery_source_node_id[0] = '\0';
    }

    snprintf(
        king_system_recovery_plan_id,
        sizeof(king_system_recovery_plan_id),
        "%s:%s:" ZEND_LONG_FMT ":%llu:%llu",
        king_system_recovery_mode_to_string(mode),
        king_system_recovery_source_node_id[0] != '\0'
            ? king_system_recovery_source_node_id
            : "local",
        (zend_long) king_system_recovery_plan_requested_at,
        (unsigned long long) king_system_coordinator_state_version,
        (unsigned long long) king_system_coordinator_generation
    );
}

static const char *king_system_recovery_reason_to_string(void)
{
    return king_system_recovery_mode_to_string(king_system_recovery_mode);
}

static void king_system_apply_config(king_system_config_t *config, zval *config_arr)
{
    zval *value = NULL;
    HashTable *ht = Z_ARRVAL_P(config_arr);

    value = zend_hash_str_find(ht, "environment", strlen("environment"));
    if (value && Z_TYPE_P(value) == IS_STRING && Z_STRLEN_P(value) > 0) {
        strncpy(
            config->environment,
            Z_STRVAL_P(value),
            sizeof(config->environment) - 1
        );
    }

    value = zend_hash_str_find(ht, "cluster_id", strlen("cluster_id"));
    if (value && Z_TYPE_P(value) == IS_STRING && Z_STRLEN_P(value) > 0) {
        strncpy(
            config->cluster_id,
            Z_STRVAL_P(value),
            sizeof(config->cluster_id) - 1
        );
    }

    value = zend_hash_str_find(ht, "node_id", strlen("node_id"));
    if (value && Z_TYPE_P(value) == IS_STRING && Z_STRLEN_P(value) > 0) {
        strncpy(
            config->node_id,
            Z_STRVAL_P(value),
            sizeof(config->node_id) - 1
        );
    }

    value = zend_hash_str_find(ht, "state_root_path", strlen("state_root_path"));
    if (value && Z_TYPE_P(value) == IS_STRING && Z_STRLEN_P(value) > 0) {
        strncpy(
            config->state_root_path,
            Z_STRVAL_P(value),
            sizeof(config->state_root_path) - 1
        );
    }

    value = zend_hash_str_find(
        ht,
        "component_timeout_seconds",
        strlen("component_timeout_seconds")
    );
    if (value && Z_TYPE_P(value) != IS_UNDEF) {
        config->component_timeout_seconds = (uint32_t) MAX(
            1,
            (uint32_t) zval_get_long(value)
        );
    }

    value = zend_hash_str_find(ht, "max_concurrent_requests", strlen("max_concurrent_requests"));
    if (value && Z_TYPE_P(value) != IS_UNDEF) {
        config->max_concurrent_requests = (uint32_t) MAX(
            1,
            (uint32_t) zval_get_long(value)
        );
    }

    value = zend_hash_str_find(ht, "health_check_interval_seconds", strlen("health_check_interval_seconds"));
    if (value && Z_TYPE_P(value) != IS_UNDEF) {
        config->health_check_interval_seconds = (uint32_t) MAX(
            1,
            (uint32_t) zval_get_long(value)
        );
    }
}

static void king_system_apply_default_node_identity(king_system_config_t *config)
{
    if (config == NULL || config->state_root_path[0] == '\0') {
        return;
    }

    if (config->cluster_id[0] == '\0') {
        strncpy(config->cluster_id, "local-cluster", sizeof(config->cluster_id) - 1);
    }

    if (config->node_id[0] == '\0') {
        strncpy(config->node_id, "local-node", sizeof(config->node_id) - 1);
    }
}
