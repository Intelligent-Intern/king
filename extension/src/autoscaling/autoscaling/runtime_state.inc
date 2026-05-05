static zend_bool king_autoscaling_monitor_tick(void)
{
    king_autoscaling_signal_snapshot_t snapshot;
    char reason_buffer[256];
    int decision;
    int rollback_result;
    time_t now;
    time_t cooldown_remaining;
    uint32_t live_mask = 0u;
    uint32_t scale_up_mask = 0u;
    uint32_t scale_down_ready_mask = 0u;

    if (!king_autoscaling_runtime.initialized) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Autoscaling runtime is not initialized."
        );
        return 0;
    }

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );
    king_autoscaling_reset_message(reason_buffer, sizeof(reason_buffer));

    now = time(NULL);
    king_autoscaling_runtime.last_monitor_tick_at = now;

    rollback_result = king_autoscaling_provider_rollback_stale_pending_node(now);
    if (rollback_result < 0) {
        return 0;
    }
    if (rollback_result > 0) {
        return 1;
    }

    if (king_autoscaling_collect_live_signal_snapshot(&snapshot) != SUCCESS) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Failed to collect autoscaling telemetry and system signals."
        );
        return 0;
    }

    now = snapshot.metrics.timestamp;
    king_autoscaling_runtime.last_monitor_tick_at = now;
    king_autoscaling_runtime.last_monitor_metrics = snapshot.metrics;
    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_signal_source,
        sizeof(king_autoscaling_runtime.last_signal_source),
        king_autoscaling_resolve_signal_source(&snapshot)
    );
    decision = king_autoscaling_evaluate_scaling_decision_snapshot(
        &snapshot,
        reason_buffer,
        sizeof(reason_buffer),
        &live_mask,
        &scale_up_mask,
        &scale_down_ready_mask
    );
    king_autoscaling_capture_last_monitor_decision(
        &snapshot,
        decision > 0 ? "scale_up" : (decision < 0 ? "scale_down" : "hold"),
        live_mask,
        scale_up_mask,
        scale_down_ready_mask
    );
    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_decision_reason,
        sizeof(king_autoscaling_runtime.last_decision_reason),
        reason_buffer
    );

    cooldown_remaining = king_autoscaling_get_cooldown_remaining(now);
    if (decision != 0 && cooldown_remaining > 0) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Autoscaling controller is in cooldown for " ZEND_LONG_FMT " more second(s).",
            (zend_long) cooldown_remaining
        );
        king_autoscaling_set_runtime_string(
            king_autoscaling_runtime.last_action_kind,
            sizeof(king_autoscaling_runtime.last_action_kind),
            "monitor_tick"
        );
        return 1;
    }

    if (decision > 0) {
        uint32_t scale_step = king_autoscaling_resolve_scale_up_step();

        if (
            king_autoscaling_runtime.provider_kind == KING_AUTOSCALING_PROVIDER_HETZNER
            && king_autoscaling_runtime_count_pending_nodes() > 0
        ) {
            snprintf(
                king_autoscaling_runtime.last_warning,
                sizeof(king_autoscaling_runtime.last_warning),
                "Pending Hetzner nodes must finish register/ready/drain lifecycle before another automatic scale-up."
            );
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_decision_reason,
                sizeof(king_autoscaling_runtime.last_decision_reason),
                "Pending Hetzner nodes must finish register/ready/drain lifecycle before another automatic scale-up."
            );
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_action_kind,
                sizeof(king_autoscaling_runtime.last_action_kind),
                "monitor_tick"
            );
            return 1;
        }

        if (scale_step == 0) {
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_action_kind,
                sizeof(king_autoscaling_runtime.last_action_kind),
                "monitor_tick"
            );
            return 1;
        }

        return king_autoscaling_provider_scale_up(scale_step) == SUCCESS;
    }

    if (decision < 0) {
        if (king_autoscaling_runtime.provider_kind == KING_AUTOSCALING_PROVIDER_HETZNER) {
            if (king_autoscaling_runtime_count_pending_nodes() > 0) {
                return king_autoscaling_provider_scale_down(1) == SUCCESS;
            }

            if (king_autoscaling_drain_ready_node_internal(
                king_autoscaling_runtime_pick_active_node(),
                "Live telemetry and system signals triggered a scale down decision: drained one Hetzner node before provider removal."
            )) {
                return 1;
            }

            snprintf(
                king_autoscaling_runtime.last_warning,
                sizeof(king_autoscaling_runtime.last_warning),
                "Automatic Hetzner scale-down reached the configured floor and could not drain another node."
            );
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_action_kind,
                sizeof(king_autoscaling_runtime.last_action_kind),
                "monitor_tick"
            );
            return 1;
        }

        return king_autoscaling_provider_scale_down(1) == SUCCESS;
    }

    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "monitor_tick"
    );
    return 1;
}

