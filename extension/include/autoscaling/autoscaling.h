/*
 * include/autoscaling/autoscaling.h - Public C API for autoscaling
 * ================================================================
 *
 * This header exposes the autoscaling data structures and PHP functions used
 * to monitor load and trigger scale-up or scale-down actions.
 */

#ifndef KING_AUTOSCALING_H
#define KING_AUTOSCALING_H

#include <php.h>
#include <stdint.h>
#include <time.h>

/* --- Autoscaling State --- */

typedef struct _king_load_metrics_t {
    double cpu_utilization_percent;
    double memory_utilization_percent;
    uint64_t active_connections;
    uint64_t requests_per_second;
    uint64_t response_time_ms;
    uint64_t queue_depth;
    time_t timestamp;
} king_load_metrics_t;

typedef struct _king_scaling_thresholds_t {
    double cpu_scale_up_threshold;
    double cpu_scale_down_threshold;
    double memory_scale_up_threshold;
    double memory_scale_down_threshold;
    uint64_t connections_scale_up_threshold;
    uint64_t connections_scale_down_threshold;
    uint64_t response_time_scale_up_threshold;
    uint32_t scale_up_cooldown_seconds;
    uint32_t scale_down_cooldown_seconds;
    uint32_t min_instances;
    uint32_t max_instances;
} king_scaling_thresholds_t;

typedef struct _king_autoscaling_config_t {
    zend_bool enabled;
    uint32_t monitoring_interval_ms;
    uint32_t metrics_history_size;
    king_scaling_thresholds_t thresholds;
    char *provisioning_script_path;
    zval mcp_coordinator_config; /* PHP array with MCP coordinator settings */
} king_autoscaling_config_t;

/* --- PHP Function Prototypes --- */

/* Initializes autoscaling from a PHP config array. */
PHP_FUNCTION(king_autoscaling_init);

/* Starts load monitoring. */
PHP_FUNCTION(king_autoscaling_start_monitoring);

/* Stops load monitoring. */
PHP_FUNCTION(king_autoscaling_stop_monitoring);

/* Returns current load metrics. */
PHP_FUNCTION(king_autoscaling_get_metrics);

/* Triggers a manual scale-up. */
PHP_FUNCTION(king_autoscaling_scale_up);

/* Triggers a manual scale-down. */
PHP_FUNCTION(king_autoscaling_scale_down);

/* Returns current autoscaling status. */
PHP_FUNCTION(king_autoscaling_get_status);

/* --- Internal C API --- */

int king_autoscaling_init_system(king_autoscaling_config_t *config);
void king_autoscaling_shutdown_system(void);
int king_autoscaling_collect_metrics(king_load_metrics_t *metrics);
int king_autoscaling_evaluate_scaling_decision(const king_load_metrics_t *metrics);
int king_autoscaling_provision_instances(uint32_t count);
int king_autoscaling_terminate_instances(uint32_t count);
void king_autoscaling_update_resource_sharing(void);
extern uint32_t king_current_instances;

#endif /* KING_AUTOSCALING_H */
