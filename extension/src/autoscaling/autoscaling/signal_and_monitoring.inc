static const char *king_autoscaling_node_lifecycle_to_string(
    king_autoscaling_node_lifecycle_t lifecycle_state)
{
    switch (lifecycle_state) {
        case KING_AUTOSCALING_NODE_REGISTERED:
            return "registered";
        case KING_AUTOSCALING_NODE_READY:
            return "ready";
        case KING_AUTOSCALING_NODE_DRAINING:
            return "draining";
        case KING_AUTOSCALING_NODE_DELETED:
            return "deleted";
        case KING_AUTOSCALING_NODE_PROVISIONED:
        default:
            return "provisioned";
    }
}

static king_autoscaling_node_lifecycle_t king_autoscaling_node_lifecycle_from_string(
    const char *raw_value,
    zend_bool active,
    time_t deleted_at,
    time_t registered_at,
    time_t ready_at,
    time_t draining_at)
{
    if (raw_value != NULL && raw_value[0] != '\0') {
        if (strcasecmp(raw_value, "provisioned") == 0) {
            return KING_AUTOSCALING_NODE_PROVISIONED;
        }
        if (strcasecmp(raw_value, "registered") == 0) {
            return KING_AUTOSCALING_NODE_REGISTERED;
        }
        if (strcasecmp(raw_value, "ready") == 0) {
            return KING_AUTOSCALING_NODE_READY;
        }
        if (strcasecmp(raw_value, "draining") == 0) {
            return KING_AUTOSCALING_NODE_DRAINING;
        }
        if (strcasecmp(raw_value, "deleted") == 0) {
            return KING_AUTOSCALING_NODE_DELETED;
        }
    }

    if (deleted_at > 0) {
        return KING_AUTOSCALING_NODE_DELETED;
    }
    if (active || ready_at > 0) {
        return KING_AUTOSCALING_NODE_READY;
    }
    if (draining_at > 0) {
        return KING_AUTOSCALING_NODE_DRAINING;
    }
    if (registered_at > 0) {
        return KING_AUTOSCALING_NODE_REGISTERED;
    }

    return KING_AUTOSCALING_NODE_PROVISIONED;
}

static void king_autoscaling_runtime_normalize_node(king_autoscaling_managed_node_t *node)
{
    if (node == NULL) {
        return;
    }

    if (node->deleted_at > 0) {
        node->lifecycle_state = KING_AUTOSCALING_NODE_DELETED;
    }

    switch (node->lifecycle_state) {
        case KING_AUTOSCALING_NODE_READY:
            node->active = 1;
            if (node->registered_at == 0) {
                node->registered_at = node->created_at;
            }
            if (node->ready_at == 0) {
                node->ready_at = node->registered_at;
            }
            break;
        case KING_AUTOSCALING_NODE_REGISTERED:
            node->active = 0;
            if (node->registered_at == 0) {
                node->registered_at = node->created_at;
            }
            break;
        case KING_AUTOSCALING_NODE_DRAINING:
            node->active = 0;
            if (node->registered_at == 0) {
                node->registered_at = node->created_at;
            }
            if (node->ready_at == 0) {
                node->ready_at = node->registered_at;
            }
            if (node->draining_at == 0) {
                node->draining_at = node->ready_at;
            }
            break;
        case KING_AUTOSCALING_NODE_DELETED:
            node->active = 0;
            if (node->deleted_at == 0) {
                node->deleted_at = node->draining_at > 0
                    ? node->draining_at
                    : node->created_at;
            }
            break;
        case KING_AUTOSCALING_NODE_PROVISIONED:
        default:
            node->active = 0;
            break;
    }
}

static size_t king_autoscaling_runtime_count_nodes_in_state(
    king_autoscaling_node_lifecycle_t lifecycle_state)
{
    size_t index;
    size_t count = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        if (king_autoscaling_runtime.managed_nodes[index].lifecycle_state == lifecycle_state) {
            count++;
        }
    }

    return count;
}

static size_t king_autoscaling_runtime_count_pending_nodes(void)
{
    return king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_PROVISIONED)
        + king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_REGISTERED)
        + king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_DRAINING);
}

static time_t king_autoscaling_get_cooldown_remaining(time_t now)
{
    time_t last_action_at = 0;
    time_t elapsed;

    if (king_autoscaling_runtime.config.cooldown_period_sec <= 0) {
        return 0;
    }

    if (king_autoscaling_runtime.last_scale_up_at > last_action_at) {
        last_action_at = king_autoscaling_runtime.last_scale_up_at;
    }
    if (king_autoscaling_runtime.last_scale_down_at > last_action_at) {
        last_action_at = king_autoscaling_runtime.last_scale_down_at;
    }

    if (last_action_at == 0) {
        return 0;
    }

    if (now <= last_action_at) {
        return (time_t) king_autoscaling_runtime.config.cooldown_period_sec;
    }

    elapsed = now - last_action_at;
    if (elapsed >= king_autoscaling_runtime.config.cooldown_period_sec) {
        return 0;
    }

    return (time_t) (king_autoscaling_runtime.config.cooldown_period_sec - elapsed);
}

