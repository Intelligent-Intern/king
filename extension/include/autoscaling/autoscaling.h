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
#include "include/config/cloud_autoscale/base_layer.h"
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

/* Returns the managed node inventory. */
PHP_FUNCTION(king_autoscaling_get_nodes);

/* Marks one managed node as registered with the controller. */
PHP_FUNCTION(king_autoscaling_register_node);

/* Marks one managed node as ready for admission. */
PHP_FUNCTION(king_autoscaling_mark_node_ready);

/* Drains one ready managed node before termination. */
PHP_FUNCTION(king_autoscaling_drain_node);

/* --- Internal C API --- */

int king_autoscaling_init_system(const kg_cloud_autoscale_config_t *config);
void king_autoscaling_shutdown_system(void);
int king_autoscaling_collect_metrics(king_load_metrics_t *metrics);
int king_autoscaling_evaluate_scaling_decision(const king_load_metrics_t *metrics);
int king_autoscaling_provision_instances(uint32_t count);
int king_autoscaling_terminate_instances(uint32_t count);
void king_autoscaling_update_resource_sharing(void);
extern uint32_t king_current_instances;

#endif /* KING_AUTOSCALING_H */
