int king_autoscaling_collect_metrics(king_load_metrics_t *metrics)
{
    king_autoscaling_signal_snapshot_t snapshot;

    if (metrics == NULL) {
        return FAILURE;
    }

    if (king_autoscaling_collect_live_signal_snapshot(&snapshot) != SUCCESS) {
        return FAILURE;
    }

    *metrics = snapshot.metrics;
    return SUCCESS;
}

int king_autoscaling_evaluate_scaling_decision(const king_load_metrics_t *metrics)
{
    if (metrics == NULL) {
        return 0;
    }

    if (
        metrics->cpu_utilization_percent
        > (double) king_autoscaling_runtime.config.scale_up_cpu_threshold_percent
    ) {
        return 1;
    }

    if (
        metrics->cpu_utilization_percent
        < (double) king_autoscaling_runtime.config.scale_down_cpu_threshold_percent
    ) {
        return -1;
    }

    return 0;
}

PHP_FUNCTION(king_autoscaling_init)
{
    zval *config_arr;
    kg_cloud_autoscale_config_t runtime_config;

    if (zend_parse_parameters(1, "a", &config_arr) == FAILURE) {
        RETURN_FALSE;
    }

    if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config_arr)) > 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        RETURN_THROWS();
    }

    king_autoscaling_copy_runtime_config(&runtime_config, &king_cloud_autoscale_config);

    if (
        zend_hash_num_elements(Z_ARRVAL_P(config_arr)) > 0
        && kg_config_cloud_autoscale_apply_userland_config_to(&runtime_config, config_arr) != SUCCESS
    ) {
        king_autoscaling_free_runtime_config(&runtime_config);
        RETURN_FALSE;
    }

    if (king_autoscaling_init_system(&runtime_config) != SUCCESS) {
        king_autoscaling_free_runtime_config(&runtime_config);
        RETURN_FALSE;
    }

    king_autoscaling_free_runtime_config(&runtime_config);
    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_start_monitoring)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_autoscaling_runtime.monitoring_active = 1;
    if (!king_autoscaling_monitor_tick()) {
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_stop_monitoring)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_autoscaling_runtime.monitoring_active = 0;
    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_get_metrics)
{
    king_load_metrics_t metrics;

    ZEND_PARSE_PARAMETERS_NONE();

    if (king_autoscaling_collect_metrics(&metrics) != SUCCESS) {
        RETURN_FALSE;
    }

    array_init(return_value);
    add_assoc_double(return_value, "cpu_utilization", metrics.cpu_utilization_percent);
    add_assoc_double(return_value, "memory_utilization", metrics.memory_utilization_percent);
    add_assoc_long(return_value, "active_connections", (zend_long) metrics.active_connections);
    add_assoc_long(return_value, "requests_per_second", (zend_long) metrics.requests_per_second);
    add_assoc_long(return_value, "response_time_ms", (zend_long) metrics.response_time_ms);
    add_assoc_long(return_value, "queue_depth", (zend_long) metrics.queue_depth);
    add_assoc_long(return_value, "timestamp", (zend_long) metrics.timestamp);
}

