/*
 * Local system-integration runtime. Owns the process-local component registry,
 * the small system config snapshot, status-transition bookkeeping and the
 * public king_system_* leaves that expose status, restart and request-routing
 * helpers over that inventory.
 */
#include "php_king.h"
#include "include/integration/system_integration.h"

static king_system_config_t king_system_runtime_config;
static bool king_system_initialized = false;
static HashTable king_system_components;

typedef struct _king_system_component_name_entry {
    king_component_type_t type;
    const char *name;
} king_system_component_name_entry_t;

typedef struct _king_system_admission_state {
    const char *lifecycle;
    uint32_t component_count;
    uint32_t ready_count;
    uint32_t draining_count;
    uint32_t readiness_blocker_count;
    bool has_starting;
    bool has_draining;
    bool has_error;
    zend_bool aggregate_ready;
} king_system_admission_state_t;

static const king_system_component_name_entry_t king_system_component_names[] = {
    {KING_COMPONENT_CONFIG, "config"},
    {KING_COMPONENT_CLIENT, "client"},
    {KING_COMPONENT_SERVER, "server"},
    {KING_COMPONENT_ROUTER_LOADBALANCER, "router"},
    {KING_COMPONENT_ROUTER_LOADBALANCER, "loadbalancer"},
    {KING_COMPONENT_ROUTER_LOADBALANCER, "router_loadbalancer"},
    {KING_COMPONENT_MCP, "mcp"},
    {KING_COMPONENT_OBJECT_STORE, "object_store"},
    {KING_COMPONENT_CDN, "cdn"},
    {KING_COMPONENT_TELEMETRY, "telemetry"},
    {KING_COMPONENT_AUTOSCALING, "autoscaling"},
    {KING_COMPONENT_IIBIN, "iibin"},
    {KING_COMPONENT_PIPELINE_ORCHESTRATOR, "orchestrator"},
    {KING_COMPONENT_PIPELINE_ORCHESTRATOR, "pipeline_orchestrator"},
    {KING_COMPONENT_SEMANTIC_DNS, "semantic_dns"},
};

static const char *king_system_component_statuses[] = {
    "uninitialized",
    "initializing",
    "running",
    "error",
    "shutting_down",
    "shutdown"
};

static king_component_info_t *king_system_get_component_internal(
    king_component_type_t type
);

static king_component_info_t *king_system_get_component_by_name(const char *name);
static void king_system_apply_component_transition(king_component_info_t *info);
static void king_system_apply_all_transitions(void);
static int king_system_set_component_status(
    king_component_type_t type,
    king_component_status_t status
);
static void king_system_apply_default_config(king_system_config_t *config);
static void king_system_apply_config(king_system_config_t *config, zval *config_arr);
static void king_system_collect_admission_state(
    king_system_admission_state_t *state
);
static void king_system_build_allowed_lifecycle_transitions(
    zval *transitions,
    const char *lifecycle
);

static void king_component_info_dtor(zval *zv)
{
    king_component_info_t *info = Z_PTR_P(zv);
    if (info) {
        zval_ptr_dtor(&info->dependencies);
        zval_ptr_dtor(&info->configuration);
        efree(info);
    }
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
    strncpy(config->environment, "development", sizeof(config->environment) - 1);
    ZVAL_UNDEF(&config->global_configuration);
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
        add_next_index_string(transitions, "starting");
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

    state->lifecycle = king_system_resolve_lifecycle(
        king_system_initialized,
        state->component_count,
        state->ready_count,
        state->has_starting,
        state->has_draining,
        state->has_error
    );
    state->aggregate_ready = king_system_initialized
        && state->component_count > 0
        && state->readiness_blocker_count == 0
        && strcmp(state->lifecycle, "ready") == 0;
}

static int king_system_set_component_status(
    king_component_type_t type,
    king_component_status_t status
)
{
    king_component_info_t *info = king_system_get_component_internal(type);
    if (info == NULL) {
        return FAILURE;
    }

    info->status = status;
    info->last_health_check = time(NULL);

    return SUCCESS;
}

