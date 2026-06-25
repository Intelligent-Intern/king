/*
 * Local system-integration runtime. Owns the process-local component registry,
 * the small system config snapshot, status-transition bookkeeping and the
 * public king_system_* leaves that expose status, restart and request-routing
 * helpers over that inventory.
 */
#include "php_king.h"
#include "integration/system_integration.h"
#include <errno.h>
#include <inttypes.h>
#include <limits.h>
#include <sys/stat.h>
#include <unistd.h>

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

typedef enum _king_system_recovery_mode {
    KING_SYSTEM_RECOVERY_MODE_NONE,
    KING_SYSTEM_RECOVERY_MODE_NODE,
    KING_SYSTEM_RECOVERY_MODE_COMPONENT
} king_system_recovery_mode_t;

static king_system_config_t king_system_runtime_config;
static bool king_system_initialized = false;
static bool king_system_shutdown_requested = false;
static bool king_system_recovery_requested = false;
static bool king_system_coordinator_state_present = false;
static bool king_system_coordinator_state_recovered = false;
static HashTable king_system_components;
static uint64_t king_system_coordinator_state_version = 0;
static uint64_t king_system_coordinator_generation = 0;
static time_t king_system_coordinator_created_at = 0;
static time_t king_system_coordinator_last_loaded_at = 0;
static char king_system_coordinator_state_path[PATH_MAX];
static char king_system_coordinator_state_error[256];
static char king_system_coordinator_state_status[32] = "inactive";
static char king_system_recovery_source_node_id[64];
static king_system_recovery_mode_t king_system_recovery_mode =
    KING_SYSTEM_RECOVERY_MODE_NONE;
static char king_system_recovery_plan_id[64];
static time_t king_system_recovery_plan_requested_at = 0;
static uint32_t king_system_recovery_plan_window_seconds = 0;

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

typedef struct _king_system_startup_entry {
    king_component_type_t type;
    const char *name;
    uint32_t order;
} king_system_startup_entry_t;

typedef enum _king_system_drain_reason {
    KING_SYSTEM_DRAIN_REASON_NONE,
    KING_SYSTEM_DRAIN_REASON_COMPONENT_RESTART,
    KING_SYSTEM_DRAIN_REASON_COMPONENT_RECOVERY,
    KING_SYSTEM_DRAIN_REASON_SYSTEM_SHUTDOWN
} king_system_drain_reason_t;

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

static const king_system_startup_entry_t king_system_startup_plan[] = {
    {KING_COMPONENT_CONFIG, "config", 1},
    {KING_COMPONENT_CLIENT, "client", 2},
    {KING_COMPONENT_SERVER, "server", 3},
    {KING_COMPONENT_TELEMETRY, "telemetry", 4},
    {KING_COMPONENT_OBJECT_STORE, "object_store", 5},
    {KING_COMPONENT_IIBIN, "iibin", 6},
    {KING_COMPONENT_MCP, "mcp", 7},
    {KING_COMPONENT_SEMANTIC_DNS, "semantic_dns", 8},
    {KING_COMPONENT_ROUTER_LOADBALANCER, "router_loadbalancer", 9},
    {KING_COMPONENT_PIPELINE_ORCHESTRATOR, "orchestrator", 10},
    {KING_COMPONENT_CDN, "cdn", 11},
    {KING_COMPONENT_AUTOSCALING, "autoscaling", 12},
};

static const king_system_startup_entry_t king_system_shutdown_plan[] = {
    {KING_COMPONENT_AUTOSCALING, "autoscaling", 1},
    {KING_COMPONENT_CDN, "cdn", 2},
    {KING_COMPONENT_PIPELINE_ORCHESTRATOR, "orchestrator", 3},
    {KING_COMPONENT_ROUTER_LOADBALANCER, "router_loadbalancer", 4},
    {KING_COMPONENT_SEMANTIC_DNS, "semantic_dns", 5},
    {KING_COMPONENT_MCP, "mcp", 6},
    {KING_COMPONENT_IIBIN, "iibin", 7},
    {KING_COMPONENT_OBJECT_STORE, "object_store", 8},
    {KING_COMPONENT_TELEMETRY, "telemetry", 9},
    {KING_COMPONENT_SERVER, "server", 10},
    {KING_COMPONENT_CLIENT, "client", 11},
    {KING_COMPONENT_CONFIG, "config", 12},
};