PHP_FUNCTION(king_autoscaling_get_status)
{
    zval last_monitor_signal_snapshot;
    zval last_monitor_decision_details;
    zend_bool cooldown_blocked;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    add_assoc_bool(return_value, "initialized", king_autoscaling_runtime.initialized);
    add_assoc_bool(return_value, "monitoring_active", king_autoscaling_runtime.monitoring_active);
    add_assoc_long(return_value, "current_instances", (zend_long) king_current_instances);
    add_assoc_string(
        return_value,
        "provider",
        king_autoscaling_runtime.config.provider != NULL
            ? king_autoscaling_runtime.config.provider
            : ""
    );
    add_assoc_string(return_value, "provider_mode", (char *) king_autoscaling_get_provider_mode());
    add_assoc_bool(
        return_value,
        "controller_token_configured",
        king_autoscaling_runtime.controller_token_configured
    );
    add_assoc_long(
        return_value,
        "managed_nodes",
        (zend_long) king_autoscaling_runtime.managed_node_count
    );
    add_assoc_long(
        return_value,
        "active_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_active_nodes()
    );
    add_assoc_long(
        return_value,
        "provisioned_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_PROVISIONED)
    );
    add_assoc_long(
        return_value,
        "registered_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_REGISTERED)
    );
    add_assoc_long(
        return_value,
        "draining_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_DRAINING)
    );
    add_assoc_long(
        return_value,
        "cooldown_remaining_sec",
        (zend_long) king_autoscaling_get_cooldown_remaining(time(NULL))
    );
    add_assoc_long(
        return_value,
        "last_monitor_tick_at",
        (zend_long) king_autoscaling_runtime.last_monitor_tick_at
    );
    add_assoc_long(return_value, "action_count", (zend_long) king_autoscaling_runtime.action_count);
    add_assoc_string(
        return_value,
        "api_endpoint",
        king_autoscaling_runtime.config.api_endpoint != NULL
            ? king_autoscaling_runtime.config.api_endpoint
            : ""
    );
    add_assoc_string(
        return_value,
        "state_path",
        king_autoscaling_runtime.config.state_path != NULL
            ? king_autoscaling_runtime.config.state_path
            : ""
    );
    add_assoc_string(return_value, "last_action_kind", king_autoscaling_runtime.last_action_kind);
    add_assoc_string(return_value, "last_signal_source", king_autoscaling_runtime.last_signal_source);
    add_assoc_string(return_value, "last_decision_reason", king_autoscaling_runtime.last_decision_reason);
    add_assoc_string(
        return_value,
        "last_monitor_decision",
        king_autoscaling_runtime.last_monitor_decision
    );
    array_init(&last_monitor_signal_snapshot);
    add_assoc_double(
        &last_monitor_signal_snapshot,
        "cpu_utilization",
        king_autoscaling_runtime.last_monitor_metrics.cpu_utilization_percent
    );
    add_assoc_double(
        &last_monitor_signal_snapshot,
        "memory_utilization",
        king_autoscaling_runtime.last_monitor_metrics.memory_utilization_percent
    );
    add_assoc_long(
        &last_monitor_signal_snapshot,
        "active_connections",
        (zend_long) king_autoscaling_runtime.last_monitor_metrics.active_connections
    );
    add_assoc_long(
        &last_monitor_signal_snapshot,
        "requests_per_second",
        (zend_long) king_autoscaling_runtime.last_monitor_metrics.requests_per_second
    );
    add_assoc_long(
        &last_monitor_signal_snapshot,
        "response_time_ms",
        (zend_long) king_autoscaling_runtime.last_monitor_metrics.response_time_ms
    );
    add_assoc_long(
        &last_monitor_signal_snapshot,
        "queue_depth",
        (zend_long) king_autoscaling_runtime.last_monitor_metrics.queue_depth
    );
    add_assoc_long(
        &last_monitor_signal_snapshot,
        "timestamp",
        (zend_long) king_autoscaling_runtime.last_monitor_metrics.timestamp
    );
    add_assoc_zval(return_value, "last_monitor_signal_snapshot", &last_monitor_signal_snapshot);

    cooldown_blocked =
        (
            strcmp(king_autoscaling_runtime.last_monitor_decision, "scale_up") == 0
            || strcmp(king_autoscaling_runtime.last_monitor_decision, "scale_down") == 0
        )
        && strcmp(king_autoscaling_runtime.last_action_kind, "monitor_tick") == 0
        && strncmp(
            king_autoscaling_runtime.last_warning,
            "Autoscaling controller is in cooldown",
            sizeof("Autoscaling controller is in cooldown") - 1
        ) == 0;

    array_init(&last_monitor_decision_details);
    add_assoc_bool(&last_monitor_decision_details, "blocked_by_cooldown", cooldown_blocked);
    king_autoscaling_add_signal_mask_array(
        &last_monitor_decision_details,
        "live_signals",
        king_autoscaling_runtime.last_monitor_live_signal_mask
    );
    king_autoscaling_add_signal_mask_array(
        &last_monitor_decision_details,
        "scale_up_signals",
        king_autoscaling_runtime.last_monitor_scale_up_signal_mask
    );
    king_autoscaling_add_signal_mask_array(
        &last_monitor_decision_details,
        "scale_down_ready_signals",
        king_autoscaling_runtime.last_monitor_scale_down_ready_mask
    );
    king_autoscaling_add_signal_mask_array(
        &last_monitor_decision_details,
        "hold_blockers",
        king_autoscaling_runtime.last_monitor_hold_blocker_mask
    );
    add_assoc_zval(return_value, "last_monitor_decision_details", &last_monitor_decision_details);
    add_assoc_string(return_value, "last_error", king_autoscaling_runtime.last_error);
    add_assoc_string(return_value, "last_warning", king_autoscaling_runtime.last_warning);
    add_assoc_string(
        return_value,
        "hetzner_budget_path",
        king_autoscaling_runtime.config.hetzner_budget_path != NULL
            ? king_autoscaling_runtime.config.hetzner_budget_path
            : ""
    );
    add_assoc_long(
        return_value,
        "spend_warning_threshold_percent",
        king_autoscaling_runtime.config.spend_warning_threshold_percent
    );
    add_assoc_long(
        return_value,
        "spend_hard_limit_percent",
        king_autoscaling_runtime.config.spend_hard_limit_percent
    );
    add_assoc_long(
        return_value,
        "quota_warning_threshold_percent",
        king_autoscaling_runtime.config.quota_warning_threshold_percent
    );
    add_assoc_long(
        return_value,
        "quota_hard_limit_percent",
        king_autoscaling_runtime.config.quota_hard_limit_percent
    );
    add_assoc_long(
        return_value,
        "spend_usage_percent",
        king_autoscaling_runtime.spend_usage_percent
    );
    add_assoc_long(
        return_value,
        "quota_usage_percent",
        king_autoscaling_runtime.quota_usage_percent
    );
    add_assoc_string(
        return_value,
        "spend_status",
        (char *) king_autoscaling_budget_status_to_string(king_autoscaling_runtime.spend_status)
    );
    add_assoc_string(
        return_value,
        "quota_status",
        (char *) king_autoscaling_budget_status_to_string(king_autoscaling_runtime.quota_status)
    );
    add_assoc_string(return_value, "budget_probe_error", king_autoscaling_runtime.budget_probe_error);
}

