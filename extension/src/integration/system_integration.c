/*
 * Local system-integration runtime. Owns the process-local component registry,
 * the small system config snapshot, status-transition bookkeeping and the
 * public king_system_* leaves that expose status, restart and request-routing
 * helpers over that inventory.
 */
#include "php_king.h"
#include "include/integration/system_integration.h"
#include <errno.h>
#include <inttypes.h>
#include <limits.h>
#include <sys/stat.h>
#include <unistd.h>

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

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

static const char *king_system_recovery_reason_to_string(void)
{
    return king_system_coordinator_state_recovered ? "node_failure" : "none";
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

static void king_system_build_coordinator_dir_path(char *dest, size_t dest_len)
{
    if (dest == NULL || dest_len == 0) {
        return;
    }

    if (king_system_runtime_config.state_root_path[0] == '\0') {
        dest[0] = '\0';
        return;
    }

    snprintf(
        dest,
        dest_len,
        "%s/.king-system",
        king_system_runtime_config.state_root_path
    );
}

static void king_system_build_coordinator_state_path(char *dest, size_t dest_len)
{
    char coordinator_dir[PATH_MAX];

    if (dest == NULL || dest_len == 0) {
        return;
    }

    king_system_build_coordinator_dir_path(
        coordinator_dir,
        sizeof(coordinator_dir)
    );
    if (coordinator_dir[0] == '\0') {
        dest[0] = '\0';
        return;
    }

    snprintf(dest, dest_len, "%s/coordinator.state", coordinator_dir);
}

static int king_system_ensure_directory_recursive(const char *path)
{
    char current[PATH_MAX];
    size_t len;
    size_t idx;

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    len = strlen(path);
    if (len >= sizeof(current)) {
        return FAILURE;
    }

    memcpy(current, path, len + 1);
    for (idx = 1; idx < len; idx++) {
        if (current[idx] != '/') {
            continue;
        }

        current[idx] = '\0';
        if (mkdir(current, 0755) != 0 && errno != EEXIST) {
            return FAILURE;
        }
        current[idx] = '/';
    }

    if (mkdir(current, 0755) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_system_write_coordinator_state(
    const char *state_path,
    uint64_t version,
    uint64_t generation,
    time_t created_at,
    const char *cluster_id,
    const char *active_node_id,
    zend_bool clean_shutdown
)
{
    char payload[1024];
    char temp_path[PATH_MAX];
    FILE *file;
    int payload_len;

    if (state_path == NULL || state_path[0] == '\0') {
        return FAILURE;
    }

    payload_len = snprintf(
        payload,
        sizeof(payload),
        "version=%" PRIu64 "\n"
        "generation=%" PRIu64 "\n"
        "created_at=%" PRIu64 "\n"
        "updated_at=%" PRIu64 "\n"
        "cluster_id=%s\n"
        "active_node_id=%s\n"
        "clean_shutdown=%d\n",
        version,
        generation,
        (uint64_t) created_at,
        (uint64_t) time(NULL),
        cluster_id != NULL ? cluster_id : "",
        active_node_id != NULL ? active_node_id : "",
        clean_shutdown ? 1 : 0
    );
    if (payload_len < 0 || (size_t) payload_len >= sizeof(payload)) {
        return FAILURE;
    }

    snprintf(temp_path, sizeof(temp_path), "%s.tmp.%ld", state_path, (long) getpid());
    file = fopen(temp_path, "wb");
    if (file == NULL) {
        return FAILURE;
    }

    if (
        fwrite(payload, 1, (size_t) payload_len, file) != (size_t) payload_len
        || fflush(file) != 0
        || fsync(fileno(file)) != 0
    ) {
        fclose(file);
        unlink(temp_path);
        return FAILURE;
    }

    if (fclose(file) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, state_path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

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
)
{
    char buffer[2048];
    char *line;
    char *saveptr = NULL;
    FILE *file;
    uint64_t created_at = 0;
    uint64_t generation = 0;
    zend_bool clean_shutdown = 0;
    uint64_t version = 0;
    int has_clean_shutdown = 0;
    int has_created_at = 0;
    int has_generation = 0;
    int has_version = 0;

    if (
        state_path == NULL
        || version_out == NULL
        || generation_out == NULL
        || created_at_out == NULL
        || cluster_id_out == NULL
        || cluster_id_out_len == 0
        || active_node_id_out == NULL
        || active_node_id_out_len == 0
        || clean_shutdown_out == NULL
    ) {
        return FAILURE;
    }

    file = fopen(state_path, "rb");
    if (file == NULL) {
        return FAILURE;
    }

    memset(buffer, 0, sizeof(buffer));
    if (fread(buffer, 1, sizeof(buffer) - 1, file) == 0 && ferror(file)) {
        fclose(file);
        return FAILURE;
    }
    fclose(file);

    cluster_id_out[0] = '\0';
    active_node_id_out[0] = '\0';

    for (line = strtok_r(buffer, "\n", &saveptr);
         line != NULL;
         line = strtok_r(NULL, "\n", &saveptr)) {
        char *eq = strchr(line, '=');

        if (eq == NULL) {
            continue;
        }

        *eq = '\0';
        if (strcmp(line, "version") == 0) {
            version = strtoull(eq + 1, NULL, 10);
            has_version = 1;
        } else if (strcmp(line, "generation") == 0) {
            generation = strtoull(eq + 1, NULL, 10);
            has_generation = 1;
        } else if (strcmp(line, "created_at") == 0) {
            created_at = strtoull(eq + 1, NULL, 10);
            has_created_at = 1;
        } else if (strcmp(line, "cluster_id") == 0) {
            strncpy(cluster_id_out, eq + 1, cluster_id_out_len - 1);
        } else if (strcmp(line, "active_node_id") == 0) {
            strncpy(active_node_id_out, eq + 1, active_node_id_out_len - 1);
        } else if (strcmp(line, "clean_shutdown") == 0) {
            clean_shutdown = atoi(eq + 1) != 0 ? 1 : 0;
            has_clean_shutdown = 1;
        }
    }

    if (
        !has_version
        || !has_generation
        || !has_created_at
        || !has_clean_shutdown
        || version == 0
        || generation == 0
    ) {
        return FAILURE;
    }

    *version_out = version;
    *generation_out = generation;
    *created_at_out = (time_t) created_at;
    *clean_shutdown_out = clean_shutdown;
    return SUCCESS;
}

static int king_system_initialize_coordinator_state(void)
{
    char coordinator_dir[PATH_MAX];
    char loaded_cluster_id[64];
    char loaded_node_id[64];
    char state_path[PATH_MAX];
    time_t created_at;
    time_t now;
    uint64_t generation;
    uint64_t version;
    zend_bool clean_shutdown = 0;

    king_system_reset_coordinator_state_runtime();

    if (king_system_runtime_config.state_root_path[0] == '\0') {
        return SUCCESS;
    }

    king_system_build_coordinator_dir_path(
        coordinator_dir,
        sizeof(coordinator_dir)
    );
    king_system_build_coordinator_state_path(
        state_path,
        sizeof(state_path)
    );

    if (king_system_ensure_directory_recursive(coordinator_dir) != SUCCESS) {
        snprintf(
            king_system_coordinator_state_status,
            sizeof(king_system_coordinator_state_status),
            "%s",
            "failed"
        );
        snprintf(
            king_system_coordinator_state_error,
            sizeof(king_system_coordinator_state_error),
            "Could not create system coordinator directory '%s'.",
            coordinator_dir
        );
        return FAILURE;
    }

    now = time(NULL);
    if (access(state_path, F_OK) != 0) {
        version = 1;
        generation = 1;
        created_at = now;
    } else {
        if (king_system_load_coordinator_state(
                state_path,
                &version,
                &generation,
                &created_at,
                loaded_cluster_id,
                sizeof(loaded_cluster_id),
                loaded_node_id,
                sizeof(loaded_node_id),
                &clean_shutdown
            ) != SUCCESS) {
            snprintf(
                king_system_coordinator_state_status,
                sizeof(king_system_coordinator_state_status),
                "%s",
                "failed"
            );
            snprintf(
                king_system_coordinator_state_error,
                sizeof(king_system_coordinator_state_error),
                "System coordinator state at '%s' is unreadable or corrupted.",
                state_path
            );
            return FAILURE;
        }

        if (
            loaded_cluster_id[0] != '\0'
            && king_system_runtime_config.cluster_id[0] != '\0'
            && strcmp(loaded_cluster_id, king_system_runtime_config.cluster_id) != 0
        ) {
            snprintf(
                king_system_coordinator_state_status,
                sizeof(king_system_coordinator_state_status),
                "%s",
                "failed"
            );
            snprintf(
                king_system_coordinator_state_error,
                sizeof(king_system_coordinator_state_error),
                "System coordinator state at '%s' belongs to cluster '%s', not '%s'.",
                state_path,
                loaded_cluster_id,
                king_system_runtime_config.cluster_id
            );
            return FAILURE;
        }

        if (!clean_shutdown && loaded_node_id[0] != '\0') {
            king_system_coordinator_state_recovered = true;
            strncpy(
                king_system_recovery_source_node_id,
                loaded_node_id,
                sizeof(king_system_recovery_source_node_id) - 1
            );
        }

        generation++;
    }

    if (king_system_write_coordinator_state(
            state_path,
            version,
            generation,
            created_at,
            king_system_runtime_config.cluster_id,
            king_system_runtime_config.node_id,
            0
        ) != SUCCESS) {
        snprintf(
            king_system_coordinator_state_status,
            sizeof(king_system_coordinator_state_status),
            "%s",
            "failed"
        );
        snprintf(
            king_system_coordinator_state_error,
            sizeof(king_system_coordinator_state_error),
            "Could not persist system coordinator state at '%s'.",
            state_path
        );
        return FAILURE;
    }

    king_system_coordinator_state_present = true;
    king_system_coordinator_state_version = version;
    king_system_coordinator_generation = generation;
    king_system_coordinator_created_at = created_at;
    king_system_coordinator_last_loaded_at = now;
    strncpy(
        king_system_coordinator_state_path,
        state_path,
        sizeof(king_system_coordinator_state_path) - 1
    );
    snprintf(
        king_system_coordinator_state_status,
        sizeof(king_system_coordinator_state_status),
        "%s",
        king_system_coordinator_state_recovered ? "recovered" : "initialized"
    );
    return SUCCESS;
}

static void king_system_mark_coordinator_clean_shutdown(void)
{
    if (
        !king_system_coordinator_state_present
        || king_system_coordinator_state_path[0] == '\0'
    ) {
        return;
    }

    if (king_system_write_coordinator_state(
            king_system_coordinator_state_path,
            king_system_coordinator_state_version > 0
                ? king_system_coordinator_state_version
                : 1,
            king_system_coordinator_generation > 0
                ? king_system_coordinator_generation
                : 1,
            king_system_coordinator_created_at > 0
                ? king_system_coordinator_created_at
                : time(NULL),
            king_system_runtime_config.cluster_id,
            king_system_runtime_config.node_id,
            1
        ) == SUCCESS) {
        king_system_coordinator_last_loaded_at = time(NULL);
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

static void king_system_build_component_startup_dependencies(
    zval *dependencies,
    king_component_type_t type
)
{
    array_init(dependencies);

    switch (type) {
        case KING_COMPONENT_CONFIG:
            break;
        case KING_COMPONENT_CLIENT:
        case KING_COMPONENT_SERVER:
        case KING_COMPONENT_TELEMETRY:
        case KING_COMPONENT_OBJECT_STORE:
        case KING_COMPONENT_IIBIN:
            add_next_index_string(dependencies, "config");
            break;
        case KING_COMPONENT_MCP:
        case KING_COMPONENT_SEMANTIC_DNS:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "client");
            break;
        case KING_COMPONENT_ROUTER_LOADBALANCER:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "client");
            add_next_index_string(dependencies, "server");
            break;
        case KING_COMPONENT_PIPELINE_ORCHESTRATOR:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "telemetry");
            add_next_index_string(dependencies, "object_store");
            break;
        case KING_COMPONENT_CDN:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "server");
            add_next_index_string(dependencies, "object_store");
            break;
        case KING_COMPONENT_AUTOSCALING:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "telemetry");
            add_next_index_string(dependencies, "server");
            add_next_index_string(dependencies, "semantic_dns");
            break;
    }
}

static void king_system_build_component_pending_startup_dependencies(
    zval *pending_dependencies,
    king_component_type_t type
)
{
    zval dependencies;
    zval *dependency_name;

    array_init(pending_dependencies);
    king_system_build_component_startup_dependencies(&dependencies, type);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependencies), dependency_name) {
        king_component_info_t *dependency_info;
        const char *dependency;

        if (Z_TYPE_P(dependency_name) != IS_STRING) {
            continue;
        }

        dependency = Z_STRVAL_P(dependency_name);
        dependency_info = king_system_get_component_by_name(dependency);
        if (dependency_info == NULL || !king_system_component_startup_dependency_ready(dependency)) {
            add_next_index_string(pending_dependencies, dependency);
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&dependencies);
}

