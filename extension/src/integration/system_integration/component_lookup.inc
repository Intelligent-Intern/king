static king_component_info_t *king_system_get_component_internal(king_component_type_t type)
{
    zval *entry;

    if (!king_system_initialized) {
        return NULL;
    }

    entry = zend_hash_index_find(&king_system_components, (zend_ulong)type);
    if (entry == NULL) {
        return NULL;
    }

    return Z_PTR_P(entry);
}

static king_component_info_t *king_system_get_component_by_name(const char *name)
{
    size_t idx;

    for (idx = 0; idx < sizeof(king_system_component_names) / sizeof(king_system_component_names[0]); idx++) {
        const char *candidate = king_system_component_names[idx].name;
        if (strcmp(candidate, name) == 0) {
            return king_system_get_component_internal(king_system_component_names[idx].type);
        }
    }

    return NULL;
}

const char *king_component_status_to_string(king_component_status_t status)
{
    if ((int) status < 0 || (int) status >= 6) {
        return "unknown";
    }

    return king_system_component_statuses[(int) status];
}

/*
 * Export one aggregate lifecycle so callers do not have to infer process
 * state from the per-component map on every status read.
 */
static const char *king_system_resolve_lifecycle(
    bool initialized,
    uint32_t component_count,
    uint32_t ready_count,
    bool has_starting,
    bool has_draining,
    bool has_error
)
{
    if (!initialized || component_count == 0) {
        return "stopped";
    }

    if (has_error) {
        return "failed";
    }

    if (has_draining) {
        return "draining";
    }

    if (has_starting || ready_count < component_count) {
        return "starting";
    }

    return "ready";
}

static const char *king_system_component_readiness_reason(
    king_component_status_t status
)
{
    switch (status) {
        case KING_COMPONENT_STATUS_RUNNING:
            return "ready";
        case KING_COMPONENT_STATUS_INITIALIZING:
            return "component_initializing";
        case KING_COMPONENT_STATUS_SHUTTING_DOWN:
            return "component_shutting_down";
        case KING_COMPONENT_STATUS_ERROR:
            return "component_failed";
        case KING_COMPONENT_STATUS_SHUTDOWN:
            return "component_shutdown";
        case KING_COMPONENT_STATUS_UNINITIALIZED:
        default:
            return "component_uninitialized";
    }
}

static zend_bool king_system_component_readiness_blocking(
    king_component_status_t status
)
{
    return status == KING_COMPONENT_STATUS_RUNNING ? 0 : 1;
}

static const king_system_startup_entry_t *king_system_get_startup_entry(
    king_component_type_t type
)
{
    size_t idx;

    for (
        idx = 0;
        idx < sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0]);
        idx++
    ) {
        if (king_system_startup_plan[idx].type == type) {
            return &king_system_startup_plan[idx];
        }
    }

    return NULL;
}

static const king_system_startup_entry_t *king_system_get_shutdown_entry(
    king_component_type_t type
)
{
    size_t idx;

    for (
        idx = 0;
        idx < sizeof(king_system_shutdown_plan) / sizeof(king_system_shutdown_plan[0]);
        idx++
    ) {
        if (king_system_shutdown_plan[idx].type == type) {
            return &king_system_shutdown_plan[idx];
        }
    }

    return NULL;
}

static zend_bool king_system_component_started(king_component_status_t status)
{
    switch (status) {
        case KING_COMPONENT_STATUS_INITIALIZING:
        case KING_COMPONENT_STATUS_RUNNING:
        case KING_COMPONENT_STATUS_ERROR:
        case KING_COMPONENT_STATUS_SHUTTING_DOWN:
            return 1;
        case KING_COMPONENT_STATUS_UNINITIALIZED:
        case KING_COMPONENT_STATUS_SHUTDOWN:
        default:
            return 0;
    }
}

static zend_bool king_system_component_startup_dependency_ready(
    const char *dependency_name
)
{
    king_component_info_t *dependency_info;

    dependency_info = king_system_get_component_by_name(dependency_name);
    if (dependency_info == NULL) {
        return 0;
    }

    return dependency_info->status == KING_COMPONENT_STATUS_RUNNING ? 1 : 0;
}

static zend_bool king_system_component_dependencies_running(
    king_component_type_t type
)
{
    zval dependencies;
    zval *dependency_name;
    zend_bool ready = 1;

    king_system_build_component_startup_dependencies(&dependencies, type);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependencies), dependency_name) {
        if (Z_TYPE_P(dependency_name) != IS_STRING) {
            continue;
        }

        if (
            !king_system_component_startup_dependency_ready(
                Z_STRVAL_P(dependency_name)
            )
        ) {
            ready = 0;
            break;
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&dependencies);
    return ready;
}