static void king_system_apply_component_transition(king_component_info_t *info)
{
    time_t now;
    time_t age;

    if (info == NULL || king_system_runtime_config.component_timeout_seconds == 0) {
        return;
    }

    now = time(NULL);
    age = now - info->last_health_check;

    if (info->status == KING_COMPONENT_STATUS_SHUTTING_DOWN &&
        age >= king_system_runtime_config.component_timeout_seconds) {
        info->status = KING_COMPONENT_STATUS_INITIALIZING;
        info->last_health_check = now;
        return;
    }

    if (info->status == KING_COMPONENT_STATUS_INITIALIZING &&
        age >= king_system_runtime_config.component_timeout_seconds) {
        info->status = KING_COMPONENT_STATUS_RUNNING;
        info->last_health_check = now;
    }
}

static void king_system_apply_all_transitions(void)
{
    king_component_info_t *info;
    zend_ulong idx;

    if (!king_system_initialized) {
        return;
    }

    ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
        king_system_apply_component_transition(info);
    } ZEND_HASH_FOREACH_END();
}

int king_system_update_component_status(
    king_component_type_t type,
    king_component_status_t status
)
{
    return king_system_set_component_status(type, status);
}

king_component_info_t *king_system_get_component(king_component_type_t type)
{
    return king_system_get_component_internal(type);
}

int king_system_check_component_health(king_component_type_t type)
{
    king_component_info_t *info;

    info = king_system_get_component_internal(type);
    if (info == NULL) {
        return FAILURE;
    }

    king_system_apply_component_transition(info);

    return info->status == KING_COMPONENT_STATUS_RUNNING ? SUCCESS : FAILURE;
}

int king_system_check_all_components_health(void)
{
    king_component_info_t *info;
    zend_ulong idx;
    bool all_running = true;

    king_system_apply_all_transitions();

    ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
        if (info == NULL || info->status != KING_COMPONENT_STATUS_RUNNING) {
            all_running = false;
            break;
        }
    } ZEND_HASH_FOREACH_END();

    return all_running ? SUCCESS : FAILURE;
}

int king_system_require_admission(
    const char *function_name,
    const char *admission_name
)
{
    king_system_admission_state_t state;
    zend_string *message;

    king_system_apply_all_transitions();
    king_system_collect_admission_state(&state);

    if (!king_system_initialized || state.component_count == 0) {
        king_set_error("");
        return SUCCESS;
    }

    if (state.aggregate_ready) {
        king_set_error("");
        return SUCCESS;
    }

    message = strpprintf(
        0,
        "%s() cannot admit %s while the coordinated runtime lifecycle is '%s' with %u readiness blocker(s).",
        function_name,
        admission_name,
        state.lifecycle,
        state.readiness_blocker_count
    );

    king_set_error(ZSTR_VAL(message));
    zend_string_release(message);
    return FAILURE;
}

int king_system_handle_component_error(
    king_component_type_t type,
    const char *error_message
)
{
    king_component_info_t *info;

    (void) error_message;

    info = king_system_get_component_internal(type);
    if (info == NULL) {
        return FAILURE;
    }

    info->status = KING_COMPONENT_STATUS_ERROR;
    info->errors_encountered++;
    info->last_health_check = time(NULL);

    return SUCCESS;
}

int king_system_init_all_components(king_system_config_t *config)
{
    if (!king_system_initialized) {
        zend_hash_init(&king_system_components, 16, NULL, king_component_info_dtor, 0);
        king_system_initialized = true;
    }

    if (config) {
        memcpy(&king_system_runtime_config, config, sizeof(king_system_config_t));
    }

    /* Register core components */
    king_system_register_component(KING_COMPONENT_CONFIG, "config", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_CLIENT, "client", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_SERVER, "server", "0.2.1-alpha");
    king_system_register_component(
        KING_COMPONENT_ROUTER_LOADBALANCER,
        "router_loadbalancer",
        "0.2.1-alpha"
    );
    king_system_register_component(KING_COMPONENT_MCP, "mcp", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_TELEMETRY, "telemetry", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_AUTOSCALING, "autoscaling", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_PIPELINE_ORCHESTRATOR, "orchestrator", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_OBJECT_STORE, "object_store", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_CDN, "cdn", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_IIBIN, "iibin", "0.2.1-alpha");
    king_system_register_component(KING_COMPONENT_SEMANTIC_DNS, "semantic_dns", "0.2.1-alpha");

    return SUCCESS;
}

