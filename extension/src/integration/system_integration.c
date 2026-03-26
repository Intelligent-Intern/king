/*
 * src/integration/system_integration.c - System Integration Runtime
 * =========================================================================
 *
 * This module coordinates the extension's disparate subsystems. It manages
 * component lifecycles, health checks, and cross-component communication.
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
    king_system_register_component(KING_COMPONENT_CONFIG, "config", "1.0.0");
    king_system_register_component(KING_COMPONENT_CLIENT, "client", "1.0.0");
    king_system_register_component(KING_COMPONENT_SERVER, "server", "1.0.0");
    king_system_register_component(
        KING_COMPONENT_ROUTER_LOADBALANCER,
        "router_loadbalancer",
        "1.0.0"
    );
    king_system_register_component(KING_COMPONENT_MCP, "mcp", "1.0.0");
    king_system_register_component(KING_COMPONENT_TELEMETRY, "telemetry", "1.0.0");
    king_system_register_component(KING_COMPONENT_AUTOSCALING, "autoscaling", "1.0.0");
    king_system_register_component(KING_COMPONENT_PIPELINE_ORCHESTRATOR, "orchestrator", "1.0.0");
    king_system_register_component(KING_COMPONENT_OBJECT_STORE, "object_store", "1.0.0");
    king_system_register_component(KING_COMPONENT_CDN, "cdn", "1.0.0");
    king_system_register_component(KING_COMPONENT_IIBIN, "iibin", "1.0.0");
    king_system_register_component(KING_COMPONENT_SEMANTIC_DNS, "semantic_dns", "1.0.0");

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

    king_system_apply_all_transitions();
    if (!king_system_initialized || king_system_check_all_components_health() != SUCCESS) {
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
    zval component_entry;
    zval components;
    time_t now;
    int draining_count = 0;
    int ready_count = 0;
    uint32_t component_count = 0;
    zend_ulong idx;
    king_component_info_t *info;

    now = time(NULL);
    king_system_apply_all_transitions();

    component_count = king_system_initialized
        ? (uint32_t) zend_hash_num_elements(&king_system_components)
        : 0;

    array_init(return_value);
    add_assoc_bool(return_value, "initialized", king_system_initialized);
    add_assoc_long(return_value, "component_count", component_count);

    array_init(&components);
    if (king_system_initialized) {
        ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info) {
            time_t last_health_check = info->last_health_check;
            array_init(&component_entry);
            add_assoc_string(&component_entry, "status", king_component_status_to_string(info->status));
            add_assoc_bool(
                &component_entry,
                "ready",
                info->status == KING_COMPONENT_STATUS_RUNNING ? 1 : 0
            );
            add_assoc_long(&component_entry, "requests_handled", (zend_long) info->requests_handled);
            add_assoc_long(&component_entry, "errors_encountered", (zend_long) info->errors_encountered);
            add_assoc_long(&component_entry, "last_health_check", (zend_long) last_health_check);
            add_assoc_long(&component_entry, "up_for_seconds", (zend_long) (now - last_health_check));

            if (info->status == KING_COMPONENT_STATUS_INITIALIZING ||
                info->status == KING_COMPONENT_STATUS_SHUTTING_DOWN) {
                draining_count++;
            } else if (info->status == KING_COMPONENT_STATUS_RUNNING) {
                ready_count++;
            }

            add_assoc_zval(&components, info->name, &component_entry);
        } ZEND_HASH_FOREACH_END();
    }
    add_assoc_zval(return_value, "components", &components);
    add_assoc_long(return_value, "components_ready", (zend_long) ready_count);
    add_assoc_long(return_value, "components_draining", (zend_long) draining_count);
    add_assoc_long(
        return_value,
        "health_check_interval_seconds",
        (zend_long) king_system_runtime_config.health_check_interval_seconds
    );
}