static uint32_t king_autoscaling_resolve_scale_up_step(void)
{
    const char *policy = king_autoscaling_runtime.config.scale_up_policy;
    zend_long desired = 1;

    if (policy != NULL && strncmp(policy, "add_nodes:", sizeof("add_nodes:") - 1) == 0) {
        const char *raw = policy + (sizeof("add_nodes:") - 1);
        if (raw[0] != '\0') {
            desired = ZEND_STRTOL(raw, NULL, 10);
        }
    }

    if (desired <= 0) {
        desired = 1;
    }
    if (desired > king_autoscaling_runtime.config.max_scale_step) {
        desired = king_autoscaling_runtime.config.max_scale_step;
    }

    return (uint32_t) desired;
}

static zend_bool king_autoscaling_lookup_signal_value(
    const char *const *metric_names,
    double *value_out,
    time_t *timestamp_out)
{
    size_t index;

    for (index = 0; metric_names[index] != NULL; index++) {
        if (king_telemetry_lookup_metric(metric_names[index], value_out, NULL, timestamp_out)) {
            return 1;
        }
    }

    return 0;
}

static const char *king_autoscaling_resolve_signal_source(
    const king_autoscaling_signal_snapshot_t *snapshot)
{
    if (snapshot->telemetry_signal_present && snapshot->system_signal_present) {
        return "telemetry+system";
    }

    if (snapshot->telemetry_signal_present) {
        return "telemetry";
    }

    if (snapshot->system_signal_present) {
        return "system";
    }

    return "none";
}

static uint32_t king_autoscaling_build_live_signal_mask(
    const king_autoscaling_signal_snapshot_t *snapshot)
{
    uint32_t mask = 0u;

    if (snapshot == NULL) {
        return 0u;
    }

    if (snapshot->cpu_live) {
        mask |= KING_AUTOSCALING_SIGNAL_CPU;
    }
    if (snapshot->memory_live) {
        mask |= KING_AUTOSCALING_SIGNAL_MEMORY;
    }
    if (snapshot->active_connections_live) {
        mask |= KING_AUTOSCALING_SIGNAL_ACTIVE_CONNECTIONS;
    }
    if (snapshot->requests_per_second_live) {
        mask |= KING_AUTOSCALING_SIGNAL_REQUESTS_PER_SECOND;
    }
    if (snapshot->response_time_live) {
        mask |= KING_AUTOSCALING_SIGNAL_RESPONSE_TIME;
    }
    if (snapshot->queue_depth_live) {
        mask |= KING_AUTOSCALING_SIGNAL_QUEUE_DEPTH;
    }

    return mask;
}

static uint32_t king_autoscaling_build_scale_up_signal_mask(
    const king_autoscaling_signal_snapshot_t *snapshot,
    uint64_t instance_divisor)
{
    uint32_t mask = 0u;

    if (snapshot == NULL) {
        return 0u;
    }

    if (
        snapshot->cpu_live
        && snapshot->metrics.cpu_utilization_percent
            >= (double) king_autoscaling_runtime.config.scale_up_cpu_threshold_percent
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_CPU;
    }
    if (snapshot->queue_depth_live && snapshot->metrics.queue_depth >= 8) {
        mask |= KING_AUTOSCALING_SIGNAL_QUEUE_DEPTH;
    }
    if (snapshot->response_time_live && snapshot->metrics.response_time_ms >= 250) {
        mask |= KING_AUTOSCALING_SIGNAL_RESPONSE_TIME;
    }
    if (
        snapshot->requests_per_second_live
        && snapshot->metrics.requests_per_second >= (450 * instance_divisor)
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_REQUESTS_PER_SECOND;
    }
    if (
        snapshot->active_connections_live
        && snapshot->metrics.active_connections >= (900 * instance_divisor)
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_ACTIVE_CONNECTIONS;
    }
    if (
        snapshot->memory_live
        && snapshot->metrics.memory_utilization_percent >= 90.0
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_MEMORY;
    }

    return mask;
}