static const char *king_system_component_statuses[] = {
    "uninitialized",
    "initializing",
    "running",
    "error",
    "shutting_down",
    "shutdown"
};

static king_system_drain_reason_t king_system_drain_reason =
    KING_SYSTEM_DRAIN_REASON_NONE;

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
static const king_system_startup_entry_t *king_system_get_startup_entry(
    king_component_type_t type
);
static const king_system_startup_entry_t *king_system_get_shutdown_entry(
    king_component_type_t type
);
static zend_bool king_system_component_started(king_component_status_t status);
static zend_bool king_system_component_startup_dependency_ready(
    const char *dependency_name
);
static zend_bool king_system_component_dependencies_running(
    king_component_type_t type
);
static void king_system_build_component_startup_dependencies(
    zval *dependencies,
    king_component_type_t type
);
static void king_system_build_component_pending_startup_dependencies(
    zval *pending_dependencies,
    king_component_type_t type
);
static void king_system_build_component_shutdown_dependents(
    zval *dependents,
    king_component_type_t type
);
static void king_system_build_component_pending_shutdown_dependents(
    zval *pending_dependents,
    king_component_type_t type
);
static void king_system_build_startup_visibility(zval *startup);
static void king_system_build_shutdown_visibility(zval *shutdown);
static void king_system_schedule_startup_components(void);
static void king_system_schedule_shutdown_components(void);
static void king_system_reset_drain_state(void);
static void king_system_reset_coordinator_state_runtime(void);
static const char *king_system_drain_reason_to_string(
    king_system_drain_reason_t reason
);
static uint32_t king_system_recovery_window_seconds(
    king_system_recovery_mode_t mode
);
static const char *king_system_recovery_mode_to_string(
    king_system_recovery_mode_t mode
);
static void king_system_start_recovery_plan(
    king_system_recovery_mode_t mode,
    const char *source_node_id
);
static const char *king_system_recovery_reason_to_string(void);
static uint32_t king_system_count_started_components(void);
static void king_system_apply_default_node_identity(king_system_config_t *config);
static void king_system_build_coordinator_dir_path(char *dest, size_t dest_len);
static void king_system_build_coordinator_state_path(char *dest, size_t dest_len);
static int king_system_ensure_directory_recursive(const char *path);
static int king_system_write_coordinator_state(
    const char *state_path,
    uint64_t version,
    uint64_t generation,
    time_t created_at,
    const char *cluster_id,
    const char *active_node_id,
    zend_bool clean_shutdown
);
static int king_system_load_coordinator_state(
    const char *state_path,
    uint64_t *version_out,
    uint64_t *generation_out,
    time_t *created_at_out,
    char *cluster_id_out,
    size_t cluster_id_out_len,
    char *active_node_id_out,
    size_t active_node_id_out_len,
    zend_bool *clean_shutdown_out
);
static int king_system_initialize_coordinator_state(void);
static void king_system_mark_coordinator_clean_shutdown(void);

static void king_component_info_dtor(zval *zv)
{
    king_component_info_t *info = Z_PTR_P(zv);
    if (info) {
        zval_ptr_dtor(&info->dependencies);
        zval_ptr_dtor(&info->configuration);
        efree(info);
    }
}

#include "system_integration/config_and_recovery.inc"

#include "system_integration/coordinator_state.inc"

#include "system_integration/component_lookup.inc"

#include "system_integration/component_visibility.inc"

#include "system_integration/lifecycle_runtime.inc"