static zend_bool king_autoscaling_finalize_node_update(const char *action_kind)
{
    king_autoscaling_runtime_sync_instance_count();
    if (king_autoscaling_runtime_persist_state() != SUCCESS) {
        return 0;
    }

    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "%s",
        action_kind
    );
    return 1;
}

PHP_FUNCTION(king_autoscaling_get_nodes)
{
    size_t index;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        zval node_entry;
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];

        array_init(&node_entry);
        add_assoc_long(&node_entry, "server_id", node->server_id);
        add_assoc_string(&node_entry, "name", node->name);
        add_assoc_string(&node_entry, "provider_status", node->provider_status);
        add_assoc_string(
            &node_entry,
            "lifecycle",
            (char *) king_autoscaling_node_lifecycle_to_string(node->lifecycle_state)
        );
        add_assoc_bool(&node_entry, "active", node->active);
        add_assoc_long(&node_entry, "created_at", (zend_long) node->created_at);
        add_assoc_long(&node_entry, "registered_at", (zend_long) node->registered_at);
        add_assoc_long(&node_entry, "ready_at", (zend_long) node->ready_at);
        add_assoc_long(&node_entry, "draining_at", (zend_long) node->draining_at);
        add_assoc_long(&node_entry, "deleted_at", (zend_long) node->deleted_at);
        add_next_index_zval(return_value, &node_entry);
    }
}

PHP_FUNCTION(king_autoscaling_register_node)
{
    zend_long server_id;
    char *name = NULL;
    size_t name_length = 0;
    king_autoscaling_managed_node_t *node;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_LONG(server_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING_OR_NULL(name, name_length)
    ZEND_PARSE_PARAMETERS_END();

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );

    node = king_autoscaling_runtime_find_node(server_id);
    if (node == NULL) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is unknown to the autoscaling controller.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is already deleted.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    if (name != NULL && name_length > 0) {
        snprintf(node->name, sizeof(node->name), "%s", name);
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_PROVISIONED) {
        node->lifecycle_state = KING_AUTOSCALING_NODE_REGISTERED;
        node->registered_at = time(NULL);
        snprintf(node->provider_status, sizeof(node->provider_status), "%s", "registered");
    } else if (node->registered_at == 0) {
        node->registered_at = time(NULL);
    }

    king_autoscaling_runtime_normalize_node(node);
    if (!king_autoscaling_finalize_node_update("register_node")) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_mark_node_ready)
{
    zend_long server_id;
    king_autoscaling_managed_node_t *node;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(server_id)
    ZEND_PARSE_PARAMETERS_END();

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );

    node = king_autoscaling_runtime_find_node(server_id);
    if (node == NULL) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is unknown to the autoscaling controller.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    switch (node->lifecycle_state) {
        case KING_AUTOSCALING_NODE_READY:
            break;
        case KING_AUTOSCALING_NODE_REGISTERED:
            node->lifecycle_state = KING_AUTOSCALING_NODE_READY;
            node->ready_at = time(NULL);
            snprintf(node->provider_status, sizeof(node->provider_status), "%s", "ready");
            break;
        case KING_AUTOSCALING_NODE_PROVISIONED:
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Managed node %ld must register before it can become ready.",
                (long) server_id
            );
            RETURN_FALSE;
        case KING_AUTOSCALING_NODE_DRAINING:
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Managed node %ld is draining and cannot be re-admitted directly.",
                (long) server_id
            );
            RETURN_FALSE;
        case KING_AUTOSCALING_NODE_DELETED:
        default:
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Managed node %ld is already deleted.",
                (long) server_id
            );
            RETURN_FALSE;
    }

    king_autoscaling_runtime_normalize_node(node);
    if (!king_autoscaling_finalize_node_update("mark_node_ready")) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_drain_node)
{
    zend_long server_id;
    size_t active_nodes;
    size_t minimum_managed;
    king_autoscaling_managed_node_t *node;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(server_id)
    ZEND_PARSE_PARAMETERS_END();

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );

    node = king_autoscaling_runtime_find_node(server_id);
    if (node == NULL) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is unknown to the autoscaling controller.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING) {
        RETURN_TRUE;
    }

    if (node->lifecycle_state != KING_AUTOSCALING_NODE_READY) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is not ready and cannot be drained.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    active_nodes = king_autoscaling_runtime_count_active_nodes();
    minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
        ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
        : 0;
    if (active_nodes <= minimum_managed) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld cannot be drained because the cluster is already at its configured floor.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    node->lifecycle_state = KING_AUTOSCALING_NODE_DRAINING;
    node->draining_at = time(NULL);
    snprintf(node->provider_status, sizeof(node->provider_status), "%s", "draining");
    king_autoscaling_runtime_normalize_node(node);
    king_autoscaling_runtime.last_scale_down_at = node->draining_at;

    if (!king_autoscaling_finalize_node_update("drain_node")) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}