int king_autoscaling_runtime_append_node(
    zend_long server_id,
    const char *name,
    const char *provider_status,
    time_t created_at,
    zend_bool active)
{
    king_autoscaling_managed_node_t *node;

    if (king_autoscaling_runtime_ensure_capacity(king_autoscaling_runtime.managed_node_count + 1) != SUCCESS) {
        return FAILURE;
    }

    node = &king_autoscaling_runtime.managed_nodes[king_autoscaling_runtime.managed_node_count++];
    memset(node, 0, sizeof(*node));

    node->server_id = server_id;
    node->created_at = created_at;
    node->active = active ? 1 : 0;
    node->lifecycle_state = active
        ? KING_AUTOSCALING_NODE_READY
        : KING_AUTOSCALING_NODE_PROVISIONED;
    node->registered_at = active ? created_at : 0;
    node->ready_at = active ? created_at : 0;
    node->draining_at = 0;
    node->deleted_at = 0;

    snprintf(node->name, sizeof(node->name), "%s", name != NULL ? name : "");
    snprintf(
        node->provider_status,
        sizeof(node->provider_status),
        "%s",
        provider_status != NULL ? provider_status : ""
    );

    return SUCCESS;
}

king_autoscaling_managed_node_t *king_autoscaling_runtime_find_node(zend_long server_id)
{
    size_t index;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];
        if (node->server_id == server_id) {
            return node;
        }
    }

    return NULL;
}

king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_active_node(void)
{
    size_t index = king_autoscaling_runtime.managed_node_count;

    while (index > 0) {
        king_autoscaling_managed_node_t *node;

        index--;
        node = &king_autoscaling_runtime.managed_nodes[index];
        if (node->active) {
            return node;
        }
    }

    return NULL;
}

king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_draining_node(void)
{
    size_t index = king_autoscaling_runtime.managed_node_count;

    while (index > 0) {
        king_autoscaling_managed_node_t *node;

        index--;
        node = &king_autoscaling_runtime.managed_nodes[index];
        if (
            node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING
            && node->deleted_at == 0
        ) {
            return node;
        }
    }

    return NULL;
}

void king_autoscaling_runtime_reset(void)
{
    king_autoscaling_clear_managed_nodes();
    king_autoscaling_free_runtime_config(&king_autoscaling_runtime.config);

    king_autoscaling_runtime.provider_kind = KING_AUTOSCALING_PROVIDER_NONE;
    king_autoscaling_runtime.initialized = 0;
    king_autoscaling_runtime.monitoring_active = 0;
    king_autoscaling_runtime.controller_token_configured = 0;
    king_autoscaling_runtime.state_load_incomplete = 0;
    king_autoscaling_runtime.action_count = 0;
    king_autoscaling_runtime.last_scale_up_at = 0;
    king_autoscaling_runtime.last_scale_down_at = 0;
    king_autoscaling_runtime.last_monitor_tick_at = 0;
    memset(
        &king_autoscaling_runtime.last_monitor_metrics,
        0,
        sizeof(king_autoscaling_runtime.last_monitor_metrics)
    );
    king_autoscaling_runtime.last_monitor_live_signal_mask = 0u;
    king_autoscaling_runtime.last_monitor_scale_up_signal_mask = 0u;
    king_autoscaling_runtime.last_monitor_scale_down_ready_mask = 0u;
    king_autoscaling_runtime.last_monitor_hold_blocker_mask = 0u;
    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_monitor_decision,
        sizeof(king_autoscaling_runtime.last_monitor_decision),
        "none"
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_signal_source,
        sizeof(king_autoscaling_runtime.last_signal_source)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_decision_reason,
        sizeof(king_autoscaling_runtime.last_decision_reason)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );
    king_autoscaling_reset_budget_runtime_state();

    king_current_instances = 1;
}