static uint32_t king_autoscaling_build_scale_down_ready_mask(
    const king_autoscaling_signal_snapshot_t *snapshot,
    uint64_t instance_divisor)
{
    uint32_t mask = 0u;

    if (snapshot == NULL) {
        return 0u;
    }

    if (
        snapshot->cpu_live
        && snapshot->metrics.cpu_utilization_percent
            <= (double) king_autoscaling_runtime.config.scale_down_cpu_threshold_percent
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_CPU;
    }
    if (snapshot->queue_depth_live && snapshot->metrics.queue_depth <= 1) {
        mask |= KING_AUTOSCALING_SIGNAL_QUEUE_DEPTH;
    }
    if (snapshot->response_time_live && snapshot->metrics.response_time_ms <= 50) {
        mask |= KING_AUTOSCALING_SIGNAL_RESPONSE_TIME;
    }
    if (
        snapshot->requests_per_second_live
        && snapshot->metrics.requests_per_second <= (90 * instance_divisor)
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_REQUESTS_PER_SECOND;
    }
    if (
        snapshot->active_connections_live
        && snapshot->metrics.active_connections <= (120 * instance_divisor)
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_ACTIVE_CONNECTIONS;
    }
    if (
        snapshot->memory_live
        && snapshot->metrics.memory_utilization_percent <= 70.0
    ) {
        mask |= KING_AUTOSCALING_SIGNAL_MEMORY;
    }

    return mask;
}

static void king_autoscaling_append_signal_list(
    char *buffer,
    size_t buffer_length,
    uint32_t mask)
{
    size_t index;
    zend_bool first = 1;

    if (buffer == NULL || buffer_length == 0) {
        return;
    }

    buffer[0] = '\0';

    for (index = 0; king_autoscaling_signal_descriptors[index].name != NULL; index++) {
        const king_autoscaling_signal_descriptor_t *descriptor = &king_autoscaling_signal_descriptors[index];
        size_t used;

        if ((mask & descriptor->bit) == 0u) {
            continue;
        }

        used = strlen(buffer);
        if (used >= buffer_length - 1) {
            break;
        }

        snprintf(
            buffer + used,
            buffer_length - used,
            "%s%s",
            first ? "" : ", ",
            descriptor->name
        );
        first = 0;
    }

    if (first) {
        snprintf(buffer, buffer_length, "%s", "none");
    }
}

static void king_autoscaling_add_signal_mask_array(
    zval *parent,
    const char *key,
    uint32_t mask)
{
    zval entries;
    size_t index;

    array_init(&entries);
    for (index = 0; king_autoscaling_signal_descriptors[index].name != NULL; index++) {
        const king_autoscaling_signal_descriptor_t *descriptor = &king_autoscaling_signal_descriptors[index];

        if ((mask & descriptor->bit) != 0u) {
            add_next_index_string(&entries, descriptor->name);
        }
    }

    add_assoc_zval(parent, key, &entries);
}

static void king_autoscaling_capture_last_monitor_decision(
    const king_autoscaling_signal_snapshot_t *snapshot,
    const char *decision,
    uint32_t live_mask,
    uint32_t scale_up_mask,
    uint32_t scale_down_ready_mask)
{
    king_autoscaling_runtime.last_monitor_metrics = snapshot->metrics;
    king_autoscaling_runtime.last_monitor_live_signal_mask = live_mask;
    king_autoscaling_runtime.last_monitor_scale_up_signal_mask = scale_up_mask;
    king_autoscaling_runtime.last_monitor_scale_down_ready_mask = scale_down_ready_mask;
    king_autoscaling_runtime.last_monitor_hold_blocker_mask = live_mask & ~scale_down_ready_mask;
    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_monitor_decision,
        sizeof(king_autoscaling_runtime.last_monitor_decision),
        decision
    );
}