static void king_system_build_component_shutdown_dependents(
    zval *dependents,
    king_component_type_t type
)
{
    const king_system_startup_entry_t *target_entry;
    zval dependency_name_list;
    zval *dependency_name;
    size_t idx;

    array_init(dependents);
    target_entry = king_system_get_startup_entry(type);
    if (target_entry == NULL) {
        return;
    }

    for (
        idx = 0;
        idx < sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0]);
        idx++
    ) {
        const king_system_startup_entry_t *entry = &king_system_startup_plan[idx];
        zend_bool depends_on_target = 0;

        if (entry->type == type) {
            continue;
        }

        king_system_build_component_startup_dependencies(
            &dependency_name_list,
            entry->type
        );

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependency_name_list), dependency_name) {
            if (
                Z_TYPE_P(dependency_name) == IS_STRING &&
                strcmp(Z_STRVAL_P(dependency_name), target_entry->name) == 0
            ) {
                depends_on_target = 1;
                break;
            }
        } ZEND_HASH_FOREACH_END();

        zval_ptr_dtor(&dependency_name_list);

        if (depends_on_target) {
            add_next_index_string(dependents, (char *) entry->name);
        }
    }
}

static void king_system_build_component_pending_shutdown_dependents(
    zval *pending_dependents,
    king_component_type_t type
)
{
    zval dependents;
    zval *dependent_name;

    array_init(pending_dependents);
    king_system_build_component_shutdown_dependents(&dependents, type);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependents), dependent_name) {
        king_component_info_t *dependent_info;

        if (Z_TYPE_P(dependent_name) != IS_STRING) {
            continue;
        }

        dependent_info = king_system_get_component_by_name(
            Z_STRVAL_P(dependent_name)
        );
        if (
            dependent_info != NULL &&
            king_system_component_started(dependent_info->status)
        ) {
            add_next_index_string(
                pending_dependents,
                Z_STRVAL_P(dependent_name)
            );
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&dependents);
}

