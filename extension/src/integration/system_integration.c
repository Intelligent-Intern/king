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

static void king_component_info_dtor(zval *zv)
{
    king_component_info_t *info = Z_PTR_P(zv);
    if (info) {
        zval_ptr_dtor(&info->dependencies);
        zval_ptr_dtor(&info->configuration);
        efree(info);
    }
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
    king_system_register_component(KING_COMPONENT_MCP, "mcp", "1.0.0");
    king_system_register_component(KING_COMPONENT_TELEMETRY, "telemetry", "1.0.0");
    king_system_register_component(KING_COMPONENT_AUTOSCALING, "autoscaling", "1.0.0");
    king_system_register_component(KING_COMPONENT_PIPELINE_ORCHESTRATOR, "orchestrator", "1.0.0");

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
    memset(info, 0, sizeof(king_component_info_t));
    
    info->type = type;
    strncpy(info->name, name, sizeof(info->name) - 1);
    strncpy(info->version, version, sizeof(info->version) - 1);
    info->status = KING_COMPONENT_STATUS_RUNNING;
    info->initialized_at = time(NULL);
    
    array_init(&info->dependencies);
    array_init(&info->configuration);
    
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
    memset(&config, 0, sizeof(config));
    config.enabled = true;
    
    if (king_system_init_all_components(&config) == SUCCESS) {
        RETURN_TRUE;
    }
    
    RETURN_FALSE;
}

PHP_FUNCTION(king_system_process_request)
{
    zval *request_data;
    if (zend_parse_parameters(1, "a", &request_data) == FAILURE) RETURN_FALSE;

    /* Simulated request processing through components */
    RETURN_TRUE;
}

PHP_FUNCTION(king_system_restart_component)
{
    char *name;
    size_t name_len;
    if (zend_parse_parameters(1, "s", &name, &name_len) == FAILURE) RETURN_FALSE;

    /* Simulated component restart */
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

    array_init(return_value);
    add_assoc_bool(return_value, "initialized", king_system_initialized);
    add_assoc_long(return_value, "component_count", king_system_initialized ? zend_hash_num_elements(&king_system_components) : 0);
}