static int king_autoscaling_collect_live_signal_snapshot(
    king_autoscaling_signal_snapshot_t *snapshot)
{
    static const char *const cpu_metric_names[] = {
        "autoscaling.cpu_utilization",
        "system.cpu_utilization",
        "cpu_utilization",
        NULL
    };
    static const char *const memory_metric_names[] = {
        "autoscaling.memory_utilization",
        "system.memory_utilization",
        "memory_utilization",
        NULL
    };
    static const char *const connections_metric_names[] = {
        "autoscaling.active_connections",
        "system.active_connections",
        "active_connections",
        NULL
    };
    static const char *const requests_metric_names[] = {
        "autoscaling.requests_per_second",
        "system.requests_per_second",
        "requests_per_second",
        NULL
    };
    static const char *const response_time_metric_names[] = {
        "autoscaling.response_time_ms",
        "system.response_time_ms",
        "response_time_ms",
        NULL
    };
    static const char *const queue_metric_names[] = {
        "autoscaling.queue_depth",
        "system.queue_depth",
        "queue_depth",
        NULL
    };
    time_t now;
    double metric_value = 0.0;
    time_t metric_timestamp = 0;
    zend_long memory_limit = PG(memory_limit);

    if (snapshot == NULL) {
        return FAILURE;
    }

    memset(snapshot, 0, sizeof(*snapshot));
    now = time(NULL);
    snapshot->metrics.timestamp = now;

    if (king_autoscaling_lookup_signal_value(cpu_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.cpu_utilization_percent = metric_value;
        snapshot->cpu_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(memory_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.memory_utilization_percent = metric_value;
        snapshot->memory_live = 1;
        snapshot->telemetry_signal_present = 1;
    } else if (memory_limit > 0) {
        snapshot->metrics.memory_utilization_percent = ((double) zend_memory_usage(0) * 100.0)
            / (double) memory_limit;
        if (snapshot->metrics.memory_utilization_percent < 0.0) {
            snapshot->metrics.memory_utilization_percent = 0.0;
        }
        if (snapshot->metrics.memory_utilization_percent > 100.0) {
            snapshot->metrics.memory_utilization_percent = 100.0;
        }
        snapshot->memory_live = 1;
        snapshot->system_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(connections_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.active_connections = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->active_connections_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(requests_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.requests_per_second = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->requests_per_second_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(response_time_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.response_time_ms = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->response_time_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(queue_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.queue_depth = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->queue_depth_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    return SUCCESS;
}

static int king_autoscaling_evaluate_scaling_decision_snapshot(
    const king_autoscaling_signal_snapshot_t *snapshot,
    char *reason_buffer,
    size_t reason_buffer_length,
    uint32_t *live_mask_out,
    uint32_t *scale_up_mask_out,
    uint32_t *scale_down_ready_mask_out)
{
    uint32_t live_mask;
    uint32_t scale_up_mask;
    uint32_t scale_down_ready_mask;
    uint32_t hold_blocker_mask;
    zend_bool telemetry_present;
    uint64_t instance_divisor;
    char signal_names[192];

    if (snapshot == NULL) {
        return 0;
    }

    telemetry_present = snapshot->telemetry_signal_present;
    instance_divisor = king_current_instances > 0 ? (uint64_t) king_current_instances : 1;
    live_mask = king_autoscaling_build_live_signal_mask(snapshot);
    scale_up_mask = king_autoscaling_build_scale_up_signal_mask(snapshot, instance_divisor);
    scale_down_ready_mask = king_autoscaling_build_scale_down_ready_mask(snapshot, instance_divisor);

    if (live_mask_out != NULL) {
        *live_mask_out = live_mask;
    }
    if (scale_up_mask_out != NULL) {
        *scale_up_mask_out = scale_up_mask;
    }
    if (scale_down_ready_mask_out != NULL) {
        *scale_down_ready_mask_out = scale_down_ready_mask;
    }

    if (scale_up_mask != 0u) {
        king_autoscaling_append_signal_list(signal_names, sizeof(signal_names), scale_up_mask);
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale up decision: %s crossed scale-up pressure.",
            signal_names
        );
        return 1;
    }

    if (!telemetry_present) {
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "No live telemetry-backed autoscaling signals are available for an automatic scaling decision."
        );
        return 0;
    }

    if (live_mask != 0u && scale_down_ready_mask == live_mask) {
        king_autoscaling_append_signal_list(signal_names, sizeof(signal_names), scale_down_ready_mask);
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale down decision: %s are inside the scale-down window.",
            signal_names
        );
        return -1;
    }

    hold_blocker_mask = live_mask & ~scale_down_ready_mask;
    king_autoscaling_append_signal_list(signal_names, sizeof(signal_names), hold_blocker_mask);
    snprintf(
        reason_buffer,
        reason_buffer_length,
        "Live signals remain inside the autoscaling hysteresis window; blockers: %s.",
        signal_names
    );
    return 0;
}

static zend_bool king_autoscaling_drain_ready_node_internal(
    king_autoscaling_managed_node_t *node,
    const char *decision_reason)
{
    size_t active_nodes;
    size_t minimum_managed;

    if (node == NULL || node->lifecycle_state != KING_AUTOSCALING_NODE_READY) {
        return 0;
    }

    active_nodes = king_autoscaling_runtime_count_active_nodes();
    minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
        ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
        : 0;
    if (active_nodes <= minimum_managed) {
        return 0;
    }

    node->lifecycle_state = KING_AUTOSCALING_NODE_DRAINING;
    node->draining_at = time(NULL);
    snprintf(node->provider_status, sizeof(node->provider_status), "%s", "draining");
    king_autoscaling_runtime_normalize_node(node);
    king_autoscaling_runtime.last_scale_down_at = node->draining_at;
    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_decision_reason,
        sizeof(king_autoscaling_runtime.last_decision_reason),
        decision_reason
    );

    return king_autoscaling_finalize_node_update("drain_node");
}