/*
 * Publish the canonical startup graph even before ordered startup transitions
 * are implemented so callers can inspect the intended dependency plan without
 * reverse-engineering it from registration order.
 */
static void king_system_build_startup_visibility(zval *startup)
{
    zval blocked_components;
    zval component_entry;
    zval components;
    zval dependencies;
    zval pending_components;
    zval pending_dependencies;
    zval ready_to_start_components;
    zval started_components;
    zval status_copy;
    zval ordered_components;
    size_t idx;

    array_init(startup);
    array_init(&blocked_components);
    array_init(&components);
    array_init(&ordered_components);
    array_init(&pending_components);
    array_init(&ready_to_start_components);
    array_init(&started_components);

    for (
        idx = 0;
        idx < sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0]);
        idx++
    ) {
        const king_system_startup_entry_t *entry = &king_system_startup_plan[idx];
        king_component_info_t *info = king_system_get_component_internal(entry->type);
        zend_bool started = 0;
        zend_bool ready_to_start = 0;

        if (info != NULL) {
            started = king_system_component_started(info->status);
        }

        add_next_index_string(&ordered_components, (char *) entry->name);
        king_system_build_component_startup_dependencies(&dependencies, entry->type);
        king_system_build_component_pending_startup_dependencies(
            &pending_dependencies,
            entry->type
        );
        ready_to_start = !started
            && zend_hash_num_elements(Z_ARRVAL(pending_dependencies)) == 0;

        if (started) {
            add_next_index_string(&started_components, (char *) entry->name);
        } else {
            add_next_index_string(&pending_components, (char *) entry->name);
        }

        if (ready_to_start) {
            add_next_index_string(&ready_to_start_components, (char *) entry->name);
        }

        if (
            !started &&
            zend_hash_num_elements(Z_ARRVAL(pending_dependencies)) > 0
        ) {
            ZVAL_COPY(&status_copy, &pending_dependencies);
            add_assoc_zval(&blocked_components, entry->name, &status_copy);
        }

        array_init(&component_entry);
        add_assoc_long(&component_entry, "order", (zend_long) entry->order);
        add_assoc_zval(&component_entry, "dependencies", &dependencies);
        add_assoc_zval(
            &component_entry,
            "pending_dependencies",
            &pending_dependencies
        );
        add_assoc_bool(&component_entry, "status_visible", info != NULL ? 1 : 0);
        add_assoc_bool(&component_entry, "started", started);
        add_assoc_bool(&component_entry, "ready_to_start", ready_to_start);
        add_assoc_zval(&components, entry->name, &component_entry);
    }

    add_assoc_long(
        startup,
        "catalog_component_count",
        (zend_long) (
            sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0])
        )
    );
    add_assoc_zval(startup, "ordered_components", &ordered_components);
    add_assoc_zval(startup, "started_components", &started_components);
    add_assoc_zval(startup, "pending_components", &pending_components);
    add_assoc_zval(
        startup,
        "ready_to_start_components",
        &ready_to_start_components
    );
    add_assoc_zval(startup, "blocked_components", &blocked_components);
    add_assoc_zval(startup, "components", &components);
}