static uint32_t king_system_count_started_components(void)
{
    king_component_info_t *info;
    zend_ulong idx;
    uint32_t started_count = 0;

    if (!king_system_initialized) {
        return 0;
    }

    ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
        if (info != NULL && king_system_component_started(info->status)) {
            started_count++;
        }
    } ZEND_HASH_FOREACH_END();

    return started_count;
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
        info->status = (king_system_shutdown_requested || king_system_recovery_requested)
            ? KING_COMPONENT_STATUS_SHUTDOWN
            : KING_COMPONENT_STATUS_INITIALIZING;
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

    if (king_system_shutdown_requested) {
        king_system_schedule_shutdown_components();
        if (king_system_count_started_components() == 0) {
            king_system_mark_coordinator_clean_shutdown();
            king_system_shutdown_all_components();
        }
        return;
    }

    if (king_system_recovery_requested) {
        king_system_schedule_shutdown_components();
        if (king_system_count_started_components() > 0) {
            return;
        }

        king_system_recovery_requested = false;
        king_system_drain_reason = KING_SYSTEM_DRAIN_REASON_NONE;
    }

    king_system_schedule_startup_components();
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
    if (!king_system_initialized) {
        return FAILURE;
    }

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

    king_system_reset_drain_state();

    if (config) {
        memcpy(&king_system_runtime_config, config, sizeof(king_system_config_t));
    }

    king_system_apply_default_node_identity(&king_system_runtime_config);
    if (king_system_initialize_coordinator_state() != SUCCESS) {
        king_system_shutdown_all_components();
        return FAILURE;
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
    king_system_schedule_startup_components();

    return SUCCESS;
}

void king_system_shutdown_all_components(void)
{
    if (king_system_initialized) {
        zend_hash_destroy(&king_system_components);
        king_system_initialized = false;
    }

    king_system_reset_drain_state();
    king_system_reset_coordinator_state_runtime();
}

