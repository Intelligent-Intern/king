/*
 * Start every currently eligible component wave once its declared startup
 * dependencies are already running. This keeps the canonical graph from #13
 * honest in the local runtime instead of leaving it as pure metadata.
 */
static void king_system_schedule_startup_components(void)
{
    const king_system_startup_entry_t *entry;
    king_component_info_t *info;
    size_t idx;
    time_t now;

    if (!king_system_initialized) {
        return;
    }

    now = time(NULL);
    for (
        idx = 0;
        idx < sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0]);
        idx++
    ) {
        entry = &king_system_startup_plan[idx];
        info = king_system_get_component_internal(entry->type);
        if (info == NULL) {
            continue;
        }

        if (
            info->status != KING_COMPONENT_STATUS_UNINITIALIZED &&
            info->status != KING_COMPONENT_STATUS_SHUTDOWN
        ) {
            continue;
        }

        if (!king_system_component_dependencies_running(entry->type)) {
            continue;
        }

        info->status = KING_COMPONENT_STATUS_INITIALIZING;
        info->last_health_check = now;
    }
}

/*
 * During a coordinated shutdown, stop only components whose dependents are
 * already inactive. This preserves the canonical reverse-startup order and
 * keeps the runtime in a visible drain state until the final root component
 * can stop safely.
 */
static void king_system_schedule_shutdown_components(void)
{
    const king_system_startup_entry_t *entry;
    king_component_info_t *info;
    size_t idx;
    time_t now;
    zval pending_dependents;

    if (
        !king_system_initialized
        || (!king_system_shutdown_requested && !king_system_recovery_requested)
    ) {
        return;
    }

    now = time(NULL);
    for (
        idx = 0;
        idx < sizeof(king_system_shutdown_plan) / sizeof(king_system_shutdown_plan[0]);
        idx++
    ) {
        entry = &king_system_shutdown_plan[idx];
        info = king_system_get_component_internal(entry->type);
        if (info == NULL) {
            continue;
        }

        if (!king_system_component_started(info->status)) {
            continue;
        }

        if (info->status == KING_COMPONENT_STATUS_SHUTTING_DOWN) {
            continue;
        }

        king_system_build_component_pending_shutdown_dependents(
            &pending_dependents,
            entry->type
        );
        if (zend_hash_num_elements(Z_ARRVAL(pending_dependents)) == 0) {
            info->status = KING_COMPONENT_STATUS_SHUTTING_DOWN;
            info->last_health_check = now;
        }
        zval_ptr_dtor(&pending_dependents);
    }
}

/*
 * Publish the repo-local lifecycle graph explicitly so callers do not have to
 * infer the current state's next legal aggregate transitions themselves.
 */
static void king_system_build_allowed_lifecycle_transitions(
    zval *transitions,
    const char *lifecycle
)
{
    array_init(transitions);

    if (lifecycle == NULL || strcmp(lifecycle, "stopped") == 0) {
        add_next_index_string(transitions, "ready");
        return;
    }

    if (strcmp(lifecycle, "ready") == 0) {
        add_next_index_string(transitions, "draining");
        add_next_index_string(transitions, "failed");
        add_next_index_string(transitions, "stopped");
        return;
    }

    if (strcmp(lifecycle, "draining") == 0) {
        if (!king_system_shutdown_requested) {
            add_next_index_string(transitions, "starting");
        }
        add_next_index_string(transitions, "failed");
        add_next_index_string(transitions, "stopped");
        return;
    }

    if (strcmp(lifecycle, "starting") == 0) {
        add_next_index_string(transitions, "ready");
        add_next_index_string(transitions, "draining");
        add_next_index_string(transitions, "failed");
        add_next_index_string(transitions, "stopped");
        return;
    }

    if (strcmp(lifecycle, "failed") == 0) {
        add_next_index_string(transitions, "draining");
        add_next_index_string(transitions, "stopped");
    }
}

static void king_system_collect_admission_state(
    king_system_admission_state_t *state
)
{
    king_component_info_t *info;
    zend_ulong idx;
    bool draining_request = king_system_shutdown_requested || king_system_recovery_requested;

    memset(state, 0, sizeof(*state));
    state->lifecycle = "stopped";

    if (!king_system_initialized) {
        return;
    }

    state->component_count = (uint32_t) zend_hash_num_elements(&king_system_components);
    ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
        switch (info->status) {
            case KING_COMPONENT_STATUS_RUNNING:
                state->ready_count++;
                break;
            case KING_COMPONENT_STATUS_SHUTTING_DOWN:
                state->draining_count++;
                state->has_draining = true;
                break;
            case KING_COMPONENT_STATUS_INITIALIZING:
            case KING_COMPONENT_STATUS_UNINITIALIZED:
            case KING_COMPONENT_STATUS_SHUTDOWN:
                state->has_starting = true;
                break;
            case KING_COMPONENT_STATUS_ERROR:
                state->has_error = true;
                break;
        }

        if (king_system_component_readiness_blocking(info->status)) {
            state->readiness_blocker_count++;
        }
    } ZEND_HASH_FOREACH_END();

    if (draining_request && (state->has_draining || state->has_error)) {
        state->lifecycle = "draining";
    } else {
    state->lifecycle = king_system_resolve_lifecycle(
        king_system_initialized,
        state->component_count,
        state->ready_count,
        state->has_starting,
        state->has_draining,
        state->has_error
    );
    }
    state->aggregate_ready = king_system_initialized
        && state->component_count > 0
        && state->readiness_blocker_count == 0
        && strcmp(state->lifecycle, "ready") == 0;
}