void king_system_shutdown_all_components(void)
{
    if (king_system_initialized) {
        zend_hash_destroy(&king_system_components);
        king_system_initialized = false;
    }
}

int king_system_register_component(king_component_type_t type, const char *name, const char *version)
{
    king_component_info_t *info = emalloc(sizeof(king_component_info_t));
    time_t now = time(NULL);

    memset(info, 0, sizeof(king_component_info_t));
    
    info->type = type;
    strncpy(info->name, name, sizeof(info->name) - 1);
    strncpy(info->version, version, sizeof(info->version) - 1);
    info->status = KING_COMPONENT_STATUS_RUNNING;
    info->initialized_at = now;
    info->last_health_check = now;
    
    array_init(&info->dependencies);
    array_init(&info->configuration);

    if (zend_hash_index_exists(&king_system_components, (zend_ulong)type)) {
        zend_hash_index_del(&king_system_components, (zend_ulong)type);
    }
    
    zval val;
    ZVAL_PTR(&val, info);
    zend_hash_index_update(&king_system_components, (zend_ulong)type, &val);
    
    return SUCCESS;
}

/* --- PHP Entry Points --- */

PHP_FUNCTION(king_system_init)
{
    zval *config_arr;
    if (zend_parse_parameters(1, "a", &config_arr) == FAILURE) RETURN_FALSE;

    king_system_config_t config;
    king_system_apply_default_config(&config);
    king_system_apply_config(&config, config_arr);

    if (king_system_init_all_components(&config) == SUCCESS) {
        RETURN_TRUE;
    }

    RETURN_FALSE;
}