size_t king_autoscaling_runtime_count_active_nodes(void)
{
    size_t index;
    size_t active_nodes = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        if (king_autoscaling_runtime.managed_nodes[index].active) {
            active_nodes++;
        }
    }

    return active_nodes;
}

void king_autoscaling_runtime_sync_instance_count(void)
{
    king_current_instances = (uint32_t) (1 + king_autoscaling_runtime_count_active_nodes());
}

int king_autoscaling_runtime_load_state(void)
{
    FILE *stream;
    char line[512];
    const char *state_path = king_autoscaling_runtime.config.state_path;
    zend_long expected_live_nodes = -1;

    king_autoscaling_runtime.state_load_incomplete = 0;

    if (state_path == NULL || state_path[0] == '\0') {
        return SUCCESS;
    }

    stream = fopen(state_path, "rb");
    if (stream == NULL) {
        if (errno == ENOENT) {
            return SUCCESS;
        }

        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to open autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    king_autoscaling_clear_managed_nodes();

    while (fgets(line, sizeof(line), stream) != NULL) {
        char *saveptr = NULL;
        char *kind;
        char *fields[10] = {0};
        size_t field_count = 0;
        king_autoscaling_managed_node_t *node;
        zend_long server_id;
        zend_bool active;
        time_t created_at;
        time_t registered_at = 0;
        time_t ready_at = 0;
        time_t draining_at = 0;
        time_t deleted_at = 0;
        const char *lifecycle_raw = NULL;
        const char *status_raw = NULL;
        const char *name_raw = NULL;

        if (line[0] == '\n' || line[0] == '\r' || line[0] == '#') {
            continue;
        }

        kind = strtok_r(line, "\t\r\n", &saveptr);
        if (kind == NULL) {
            continue;
        }

        if (strcmp(kind, "live_nodes") == 0) {
            char *value = strtok_r(NULL, "\t\r\n", &saveptr);

            if (value == NULL || value[0] == '\0') {
                king_autoscaling_runtime.state_load_incomplete = 1;
                continue;
            }

            expected_live_nodes = ZEND_STRTOL(value, NULL, 10);
            continue;
        }

        if (strcmp(kind, "node") != 0) {
            continue;
        }

        while (field_count < 10 && (fields[field_count] = strtok_r(NULL, "\t\r\n", &saveptr)) != NULL) {
            field_count++;
        }

        if (field_count != 6 && field_count != 10) {
            king_autoscaling_runtime.state_load_incomplete = 1;
            continue;
        }

        server_id = ZEND_STRTOL(fields[0], NULL, 10);
        active = ZEND_STRTOL(fields[1], NULL, 10) > 0;
        created_at = (time_t) ZEND_STRTOL(fields[2], NULL, 10);

        if (field_count == 6) {
            deleted_at = (time_t) ZEND_STRTOL(fields[3], NULL, 10);
            status_raw = fields[4];
            name_raw = fields[5];
        } else {
            registered_at = (time_t) ZEND_STRTOL(fields[3], NULL, 10);
            ready_at = (time_t) ZEND_STRTOL(fields[4], NULL, 10);
            draining_at = (time_t) ZEND_STRTOL(fields[5], NULL, 10);
            deleted_at = (time_t) ZEND_STRTOL(fields[6], NULL, 10);
            lifecycle_raw = fields[7];
            status_raw = fields[8];
            name_raw = fields[9];
        }

        if (king_autoscaling_runtime_append_node(
            server_id,
            name_raw,
            status_raw,
            created_at,
            active
        ) != SUCCESS) {
            fclose(stream);
            return FAILURE;
        }

        node = &king_autoscaling_runtime.managed_nodes[king_autoscaling_runtime.managed_node_count - 1];
        node->registered_at = registered_at;
        node->ready_at = ready_at;
        node->draining_at = draining_at;
        node->deleted_at = deleted_at;
        node->lifecycle_state = king_autoscaling_node_lifecycle_from_string(
            lifecycle_raw,
            active,
            deleted_at,
            registered_at,
            ready_at,
            draining_at
        );
        king_autoscaling_runtime_normalize_node(node);
    }

    fclose(stream);
    if (
        expected_live_nodes >= 0
        && (zend_long) king_autoscaling_runtime_count_persisted_live_nodes() < expected_live_nodes
    ) {
        king_autoscaling_runtime.state_load_incomplete = 1;
    }
    king_autoscaling_runtime_sync_instance_count();
    return SUCCESS;
}

int king_autoscaling_runtime_persist_state(void)
{
    char tmp_path[512];
    FILE *stream;
    size_t index;
    const char *state_path = king_autoscaling_runtime.config.state_path;

    if (state_path == NULL || state_path[0] == '\0') {
        return SUCCESS;
    }

    snprintf(tmp_path, sizeof(tmp_path), "%s.tmp." ZEND_LONG_FMT, state_path, (zend_long) getpid());
    stream = fopen(tmp_path, "wb");
    if (stream == NULL) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to write autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    fprintf(stream, "version\t%d\n", KING_AUTOSCALING_STATE_VERSION);
    fprintf(
        stream,
        "live_nodes\t%zu\n",
        king_autoscaling_runtime_count_persisted_live_nodes()
    );
    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];
        fprintf(
            stream,
            "node\t" ZEND_LONG_FMT "\t%d\t" ZEND_LONG_FMT "\t" ZEND_LONG_FMT "\t" ZEND_LONG_FMT "\t" ZEND_LONG_FMT "\t" ZEND_LONG_FMT "\t%s\t%s\t%s\n",
            (zend_long) node->server_id,
            node->active ? 1 : 0,
            (zend_long) node->created_at,
            (zend_long) node->registered_at,
            (zend_long) node->ready_at,
            (zend_long) node->draining_at,
            (zend_long) node->deleted_at,
            king_autoscaling_node_lifecycle_to_string(node->lifecycle_state),
            node->provider_status,
            node->name
        );
    }

    if (fclose(stream) != 0) {
        unlink(tmp_path);
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to finalize autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    if (rename(tmp_path, state_path) != 0) {
        unlink(tmp_path);
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to replace autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    return SUCCESS;
}

int king_autoscaling_init_system(const kg_cloud_autoscale_config_t *config)
{
    king_autoscaling_runtime_reset();

    if (config == NULL) {
        return FAILURE;
    }

    king_autoscaling_copy_runtime_config(&king_autoscaling_runtime.config, config);
    king_autoscaling_normalize_runtime_limits();
    king_autoscaling_runtime.provider_kind = king_autoscaling_detect_provider_kind(
        king_autoscaling_runtime.config.provider
    );
    king_autoscaling_runtime.controller_token_configured =
        king_autoscaling_runtime.config.hetzner_api_token != NULL
        && king_autoscaling_runtime.config.hetzner_api_token[0] != '\0';
    king_autoscaling_runtime.initialized = 1;
    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "init"
    );

    if (
        king_autoscaling_runtime.provider_kind == KING_AUTOSCALING_PROVIDER_HETZNER
        && (
            king_autoscaling_runtime.config.state_path == NULL
            || king_autoscaling_runtime.config.state_path[0] == '\0'
        )
    ) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Hetzner controller mode is active without a state_path; restart recovery is disabled."
        );
    }

    king_autoscaling_runtime_load_state();
    if (
        king_autoscaling_runtime.state_load_incomplete
        && king_autoscaling_provider_reconcile_inventory() != SUCCESS
    ) {
        return FAILURE;
    }
    king_autoscaling_runtime_sync_instance_count();
    return SUCCESS;
}

void king_autoscaling_shutdown_system(void)
{
    king_autoscaling_runtime_reset();
}