/*
 * Publish the canonical shutdown graph separately from the startup graph so
 * operators can inspect which components are leaf shutdown candidates, which
 * still have live dependents, and that King requires a drain-first stop path.
 */
static void king_system_build_shutdown_visibility(zval *shutdown)
{
    zval active_components;
    zval blocked_components;
    zval component_entry;
    zval components;
    zval inactive_components;
    zval ordered_components;
    zval ready_to_stop_components;
    zval status_copy;
    size_t idx;

    array_init(shutdown);
    array_init(&active_components);
    array_init(&blocked_components);
    array_init(&components);
    array_init(&inactive_components);
    array_init(&ordered_components);
    array_init(&ready_to_stop_components);

    for (
        idx = 0;
        idx < sizeof(king_system_shutdown_plan) / sizeof(king_system_shutdown_plan[0]);
        idx++
    ) {
        const king_system_startup_entry_t *entry = &king_system_shutdown_plan[idx];
        king_component_info_t *info = king_system_get_component_internal(entry->type);
        zval component_pending_dependents;
        zval component_dependents;
        zend_bool started = 0;
        zend_bool ready_to_stop = 0;

        if (info != NULL) {
            started = king_system_component_started(info->status);
        }

        add_next_index_string(&ordered_components, (char *) entry->name);
        king_system_build_component_shutdown_dependents(
            &component_dependents,
            entry->type
        );
        king_system_build_component_pending_shutdown_dependents(
            &component_pending_dependents,
            entry->type
        );
        ready_to_stop = started
            && zend_hash_num_elements(
                Z_ARRVAL(component_pending_dependents)
            ) == 0;

        if (started) {
            add_next_index_string(&active_components, (char *) entry->name);
        } else {
            add_next_index_string(&inactive_components, (char *) entry->name);
        }

        if (ready_to_stop) {
            add_next_index_string(&ready_to_stop_components, (char *) entry->name);
        }

        if (
            started &&
            zend_hash_num_elements(Z_ARRVAL(component_pending_dependents)) > 0
        ) {
            ZVAL_COPY(&status_copy, &component_pending_dependents);
            add_assoc_zval(&blocked_components, entry->name, &status_copy);
        }

        array_init(&component_entry);
        add_assoc_long(&component_entry, "order", (zend_long) entry->order);
        add_assoc_zval(&component_entry, "dependents", &component_dependents);
        add_assoc_zval(
            &component_entry,
            "pending_dependents",
            &component_pending_dependents
        );
        add_assoc_bool(&component_entry, "status_visible", info != NULL ? 1 : 0);
        add_assoc_bool(&component_entry, "started", started);
        add_assoc_bool(&component_entry, "ready_to_stop", ready_to_stop);
        add_assoc_zval(&components, entry->name, &component_entry);
    }

    add_assoc_bool(shutdown, "drain_first_required", 1);
    add_assoc_long(
        shutdown,
        "catalog_component_count",
        (zend_long) (
            sizeof(king_system_shutdown_plan) / sizeof(king_system_shutdown_plan[0])
        )
    );
    add_assoc_zval(shutdown, "ordered_components", &ordered_components);
    add_assoc_zval(shutdown, "active_components", &active_components);
    add_assoc_zval(shutdown, "inactive_components", &inactive_components);
    add_assoc_zval(shutdown, "ready_to_stop_components", &ready_to_stop_components);
    add_assoc_zval(shutdown, "blocked_components", &blocked_components);
    add_assoc_zval(shutdown, "components", &components);
}

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

    if (draining_request || state->has_error) {
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
        king_system_coordinator_state_recovered
            && king_system_initialized
            && strcmp(admission_state.lifecycle, "ready") != 0
            ? 1 : 0
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