PHP_FUNCTION(king_system_process_request)
{
    zval *request_data;
    if (zend_parse_parameters(1, "a", &request_data) == FAILURE) RETURN_FALSE;

    if (!king_system_initialized) {
        king_set_error(
            "king_system_process_request() cannot process requests because the coordinated runtime is not initialized."
        );
        RETURN_FALSE;
    }

    if (
        king_system_require_admission(
            "king_system_process_request",
            "process_requests"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    (void) request_data;
    {
        king_component_info_t *info;
        zend_ulong idx;
        ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
            info->requests_handled++;
            info->last_health_check = time(NULL);
        } ZEND_HASH_FOREACH_END();
    }

    king_system_apply_all_transitions();
    RETURN_TRUE;
}

PHP_FUNCTION(king_system_restart_component)
{
    char *name;
    size_t name_len;
    if (zend_parse_parameters(1, "s", &name, &name_len) == FAILURE) RETURN_FALSE;

    king_component_info_t *info = king_system_get_component_by_name(name);
    if (!king_system_initialized || info == NULL) {
        RETURN_FALSE;
    }

    (void) name_len;

    info->status = KING_COMPONENT_STATUS_SHUTTING_DOWN;
    info->last_health_check = time(NULL);

    RETURN_TRUE;
}

PHP_FUNCTION(king_system_shutdown)
{
    king_system_shutdown_all_components();
    RETURN_TRUE;
}

PHP_FUNCTION(king_system_get_status)
{
    ZEND_PARSE_PARAMETERS_NONE();
    zval admission;
    zval allowed_lifecycle_transitions;
    zval blocker_entry;
    zval component_entry;
    zval components;
    zval drain_intent;
    zval drain_targets;
    zval readiness_blockers;
    time_t now;
    time_t drain_requested_at = 0;
    king_system_admission_state_t admission_state;
    zend_ulong idx;
    uint32_t drain_target_count = 0;
    king_component_info_t *info;

    now = time(NULL);
    king_system_apply_all_transitions();
    king_system_collect_admission_state(&admission_state);

    array_init(&components);
    array_init(&drain_targets);
    array_init(&readiness_blockers);
    if (king_system_initialized) {
        ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
            time_t last_health_check = info->last_health_check;
            zend_bool readiness_blocking = king_system_component_readiness_blocking(
                info->status
            );
            const char *readiness_reason = king_system_component_readiness_reason(
                info->status
            );
            array_init(&component_entry);
            add_assoc_string(&component_entry, "status", king_component_status_to_string(info->status));
            add_assoc_bool(
                &component_entry,
                "ready",
                info->status == KING_COMPONENT_STATUS_RUNNING ? 1 : 0
            );
            add_assoc_string(&component_entry, "readiness_reason", (char *) readiness_reason);
            add_assoc_bool(
                &component_entry,
                "readiness_blocking",
                readiness_blocking
            );
            add_assoc_long(&component_entry, "requests_handled", (zend_long) info->requests_handled);
            add_assoc_long(&component_entry, "errors_encountered", (zend_long) info->errors_encountered);
            add_assoc_long(&component_entry, "last_health_check", (zend_long) last_health_check);
            add_assoc_long(&component_entry, "up_for_seconds", (zend_long) (now - last_health_check));

            if (info->status == KING_COMPONENT_STATUS_SHUTTING_DOWN) {
                add_next_index_string(&drain_targets, info->name);
                drain_target_count++;
                if (drain_requested_at == 0 || last_health_check < drain_requested_at) {
                    drain_requested_at = last_health_check;
                }
            }

            if (readiness_blocking) {
                array_init(&blocker_entry);
                add_assoc_string(
                    &blocker_entry,
                    "status",
                    king_component_status_to_string(info->status)
                );
                add_assoc_string(
                    &blocker_entry,
                    "readiness_reason",
                    (char *) readiness_reason
                );
                add_assoc_zval(&readiness_blockers, info->name, &blocker_entry);
            }

            add_assoc_zval(&components, info->name, &component_entry);
        } ZEND_HASH_FOREACH_END();
    }

    array_init(return_value);
    add_assoc_bool(return_value, "initialized", king_system_initialized);
    add_assoc_string(return_value, "lifecycle", (char *) admission_state.lifecycle);
    add_assoc_long(return_value, "component_count", admission_state.component_count);
    add_assoc_zval(return_value, "components", &components);
    add_assoc_long(return_value, "components_ready", (zend_long) admission_state.ready_count);
    add_assoc_long(
        return_value,
        "components_draining",
        (zend_long) admission_state.draining_count
    );
    add_assoc_long(
        return_value,
        "readiness_blocker_count",
        (zend_long) admission_state.readiness_blocker_count
    );
    add_assoc_zval(return_value, "readiness_blockers", &readiness_blockers);
    array_init(&drain_intent);
    add_assoc_bool(&drain_intent, "requested", admission_state.has_draining ? 1 : 0);
    add_assoc_bool(
        &drain_intent,
        "active",
        strcmp(admission_state.lifecycle, "draining") == 0 ? 1 : 0
    );
    add_assoc_string(
        &drain_intent,
        "reason",
        (char *) (admission_state.has_draining ? "component_restart" : "none")
    );
    add_assoc_long(&drain_intent, "requested_at", (zend_long) drain_requested_at);
    if (admission_state.has_draining) {
        add_assoc_string(&drain_intent, "target_lifecycle", "starting");
    } else {
        add_assoc_null(&drain_intent, "target_lifecycle");
    }
    add_assoc_long(
        &drain_intent,
        "target_component_count",
        (zend_long) drain_target_count
    );
    add_assoc_zval(&drain_intent, "target_components", &drain_targets);
    add_assoc_zval(return_value, "drain_intent", &drain_intent);
    king_system_build_allowed_lifecycle_transitions(
        &allowed_lifecycle_transitions,
        admission_state.lifecycle
    );
    add_assoc_zval(
        return_value,
        "allowed_lifecycle_transitions",
        &allowed_lifecycle_transitions
    );
    array_init(&admission);
    add_assoc_bool(&admission, "process_requests", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "http_listener_accepts", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "websocket_upgrades", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "websocket_peer_accepts", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "orchestrator_submissions", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "file_worker_claims", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "file_worker_resumes", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "remote_peer_dispatches", admission_state.aggregate_ready);
    add_assoc_bool(&admission, "remote_peer_resumes", admission_state.aggregate_ready);
    add_assoc_zval(return_value, "admission", &admission);
    add_assoc_long(
        return_value,
        "health_check_interval_seconds",
        (zend_long) king_system_runtime_config.health_check_interval_seconds
    );
}
