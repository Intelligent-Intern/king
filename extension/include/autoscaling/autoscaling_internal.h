#ifndef KING_AUTOSCALING_INTERNAL_H
#define KING_AUTOSCALING_INTERNAL_H

#include "autoscaling/autoscaling.h"

typedef enum _king_autoscaling_provider_kind_t {
    KING_AUTOSCALING_PROVIDER_NONE = 0,
    KING_AUTOSCALING_PROVIDER_SIMULATED,
    KING_AUTOSCALING_PROVIDER_HETZNER
} king_autoscaling_provider_kind_t;

typedef enum _king_autoscaling_node_lifecycle_t {
    KING_AUTOSCALING_NODE_PROVISIONED = 0,
    KING_AUTOSCALING_NODE_REGISTERED,
    KING_AUTOSCALING_NODE_READY,
    KING_AUTOSCALING_NODE_DRAINING,
    KING_AUTOSCALING_NODE_DELETED
} king_autoscaling_node_lifecycle_t;

typedef enum _king_autoscaling_budget_status_t {
    KING_AUTOSCALING_BUDGET_STATUS_DISABLED = 0,
    KING_AUTOSCALING_BUDGET_STATUS_OK = 1,
    KING_AUTOSCALING_BUDGET_STATUS_WARNING = 2,
    KING_AUTOSCALING_BUDGET_STATUS_HARD_LIMIT = 3,
    KING_AUTOSCALING_BUDGET_STATUS_API_ERROR = 4
} king_autoscaling_budget_status_t;

typedef struct _king_autoscaling_managed_node_t {
    zend_long server_id;
    char name[128];
    char provider_status[32];
    king_autoscaling_node_lifecycle_t lifecycle_state;
    time_t created_at;
    time_t registered_at;
    time_t ready_at;
    time_t draining_at;
    time_t deleted_at;
    zend_bool active;
} king_autoscaling_managed_node_t;

typedef struct _king_autoscaling_runtime_state_t {
    kg_cloud_autoscale_config_t config;
    king_autoscaling_provider_kind_t provider_kind;
    zend_bool initialized;
    zend_bool monitoring_active;
    zend_bool controller_token_configured;
    uint64_t action_count;
    time_t last_scale_up_at;
    time_t last_scale_down_at;
    time_t last_monitor_tick_at;
    king_load_metrics_t last_monitor_metrics;
    uint32_t last_monitor_live_signal_mask;
    uint32_t last_monitor_scale_up_signal_mask;
    uint32_t last_monitor_scale_down_ready_mask;
    uint32_t last_monitor_hold_blocker_mask;
    char last_monitor_decision[16];
    char last_action_kind[32];
    char last_signal_source[64];
    char last_decision_reason[256];
    char last_error[256];
    char last_warning[256];
    king_autoscaling_budget_status_t spend_status;
    king_autoscaling_budget_status_t quota_status;
    zend_long spend_usage_percent;
    zend_long quota_usage_percent;
    char budget_probe_error[256];
    king_autoscaling_managed_node_t *managed_nodes;
    size_t managed_node_count;
    size_t managed_node_capacity;
} king_autoscaling_runtime_state_t;

extern king_autoscaling_runtime_state_t king_autoscaling_runtime;

void king_autoscaling_runtime_reset(void);
void king_autoscaling_runtime_sync_instance_count(void);
size_t king_autoscaling_runtime_count_active_nodes(void);
int king_autoscaling_runtime_load_state(void);
int king_autoscaling_runtime_persist_state(void);
int king_autoscaling_runtime_append_node(
    zend_long server_id,
    const char *name,
    const char *provider_status,
    time_t created_at,
    zend_bool active
);
king_autoscaling_managed_node_t *king_autoscaling_runtime_find_node(zend_long server_id);
king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_active_node(void);
king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_draining_node(void);
int king_autoscaling_provider_scale_up(uint32_t count);
int king_autoscaling_provider_scale_down(uint32_t count);
int king_autoscaling_provider_rollback_stale_pending_node(time_t now);

#endif