int king_system_register_component(king_component_type_t type, const char *name, const char *version)
{
    king_component_info_t *info = emalloc(sizeof(king_component_info_t));
    time_t now = time(NULL);

    memset(info, 0, sizeof(king_component_info_t));
    
    info->type = type;
    strncpy(info->name, name, sizeof(info->name) - 1);
    strncpy(info->version, version, sizeof(info->version) - 1);
    info->status = KING_COMPONENT_STATUS_UNINITIALIZED;
    info->initialized_at = now;
    info->last_health_check = now;
    
    king_system_build_component_startup_dependencies(&info->dependencies, type);
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
    if (!king_system_shutdown_requested) {
        king_system_drain_reason = KING_SYSTEM_DRAIN_REASON_COMPONENT_RESTART;
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_system_fail_component)
{
    char *name;
    size_t name_len;
    king_component_info_t *info;

    if (zend_parse_parameters(1, "s", &name, &name_len) == FAILURE) {
        RETURN_FALSE;
    }

    (void) name_len;

    info = king_system_get_component_by_name(name);
    if (!king_system_initialized || info == NULL) {
        RETURN_FALSE;
    }

    RETURN_BOOL(
        king_system_handle_component_error(
            info->type,
            "manual component failure"
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_system_recover)
{
    king_system_admission_state_t state;

    if (!king_system_initialized || king_system_shutdown_requested) {
        RETURN_FALSE;
    }

    if (king_system_recovery_requested) {
        RETURN_TRUE;
    }

    king_system_collect_admission_state(&state);
    if (strcmp(state.lifecycle, "failed") != 0) {
        RETURN_FALSE;
    }

    king_system_recovery_requested = true;
    king_system_start_recovery_plan(
        KING_SYSTEM_RECOVERY_MODE_COMPONENT,
        king_system_runtime_config.node_id
    );
    king_system_drain_reason = KING_SYSTEM_DRAIN_REASON_COMPONENT_RECOVERY;
    king_system_apply_all_transitions();
    RETURN_TRUE;
}

PHP_FUNCTION(king_system_shutdown)
{
    if (!king_system_initialized) {
        RETURN_TRUE;
    }

    king_system_shutdown_requested = true;
    king_system_drain_reason = KING_SYSTEM_DRAIN_REASON_SYSTEM_SHUTDOWN;
    king_system_apply_all_transitions();
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
    zval recovery;
    zval shutdown;
    zval startup;
    time_t now;
    time_t drain_requested_at = 0;
    bool recovery_active = false;
    king_system_admission_state_t admission_state;
    zend_ulong idx;
    uint32_t drain_target_count = 0;
    king_component_info_t *info;

    now = time(NULL);
    king_system_apply_all_transitions();
    king_system_collect_admission_state(&admission_state);
    recovery_active = (
        king_system_recovery_mode != KING_SYSTEM_RECOVERY_MODE_NONE
        && king_system_initialized
        && strcmp(admission_state.lifecycle, "ready") != 0
    ) ? 1 : 0;

    array_init(&components);
    array_init(&drain_targets);
    array_init(&readiness_blockers);
    if (king_system_initialized) {
        ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
            uint32_t shutdown_pending_dependent_count = 0;
            time_t last_health_check = info->last_health_check;
            uint32_t startup_pending_dependency_count = 0;
            zend_bool readiness_blocking = king_system_component_readiness_blocking(
                info->status
            );
            const char *readiness_reason = king_system_component_readiness_reason(
                info->status
            );
            const king_system_startup_entry_t *shutdown_entry =
                king_system_get_shutdown_entry(info->type);
            const king_system_startup_entry_t *startup_entry =
                king_system_get_startup_entry(info->type);
            zval shutdown_dependents;
            zval shutdown_pending_dependents;
            zval startup_dependencies;
            zval startup_pending_dependencies;

            king_system_build_component_shutdown_dependents(
                &shutdown_dependents,
                info->type
            );
            king_system_build_component_pending_shutdown_dependents(
                &shutdown_pending_dependents,
                info->type
            );
            shutdown_pending_dependent_count = (uint32_t) zend_hash_num_elements(
                Z_ARRVAL(shutdown_pending_dependents)
            );
            king_system_build_component_startup_dependencies(
                &startup_dependencies,
                info->type
            );
            king_system_build_component_pending_startup_dependencies(
                &startup_pending_dependencies,
                info->type
            );
            startup_pending_dependency_count = (uint32_t) zend_hash_num_elements(
                Z_ARRVAL(startup_pending_dependencies)
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
            add_assoc_long(
                &component_entry,
                "shutdown_order",
                shutdown_entry != NULL ? (zend_long) shutdown_entry->order : 0
            );
            add_assoc_zval(
                &component_entry,
                "shutdown_dependents",
                &shutdown_dependents
            );
            add_assoc_zval(
                &component_entry,
                "shutdown_pending_dependents",
                &shutdown_pending_dependents
            );
            add_assoc_bool(
                &component_entry,
                "shutdown_ready_to_stop",
                king_system_component_started(info->status) &&
                    shutdown_pending_dependent_count == 0
            );
            add_assoc_long(
                &component_entry,
                "startup_order",
                startup_entry != NULL ? (zend_long) startup_entry->order : 0
            );
            add_assoc_zval(
                &component_entry,
                "startup_dependencies",
                &startup_dependencies
            );
            add_assoc_zval(
                &component_entry,
                "startup_pending_dependencies",
                &startup_pending_dependencies
            );
            add_assoc_bool(
                &component_entry,
                "startup_ready_to_start",
                !king_system_component_started(info->status) &&
                    startup_pending_dependency_count == 0
            );

            if (
                ((king_system_shutdown_requested || king_system_recovery_requested) &&
                    king_system_component_started(info->status)) ||
                info->status == KING_COMPONENT_STATUS_SHUTTING_DOWN
            ) {
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
    add_assoc_bool(
        &drain_intent,
        "requested",
        (king_system_shutdown_requested
            || king_system_recovery_requested
            || admission_state.has_draining) ? 1 : 0
    );
    add_assoc_bool(
        &drain_intent,
        "active",
        strcmp(admission_state.lifecycle, "draining") == 0 ? 1 : 0
    );
    add_assoc_string(
        &drain_intent,
        "reason",
        (char *) (
            king_system_shutdown_requested
                || king_system_recovery_requested
                || admission_state.has_draining
                ? king_system_drain_reason_to_string(king_system_drain_reason)
                : "none"
        )
    );
    add_assoc_long(&drain_intent, "requested_at", (zend_long) drain_requested_at);
    if (king_system_shutdown_requested) {
        add_assoc_string(&drain_intent, "target_lifecycle", "stopped");
    } else if (king_system_recovery_requested) {
        add_assoc_string(&drain_intent, "target_lifecycle", "ready");
    } else if (admission_state.has_draining) {
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
    array_init(&recovery);
    add_assoc_bool(
        &recovery,
        "active",
        recovery_active
    );
    add_assoc_bool(
        &recovery,
        "recovered",
        king_system_coordinator_state_recovered ? 1 : 0
    );
    add_assoc_string(
        &recovery,
        "reason",
        (char *) king_system_recovery_reason_to_string()
    );
    add_assoc_string(
        &recovery,
        "mode",
        king_system_recovery_mode_to_string(king_system_recovery_mode)
    );
    if (king_system_recovery_plan_id[0] != '\0') {
        add_assoc_string(&recovery, "plan_id", king_system_recovery_plan_id);
    } else {
        add_assoc_null(&recovery, "plan_id");
    }
    add_assoc_long(
        &recovery,
        "plan_requested_at",
        (zend_long) king_system_recovery_plan_requested_at
    );
    add_assoc_long(
        &recovery,
        "plan_window_seconds",
        (zend_long) king_system_recovery_plan_window_seconds
    );
    if (king_system_recovery_source_node_id[0] != '\0') {
        add_assoc_string(
            &recovery,
            "source_node_id",
            king_system_recovery_source_node_id
        );
    } else {
        add_assoc_null(&recovery, "source_node_id");
    }
    if (king_system_runtime_config.node_id[0] != '\0') {
        add_assoc_string(
            &recovery,
            "active_node_id",
            king_system_runtime_config.node_id
        );
    } else {
        add_assoc_null(&recovery, "active_node_id");
    }
    if (king_system_runtime_config.cluster_id[0] != '\0') {
        add_assoc_string(
            &recovery,
            "cluster_id",
            king_system_runtime_config.cluster_id
        );
    } else {
        add_assoc_null(&recovery, "cluster_id");
    }
    add_assoc_bool(
        &recovery,
        "coordinator_state_present",
        king_system_coordinator_state_present ? 1 : 0
    );
    add_assoc_string(
        &recovery,
        "coordinator_state_status",
        king_system_coordinator_state_status
    );
    if (king_system_coordinator_state_path[0] != '\0') {
        add_assoc_string(
            &recovery,
            "coordinator_state_path",
            king_system_coordinator_state_path
        );
    } else {
        add_assoc_null(&recovery, "coordinator_state_path");
    }
    add_assoc_long(
        &recovery,
        "coordinator_state_version",
        (zend_long) king_system_coordinator_state_version
    );
    add_assoc_long(
        &recovery,
        "coordinator_generation",
        (zend_long) king_system_coordinator_generation
    );
    add_assoc_long(
        &recovery,
        "coordinator_created_at",
        (zend_long) king_system_coordinator_created_at
    );
    add_assoc_long(
        &recovery,
        "coordinator_last_loaded_at",
        (zend_long) king_system_coordinator_last_loaded_at
    );
    add_assoc_string(
        &recovery,
        "coordinator_state_error",
        king_system_coordinator_state_error
    );
    add_assoc_zval(return_value, "recovery", &recovery);
    king_system_build_startup_visibility(&startup);
    add_assoc_zval(return_value, "startup", &startup);
    king_system_build_shutdown_visibility(&shutdown);
    add_assoc_zval(return_value, "shutdown", &shutdown);
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
