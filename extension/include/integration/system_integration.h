/*
 * include/integration/system_integration.h - System integration API
 * ==================================================================
 *
 * This header exposes the types and functions used to coordinate the
 * extension's component subsystems.
 */

#ifndef KING_SYSTEM_INTEGRATION_H
#define KING_SYSTEM_INTEGRATION_H

#include <php.h>
#include <stdint.h>
#include <time.h>

/* Component headers used by the system layer. */
#include "include/config/config.h"
#include "include/semantic_dns/semantic_dns.h"
#include "include/object_store/object_store.h"
#include "include/telemetry/telemetry.h"
#include "include/autoscaling/autoscaling.h"
#include "include/mcp/mcp.h"
#include "include/iibin/iibin.h"

/* --- System Integration Types --- */

typedef enum {
    KING_COMPONENT_CONFIG,
    KING_COMPONENT_CLIENT,
    KING_COMPONENT_SERVER,
    KING_COMPONENT_SEMANTIC_DNS,
    KING_COMPONENT_OBJECT_STORE,
    KING_COMPONENT_CDN,
    KING_COMPONENT_TELEMETRY,
    KING_COMPONENT_AUTOSCALING,
    KING_COMPONENT_MCP,
    KING_COMPONENT_IIBIN,
    KING_COMPONENT_PIPELINE_ORCHESTRATOR
} king_component_type_t;

typedef enum {
    KING_COMPONENT_STATUS_UNINITIALIZED,
    KING_COMPONENT_STATUS_INITIALIZING,
    KING_COMPONENT_STATUS_RUNNING,
    KING_COMPONENT_STATUS_ERROR,
    KING_COMPONENT_STATUS_SHUTTING_DOWN,
    KING_COMPONENT_STATUS_SHUTDOWN
} king_component_status_t;

typedef struct _king_component_info_t {
    king_component_type_t type;
    char name[64];
    char version[32];
    king_component_status_t status;
    time_t initialized_at;
    time_t last_health_check;
    uint64_t requests_handled;
    uint64_t errors_encountered;
    double avg_response_time_ms;
    zval dependencies; /* PHP array of component dependencies */
    zval configuration; /* PHP array of component configuration */
} king_component_info_t;

typedef struct _king_system_config_t {
    zend_bool enabled;
    char environment[32]; /* development, staging, production */
    char cluster_id[64];
    char node_id[64];
    uint32_t max_concurrent_requests;
    uint32_t health_check_interval_seconds;
    uint32_t component_timeout_seconds;
    zend_bool enable_cross_component_tracing;
    zend_bool enable_performance_monitoring;
    zend_bool enable_auto_scaling;
    zend_bool enable_circuit_breaker;
    zval global_configuration; /* PHP array of global settings */
} king_system_config_t;

typedef struct _king_system_health_t {
    zend_bool overall_healthy;
    uint32_t healthy_components;
    uint32_t total_components;
    double system_load;
    uint64_t total_memory_usage_bytes;
    uint64_t total_requests_processed;
    uint64_t total_errors;
    double avg_system_response_time_ms;
    time_t last_health_check;
    zval component_health; /* PHP array of component health status */
} king_system_health_t;

/* --- PHP Function Prototypes --- */

/* Initializes all system components. */
PHP_FUNCTION(king_system_init);

/* Shuts down all system components. */
PHP_FUNCTION(king_system_shutdown);

/* Returns overall system status. */
PHP_FUNCTION(king_system_get_status);

/* Performs a health check across components. */
PHP_FUNCTION(king_system_health_check);

/* Returns aggregated system metrics. */
PHP_FUNCTION(king_system_get_metrics);

/* Processes a request through the integrated system. */
PHP_FUNCTION(king_system_process_request);

/* Returns information about one component. */
PHP_FUNCTION(king_system_get_component_info);

/* Restarts one component. */
PHP_FUNCTION(king_system_restart_component);

/* Returns a performance report. */
PHP_FUNCTION(king_system_get_performance_report);

/* --- Internal C API --- */

int king_system_init_all_components(king_system_config_t *config);
void king_system_shutdown_all_components(void);
int king_system_register_component(king_component_type_t type, const char *name, const char *version);
int king_system_update_component_status(king_component_type_t type, king_component_status_t status);
king_component_info_t* king_system_get_component(king_component_type_t type);
int king_system_check_component_health(king_component_type_t type);
int king_system_check_all_components_health(void);
int king_system_handle_component_error(king_component_type_t type, const char *error_message);
int king_system_cross_component_call(king_component_type_t from, king_component_type_t to, const char *method, zval *params, zval *result);
int king_system_broadcast_event(const char *event_name, zval *event_data);
king_system_health_t* king_system_get_overall_health(void);
const char* king_component_type_to_string(king_component_type_t type);
const char* king_component_status_to_string(king_component_status_t status);

/* --- Integration Utilities --- */
int king_system_validate_dependencies(void);
int king_system_setup_cross_component_communication(void);
int king_system_initialize_monitoring(void);
int king_system_setup_circuit_breakers(void);
int king_system_optimize_performance(void);

#endif /* KING_SYSTEM_INTEGRATION_H */
