/*
 * src/autoscaling/autoscaling.c - Autoscaling Runtime State and Status Surface
 * =============================================================================
 */
#include "php_king.h"
#include "include/autoscaling/autoscaling.h"
#include "include/config/cloud_autoscale/base_layer.h"
#include "include/config/cloud_autoscale/config.h"
#include "include/king_globals.h"
#include "include/telemetry/telemetry.h"
#include "autoscaling/autoscaling_internal.h"

#include <errno.h>
#include <stdio.h>
#include <strings.h>
#include <time.h>
#include <unistd.h>

#define KING_AUTOSCALING_STATE_VERSION 2

typedef struct _king_autoscaling_signal_snapshot_t {
    king_load_metrics_t metrics;
    zend_bool telemetry_signal_present;
    zend_bool system_signal_present;
    zend_bool cpu_live;
    zend_bool memory_live;
    zend_bool active_connections_live;
    zend_bool requests_per_second_live;
    zend_bool response_time_live;
    zend_bool queue_depth_live;
} king_autoscaling_signal_snapshot_t;

king_autoscaling_runtime_state_t king_autoscaling_runtime = {0};
uint32_t king_current_instances = 1;

static char *king_autoscaling_strdup_persistent(const char *value)
{
    return pestrdup(value != NULL ? value : "", 1);
}

static void king_autoscaling_reset_message(char *buffer, size_t length)
{
    if (buffer == NULL || length == 0) {
        return;
    }

    buffer[0] = '\0';
}

static void king_autoscaling_set_runtime_string(char *buffer, size_t length, const char *value)
{
    if (buffer == NULL || length == 0) {
        return;
    }

    snprintf(buffer, length, "%s", value != NULL ? value : "");
}

static zend_bool king_autoscaling_finalize_node_update(const char *action_kind);

static void king_autoscaling_free_runtime_config(kg_cloud_autoscale_config_t *config)
{
    if (config == NULL) {
        return;
    }

    pefree(config->provider, 1);
    pefree(config->region, 1);
    pefree(config->credentials_path, 1);
    pefree(config->api_endpoint, 1);
    pefree(config->state_path, 1);
    pefree(config->server_name_prefix, 1);
    pefree(config->bootstrap_user_data, 1);
    pefree(config->firewall_ids, 1);
    pefree(config->placement_group_id, 1);
    pefree(config->prepared_release_url, 1);
    pefree(config->join_endpoint, 1);
    pefree(config->hetzner_api_token, 1);
    pefree(config->hetzner_budget_path, 1);
    pefree(config->scale_up_policy, 1);
    pefree(config->instance_type, 1);
    pefree(config->instance_image_id, 1);
    pefree(config->network_config, 1);
    pefree(config->instance_tags, 1);

    memset(config, 0, sizeof(*config));
}

static void king_autoscaling_reset_budget_runtime_state(void)
{
    king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
    king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
    king_autoscaling_runtime.spend_usage_percent = -1;
    king_autoscaling_runtime.quota_usage_percent = -1;
    king_autoscaling_runtime.budget_probe_error[0] = '\0';
}

static void king_autoscaling_copy_runtime_config(
    kg_cloud_autoscale_config_t *target,
    const kg_cloud_autoscale_config_t *source)
{
    memset(target, 0, sizeof(*target));

    target->provider = king_autoscaling_strdup_persistent(source->provider);
    target->region = king_autoscaling_strdup_persistent(source->region);
    target->credentials_path = king_autoscaling_strdup_persistent(source->credentials_path);
    target->api_endpoint = king_autoscaling_strdup_persistent(source->api_endpoint);
    target->state_path = king_autoscaling_strdup_persistent(source->state_path);
    target->server_name_prefix = king_autoscaling_strdup_persistent(source->server_name_prefix);
    target->bootstrap_user_data = king_autoscaling_strdup_persistent(source->bootstrap_user_data);
    target->firewall_ids = king_autoscaling_strdup_persistent(source->firewall_ids);
    target->placement_group_id = king_autoscaling_strdup_persistent(source->placement_group_id);
    target->prepared_release_url = king_autoscaling_strdup_persistent(source->prepared_release_url);
    target->join_endpoint = king_autoscaling_strdup_persistent(source->join_endpoint);
    target->hetzner_api_token = king_autoscaling_strdup_persistent(source->hetzner_api_token);
    target->hetzner_budget_path = king_autoscaling_strdup_persistent(source->hetzner_budget_path);
    target->scale_up_policy = king_autoscaling_strdup_persistent(source->scale_up_policy);
    target->instance_type = king_autoscaling_strdup_persistent(source->instance_type);
    target->instance_image_id = king_autoscaling_strdup_persistent(source->instance_image_id);
    target->network_config = king_autoscaling_strdup_persistent(source->network_config);
    target->instance_tags = king_autoscaling_strdup_persistent(source->instance_tags);

    target->min_nodes = source->min_nodes;
    target->max_nodes = source->max_nodes;
    target->max_scale_step = source->max_scale_step;
    target->scale_up_cpu_threshold_percent = source->scale_up_cpu_threshold_percent;
    target->scale_down_cpu_threshold_percent = source->scale_down_cpu_threshold_percent;
    target->spend_warning_threshold_percent = source->spend_warning_threshold_percent;
    target->spend_hard_limit_percent = source->spend_hard_limit_percent;
    target->quota_warning_threshold_percent = source->quota_warning_threshold_percent;
    target->quota_hard_limit_percent = source->quota_hard_limit_percent;
    target->cooldown_period_sec = source->cooldown_period_sec;
    target->idle_node_timeout_sec = source->idle_node_timeout_sec;
}

static void king_autoscaling_clear_managed_nodes(void)
{
    if (king_autoscaling_runtime.managed_nodes != NULL) {
        pefree(king_autoscaling_runtime.managed_nodes, 1);
        king_autoscaling_runtime.managed_nodes = NULL;
    }

    king_autoscaling_runtime.managed_node_count = 0;
    king_autoscaling_runtime.managed_node_capacity = 0;
}

static int king_autoscaling_runtime_ensure_capacity(size_t needed)
{
    size_t next_capacity;
    king_autoscaling_managed_node_t *resized;

    if (needed <= king_autoscaling_runtime.managed_node_capacity) {
        return SUCCESS;
    }

    next_capacity = king_autoscaling_runtime.managed_node_capacity == 0
        ? 4
        : king_autoscaling_runtime.managed_node_capacity;

    while (next_capacity < needed) {
        next_capacity *= 2;
    }

    if (king_autoscaling_runtime.managed_nodes == NULL) {
        resized = pemalloc(next_capacity * sizeof(*resized), 1);
        memset(resized, 0, next_capacity * sizeof(*resized));
    } else {
        resized = perealloc(
            king_autoscaling_runtime.managed_nodes,
            next_capacity * sizeof(*resized),
            1
        );
        memset(
            resized + king_autoscaling_runtime.managed_node_capacity,
            0,
            (next_capacity - king_autoscaling_runtime.managed_node_capacity) * sizeof(*resized)
        );
    }

    king_autoscaling_runtime.managed_nodes = resized;
    king_autoscaling_runtime.managed_node_capacity = next_capacity;
    return SUCCESS;
}

static void king_autoscaling_normalize_runtime_limits(void)
{
    if (king_autoscaling_runtime.config.min_nodes <= 0) {
        king_autoscaling_runtime.config.min_nodes = 1;
    }

    if (king_autoscaling_runtime.config.max_nodes < king_autoscaling_runtime.config.min_nodes) {
        king_autoscaling_runtime.config.max_nodes = king_autoscaling_runtime.config.min_nodes;
    }

    if (king_autoscaling_runtime.config.max_scale_step <= 0) {
        king_autoscaling_runtime.config.max_scale_step = 1;
    }

    if (king_autoscaling_runtime.config.spend_warning_threshold_percent < 0) {
        king_autoscaling_runtime.config.spend_warning_threshold_percent = 0;
    }
    if (king_autoscaling_runtime.config.spend_warning_threshold_percent > 100) {
        king_autoscaling_runtime.config.spend_warning_threshold_percent = 100;
    }
    if (king_autoscaling_runtime.config.spend_hard_limit_percent < 0) {
        king_autoscaling_runtime.config.spend_hard_limit_percent = 100;
    }
    if (king_autoscaling_runtime.config.spend_hard_limit_percent > 100) {
        king_autoscaling_runtime.config.spend_hard_limit_percent = 100;
    }
    if (king_autoscaling_runtime.config.quota_warning_threshold_percent < 0) {
        king_autoscaling_runtime.config.quota_warning_threshold_percent = 0;
    }
    if (king_autoscaling_runtime.config.quota_warning_threshold_percent > 100) {
        king_autoscaling_runtime.config.quota_warning_threshold_percent = 100;
    }
    if (king_autoscaling_runtime.config.quota_hard_limit_percent < 0) {
        king_autoscaling_runtime.config.quota_hard_limit_percent = 100;
    }
    if (king_autoscaling_runtime.config.quota_hard_limit_percent > 100) {
        king_autoscaling_runtime.config.quota_hard_limit_percent = 100;
    }

    if (
        king_autoscaling_runtime.config.spend_warning_threshold_percent > 0
        && king_autoscaling_runtime.config.spend_hard_limit_percent < king_autoscaling_runtime.config.spend_warning_threshold_percent
    ) {
        king_autoscaling_runtime.config.spend_hard_limit_percent = king_autoscaling_runtime.config.spend_warning_threshold_percent;
    }

    if (
        king_autoscaling_runtime.config.quota_warning_threshold_percent > 0
        && king_autoscaling_runtime.config.quota_hard_limit_percent < king_autoscaling_runtime.config.quota_warning_threshold_percent
    ) {
        king_autoscaling_runtime.config.quota_hard_limit_percent = king_autoscaling_runtime.config.quota_warning_threshold_percent;
    }
}

static const char *king_autoscaling_budget_status_to_string(
    king_autoscaling_budget_status_t status)
{
    switch (status) {
        case KING_AUTOSCALING_BUDGET_STATUS_WARNING:
            return "warning";
        case KING_AUTOSCALING_BUDGET_STATUS_HARD_LIMIT:
            return "hard_limit";
        case KING_AUTOSCALING_BUDGET_STATUS_API_ERROR:
            return "api_error";
        case KING_AUTOSCALING_BUDGET_STATUS_OK:
            return "ok";
        case KING_AUTOSCALING_BUDGET_STATUS_DISABLED:
        default:
            return "disabled";
    }
}

static king_autoscaling_provider_kind_t king_autoscaling_detect_provider_kind(const char *provider)
{
    if (provider == NULL || provider[0] == '\0') {
        return KING_AUTOSCALING_PROVIDER_NONE;
    }

    if (strcasecmp(provider, "hetzner") == 0) {
        return KING_AUTOSCALING_PROVIDER_HETZNER;
    }

    return KING_AUTOSCALING_PROVIDER_SIMULATED;
}

static const char *king_autoscaling_get_provider_mode(void)
{
    switch (king_autoscaling_runtime.provider_kind) {
        case KING_AUTOSCALING_PROVIDER_HETZNER:
            return king_autoscaling_runtime.controller_token_configured
                ? "hetzner_active"
                : "hetzner_readonly";
        case KING_AUTOSCALING_PROVIDER_SIMULATED:
            return "simulated_provider";
        case KING_AUTOSCALING_PROVIDER_NONE:
        default:
            return "simulated_local";
    }
}

static const char *king_autoscaling_node_lifecycle_to_string(
    king_autoscaling_node_lifecycle_t lifecycle_state)
{
    switch (lifecycle_state) {
        case KING_AUTOSCALING_NODE_REGISTERED:
            return "registered";
        case KING_AUTOSCALING_NODE_READY:
            return "ready";
        case KING_AUTOSCALING_NODE_DRAINING:
            return "draining";
        case KING_AUTOSCALING_NODE_DELETED:
            return "deleted";
        case KING_AUTOSCALING_NODE_PROVISIONED:
        default:
            return "provisioned";
    }
}

static king_autoscaling_node_lifecycle_t king_autoscaling_node_lifecycle_from_string(
    const char *raw_value,
    zend_bool active,
    time_t deleted_at,
    time_t registered_at,
    time_t ready_at,
    time_t draining_at)
{
    if (raw_value != NULL && raw_value[0] != '\0') {
        if (strcasecmp(raw_value, "provisioned") == 0) {
            return KING_AUTOSCALING_NODE_PROVISIONED;
        }
        if (strcasecmp(raw_value, "registered") == 0) {
            return KING_AUTOSCALING_NODE_REGISTERED;
        }
        if (strcasecmp(raw_value, "ready") == 0) {
            return KING_AUTOSCALING_NODE_READY;
        }
        if (strcasecmp(raw_value, "draining") == 0) {
            return KING_AUTOSCALING_NODE_DRAINING;
        }
        if (strcasecmp(raw_value, "deleted") == 0) {
            return KING_AUTOSCALING_NODE_DELETED;
        }
    }

    if (deleted_at > 0) {
        return KING_AUTOSCALING_NODE_DELETED;
    }
    if (active || ready_at > 0) {
        return KING_AUTOSCALING_NODE_READY;
    }
    if (draining_at > 0) {
        return KING_AUTOSCALING_NODE_DRAINING;
    }
    if (registered_at > 0) {
        return KING_AUTOSCALING_NODE_REGISTERED;
    }

    return KING_AUTOSCALING_NODE_PROVISIONED;
}

static void king_autoscaling_runtime_normalize_node(king_autoscaling_managed_node_t *node)
{
    if (node == NULL) {
        return;
    }

    if (node->deleted_at > 0) {
        node->lifecycle_state = KING_AUTOSCALING_NODE_DELETED;
    }

    switch (node->lifecycle_state) {
        case KING_AUTOSCALING_NODE_READY:
            node->active = 1;
            if (node->registered_at == 0) {
                node->registered_at = node->created_at;
            }
            if (node->ready_at == 0) {
                node->ready_at = node->registered_at;
            }
            break;
        case KING_AUTOSCALING_NODE_REGISTERED:
            node->active = 0;
            if (node->registered_at == 0) {
                node->registered_at = node->created_at;
            }
            break;
        case KING_AUTOSCALING_NODE_DRAINING:
            node->active = 0;
            if (node->registered_at == 0) {
                node->registered_at = node->created_at;
            }
            if (node->ready_at == 0) {
                node->ready_at = node->registered_at;
            }
            if (node->draining_at == 0) {
                node->draining_at = node->ready_at;
            }
            break;
        case KING_AUTOSCALING_NODE_DELETED:
            node->active = 0;
            if (node->deleted_at == 0) {
                node->deleted_at = node->draining_at > 0
                    ? node->draining_at
                    : node->created_at;
            }
            break;
        case KING_AUTOSCALING_NODE_PROVISIONED:
        default:
            node->active = 0;
            break;
    }
}

static size_t king_autoscaling_runtime_count_nodes_in_state(
    king_autoscaling_node_lifecycle_t lifecycle_state)
{
    size_t index;
    size_t count = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        if (king_autoscaling_runtime.managed_nodes[index].lifecycle_state == lifecycle_state) {
            count++;
        }
    }

    return count;
}

static size_t king_autoscaling_runtime_count_pending_nodes(void)
{
    return king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_PROVISIONED)
        + king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_REGISTERED)
        + king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_DRAINING);
}

static time_t king_autoscaling_get_cooldown_remaining(time_t now)
{
    time_t last_action_at = 0;
    time_t elapsed;

    if (king_autoscaling_runtime.config.cooldown_period_sec <= 0) {
        return 0;
    }

    if (king_autoscaling_runtime.last_scale_up_at > last_action_at) {
        last_action_at = king_autoscaling_runtime.last_scale_up_at;
    }
    if (king_autoscaling_runtime.last_scale_down_at > last_action_at) {
        last_action_at = king_autoscaling_runtime.last_scale_down_at;
    }

    if (last_action_at == 0) {
        return 0;
    }

    if (now <= last_action_at) {
        return (time_t) king_autoscaling_runtime.config.cooldown_period_sec;
    }

    elapsed = now - last_action_at;
    if (elapsed >= king_autoscaling_runtime.config.cooldown_period_sec) {
        return 0;
    }

    return (time_t) (king_autoscaling_runtime.config.cooldown_period_sec - elapsed);
}

static uint32_t king_autoscaling_resolve_scale_up_step(void)
{
    const char *policy = king_autoscaling_runtime.config.scale_up_policy;
    zend_long desired = 1;

    if (policy != NULL && strncmp(policy, "add_nodes:", sizeof("add_nodes:") - 1) == 0) {
        const char *raw = policy + (sizeof("add_nodes:") - 1);
        if (raw[0] != '\0') {
            desired = ZEND_STRTOL(raw, NULL, 10);
        }
    }

    if (desired <= 0) {
        desired = 1;
    }
    if (desired > king_autoscaling_runtime.config.max_scale_step) {
        desired = king_autoscaling_runtime.config.max_scale_step;
    }

    return (uint32_t) desired;
}

static zend_bool king_autoscaling_lookup_signal_value(
    const char *const *metric_names,
    double *value_out,
    time_t *timestamp_out)
{
    size_t index;

    for (index = 0; metric_names[index] != NULL; index++) {
        if (king_telemetry_lookup_metric(metric_names[index], value_out, NULL, timestamp_out)) {
            return 1;
        }
    }

    return 0;
}

static void king_autoscaling_set_signal_source(
    const king_autoscaling_signal_snapshot_t *snapshot)
{
    const char *source = "none";

    if (snapshot->telemetry_signal_present && snapshot->system_signal_present) {
        source = "telemetry+system";
    } else if (snapshot->telemetry_signal_present) {
        source = "telemetry";
    } else if (snapshot->system_signal_present) {
        source = "system";
    }

    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_signal_source,
        sizeof(king_autoscaling_runtime.last_signal_source),
        source
    );
}

static int king_autoscaling_collect_live_signal_snapshot(
    king_autoscaling_signal_snapshot_t *snapshot)
{
    static const char *const cpu_metric_names[] = {
        "autoscaling.cpu_utilization",
        "system.cpu_utilization",
        "cpu_utilization",
        NULL
    };
    static const char *const memory_metric_names[] = {
        "autoscaling.memory_utilization",
        "system.memory_utilization",
        "memory_utilization",
        NULL
    };
    static const char *const connections_metric_names[] = {
        "autoscaling.active_connections",
        "system.active_connections",
        "active_connections",
        NULL
    };
    static const char *const requests_metric_names[] = {
        "autoscaling.requests_per_second",
        "system.requests_per_second",
        "requests_per_second",
        NULL
    };
    static const char *const response_time_metric_names[] = {
        "autoscaling.response_time_ms",
        "system.response_time_ms",
        "response_time_ms",
        NULL
    };
    static const char *const queue_metric_names[] = {
        "autoscaling.queue_depth",
        "system.queue_depth",
        "queue_depth",
        NULL
    };
    time_t now;
    double metric_value = 0.0;
    time_t metric_timestamp = 0;
    zend_long memory_limit = PG(memory_limit);

    if (snapshot == NULL) {
        return FAILURE;
    }

    memset(snapshot, 0, sizeof(*snapshot));
    now = time(NULL);
    snapshot->metrics.timestamp = now;

    if (king_autoscaling_lookup_signal_value(cpu_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.cpu_utilization_percent = metric_value;
        snapshot->cpu_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(memory_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.memory_utilization_percent = metric_value;
        snapshot->memory_live = 1;
        snapshot->telemetry_signal_present = 1;
    } else if (memory_limit > 0) {
        snapshot->metrics.memory_utilization_percent = ((double) zend_memory_usage(0) * 100.0)
            / (double) memory_limit;
        if (snapshot->metrics.memory_utilization_percent < 0.0) {
            snapshot->metrics.memory_utilization_percent = 0.0;
        }
        if (snapshot->metrics.memory_utilization_percent > 100.0) {
            snapshot->metrics.memory_utilization_percent = 100.0;
        }
        snapshot->memory_live = 1;
        snapshot->system_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(connections_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.active_connections = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->active_connections_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(requests_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.requests_per_second = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->requests_per_second_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(response_time_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.response_time_ms = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->response_time_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    if (king_autoscaling_lookup_signal_value(queue_metric_names, &metric_value, &metric_timestamp)) {
        snapshot->metrics.queue_depth = (uint64_t) (metric_value >= 0.0 ? metric_value : 0.0);
        snapshot->queue_depth_live = 1;
        snapshot->telemetry_signal_present = 1;
    }

    king_autoscaling_set_signal_source(snapshot);
    king_autoscaling_runtime.last_metrics = snapshot->metrics;
    return SUCCESS;
}

static int king_autoscaling_evaluate_scaling_decision_snapshot(
    const king_autoscaling_signal_snapshot_t *snapshot,
    char *reason_buffer,
    size_t reason_buffer_length)
{
    zend_bool up_trigger = 0;
    zend_bool down_ready = 0;
    zend_bool telemetry_present;
    uint64_t instance_divisor;

    if (snapshot == NULL) {
        return 0;
    }

    telemetry_present = snapshot->telemetry_signal_present;
    instance_divisor = king_current_instances > 0 ? (uint64_t) king_current_instances : 1;

    if (
        snapshot->cpu_live
        && snapshot->metrics.cpu_utilization_percent
            >= (double) king_autoscaling_runtime.config.scale_up_cpu_threshold_percent
    ) {
        up_trigger = 1;
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale up decision: cpu %.2f >= %ld.",
            snapshot->metrics.cpu_utilization_percent,
            (long) king_autoscaling_runtime.config.scale_up_cpu_threshold_percent
        );
    } else if (snapshot->queue_depth_live && snapshot->metrics.queue_depth >= 8) {
        up_trigger = 1;
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale up decision: queue depth %llu is saturated.",
            (unsigned long long) snapshot->metrics.queue_depth
        );
    } else if (snapshot->response_time_live && snapshot->metrics.response_time_ms >= 250) {
        up_trigger = 1;
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale up decision: response time %llu ms is saturated.",
            (unsigned long long) snapshot->metrics.response_time_ms
        );
    } else if (
        snapshot->requests_per_second_live
        && snapshot->metrics.requests_per_second >= (450 * instance_divisor)
    ) {
        up_trigger = 1;
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale up decision: requests_per_second %llu is above the per-instance budget.",
            (unsigned long long) snapshot->metrics.requests_per_second
        );
    } else if (
        snapshot->active_connections_live
        && snapshot->metrics.active_connections >= (900 * instance_divisor)
    ) {
        up_trigger = 1;
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale up decision: active_connections %llu is above the per-instance budget.",
            (unsigned long long) snapshot->metrics.active_connections
        );
    } else if (
        snapshot->memory_live
        && snapshot->metrics.memory_utilization_percent >= 90.0
    ) {
        up_trigger = 1;
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale up decision: memory %.2f%% is above the safety threshold.",
            snapshot->metrics.memory_utilization_percent
        );
    }

    if (up_trigger) {
        return 1;
    }

    if (!telemetry_present) {
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "No live telemetry-backed autoscaling signals are available for an automatic scaling decision."
        );
        return 0;
    }

    down_ready =
        (!snapshot->cpu_live
            || snapshot->metrics.cpu_utilization_percent
                <= (double) king_autoscaling_runtime.config.scale_down_cpu_threshold_percent)
        && (!snapshot->queue_depth_live || snapshot->metrics.queue_depth <= 1)
        && (!snapshot->response_time_live || snapshot->metrics.response_time_ms <= 50)
        && (!snapshot->requests_per_second_live
            || snapshot->metrics.requests_per_second <= (90 * instance_divisor))
        && (!snapshot->active_connections_live
            || snapshot->metrics.active_connections <= (120 * instance_divisor))
        && (!snapshot->memory_live || snapshot->metrics.memory_utilization_percent <= 70.0);

    if (down_ready) {
        snprintf(
            reason_buffer,
            reason_buffer_length,
            "Live telemetry and system signals triggered a scale down decision: load is below the configured hysteresis window."
        );
        return -1;
    }

    snprintf(
        reason_buffer,
        reason_buffer_length,
        "Live signals remain inside the autoscaling hysteresis window."
    );
    return 0;
}

static zend_bool king_autoscaling_drain_ready_node_internal(
    king_autoscaling_managed_node_t *node,
    const char *decision_reason)
{
    size_t active_nodes;
    size_t minimum_managed;

    if (node == NULL || node->lifecycle_state != KING_AUTOSCALING_NODE_READY) {
        return 0;
    }

    active_nodes = king_autoscaling_runtime_count_active_nodes();
    minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
        ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
        : 0;
    if (active_nodes <= minimum_managed) {
        return 0;
    }

    node->lifecycle_state = KING_AUTOSCALING_NODE_DRAINING;
    node->draining_at = time(NULL);
    snprintf(node->provider_status, sizeof(node->provider_status), "%s", "draining");
    king_autoscaling_runtime_normalize_node(node);
    king_autoscaling_runtime.last_scale_down_at = node->draining_at;
    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_decision_reason,
        sizeof(king_autoscaling_runtime.last_decision_reason),
        decision_reason
    );

    return king_autoscaling_finalize_node_update("drain_node");
}

static zend_bool king_autoscaling_monitor_tick(void)
{
    king_autoscaling_signal_snapshot_t snapshot;
    char reason_buffer[256];
    int decision;
    int rollback_result;
    time_t now;
    time_t cooldown_remaining;

    if (!king_autoscaling_runtime.initialized) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Autoscaling runtime is not initialized."
        );
        return 0;
    }

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );
    king_autoscaling_reset_message(reason_buffer, sizeof(reason_buffer));

    now = time(NULL);
    king_autoscaling_runtime.last_monitor_tick_at = now;

    rollback_result = king_autoscaling_provider_rollback_stale_pending_node(now);
    if (rollback_result < 0) {
        return 0;
    }
    if (rollback_result > 0) {
        return 1;
    }

    if (king_autoscaling_collect_live_signal_snapshot(&snapshot) != SUCCESS) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Failed to collect autoscaling telemetry and system signals."
        );
        return 0;
    }

    now = snapshot.metrics.timestamp;
    king_autoscaling_runtime.last_monitor_tick_at = now;
    decision = king_autoscaling_evaluate_scaling_decision_snapshot(
        &snapshot,
        reason_buffer,
        sizeof(reason_buffer)
    );
    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_decision_reason,
        sizeof(king_autoscaling_runtime.last_decision_reason),
        reason_buffer
    );

    cooldown_remaining = king_autoscaling_get_cooldown_remaining(now);
    if (decision != 0 && cooldown_remaining > 0) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Autoscaling controller is in cooldown for %ld more second(s).",
            (long) cooldown_remaining
        );
        king_autoscaling_set_runtime_string(
            king_autoscaling_runtime.last_action_kind,
            sizeof(king_autoscaling_runtime.last_action_kind),
            "monitor_tick"
        );
        return 1;
    }

    if (decision > 0) {
        uint32_t scale_step = king_autoscaling_resolve_scale_up_step();

        if (
            king_autoscaling_runtime.provider_kind == KING_AUTOSCALING_PROVIDER_HETZNER
            && king_autoscaling_runtime_count_pending_nodes() > 0
        ) {
            snprintf(
                king_autoscaling_runtime.last_warning,
                sizeof(king_autoscaling_runtime.last_warning),
                "Pending Hetzner nodes must finish register/ready/drain lifecycle before another automatic scale-up."
            );
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_decision_reason,
                sizeof(king_autoscaling_runtime.last_decision_reason),
                "Pending Hetzner nodes must finish register/ready/drain lifecycle before another automatic scale-up."
            );
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_action_kind,
                sizeof(king_autoscaling_runtime.last_action_kind),
                "monitor_tick"
            );
            return 1;
        }

        if (scale_step == 0) {
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_action_kind,
                sizeof(king_autoscaling_runtime.last_action_kind),
                "monitor_tick"
            );
            return 1;
        }

        return king_autoscaling_provider_scale_up(scale_step) == SUCCESS;
    }

    if (decision < 0) {
        if (king_autoscaling_runtime.provider_kind == KING_AUTOSCALING_PROVIDER_HETZNER) {
            if (king_autoscaling_runtime_count_pending_nodes() > 0) {
                return king_autoscaling_provider_scale_down(1) == SUCCESS;
            }

            if (king_autoscaling_drain_ready_node_internal(
                king_autoscaling_runtime_pick_active_node(),
                "Live telemetry and system signals triggered a scale down decision: drained one Hetzner node before provider removal."
            )) {
                return 1;
            }

            snprintf(
                king_autoscaling_runtime.last_warning,
                sizeof(king_autoscaling_runtime.last_warning),
                "Automatic Hetzner scale-down reached the configured floor and could not drain another node."
            );
            king_autoscaling_set_runtime_string(
                king_autoscaling_runtime.last_action_kind,
                sizeof(king_autoscaling_runtime.last_action_kind),
                "monitor_tick"
            );
            return 1;
        }

        return king_autoscaling_provider_scale_down(1) == SUCCESS;
    }

    king_autoscaling_set_runtime_string(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "monitor_tick"
    );
    return 1;
}

int king_autoscaling_runtime_append_node(
    zend_long server_id,
    const char *name,
    const char *provider_status,
    time_t created_at,
    zend_bool active)
{
    king_autoscaling_managed_node_t *node;

    if (king_autoscaling_runtime_ensure_capacity(king_autoscaling_runtime.managed_node_count + 1) != SUCCESS) {
        return FAILURE;
    }

    node = &king_autoscaling_runtime.managed_nodes[king_autoscaling_runtime.managed_node_count++];
    memset(node, 0, sizeof(*node));

    node->server_id = server_id;
    node->created_at = created_at;
    node->active = active ? 1 : 0;
    node->lifecycle_state = active
        ? KING_AUTOSCALING_NODE_READY
        : KING_AUTOSCALING_NODE_PROVISIONED;
    node->registered_at = active ? created_at : 0;
    node->ready_at = active ? created_at : 0;
    node->draining_at = 0;
    node->deleted_at = 0;

    snprintf(node->name, sizeof(node->name), "%s", name != NULL ? name : "");
    snprintf(
        node->provider_status,
        sizeof(node->provider_status),
        "%s",
        provider_status != NULL ? provider_status : ""
    );

    return SUCCESS;
}

king_autoscaling_managed_node_t *king_autoscaling_runtime_find_node(zend_long server_id)
{
    size_t index;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];
        if (node->server_id == server_id) {
            return node;
        }
    }

    return NULL;
}

king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_active_node(void)
{
    size_t index = king_autoscaling_runtime.managed_node_count;

    while (index > 0) {
        king_autoscaling_managed_node_t *node;

        index--;
        node = &king_autoscaling_runtime.managed_nodes[index];
        if (node->active) {
            return node;
        }
    }

    return NULL;
}

king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_draining_node(void)
{
    size_t index = king_autoscaling_runtime.managed_node_count;

    while (index > 0) {
        king_autoscaling_managed_node_t *node;

        index--;
        node = &king_autoscaling_runtime.managed_nodes[index];
        if (
            node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING
            && node->deleted_at == 0
        ) {
            return node;
        }
    }

    return NULL;
}

void king_autoscaling_runtime_reset(void)
{
    king_autoscaling_clear_managed_nodes();
    king_autoscaling_free_runtime_config(&king_autoscaling_runtime.config);

    king_autoscaling_runtime.provider_kind = KING_AUTOSCALING_PROVIDER_NONE;
    king_autoscaling_runtime.initialized = 0;
    king_autoscaling_runtime.monitoring_active = 0;
    king_autoscaling_runtime.controller_token_configured = 0;
    king_autoscaling_runtime.action_count = 0;
    king_autoscaling_runtime.last_scale_up_at = 0;
    king_autoscaling_runtime.last_scale_down_at = 0;
    king_autoscaling_runtime.last_monitor_tick_at = 0;
    memset(&king_autoscaling_runtime.last_metrics, 0, sizeof(king_autoscaling_runtime.last_metrics));
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_signal_source,
        sizeof(king_autoscaling_runtime.last_signal_source)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_decision_reason,
        sizeof(king_autoscaling_runtime.last_decision_reason)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );
    king_autoscaling_reset_budget_runtime_state();

    king_current_instances = 1;
}

size_t king_autoscaling_runtime_count_active_nodes(void)
{
    size_t index;
    size_t active_nodes = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        if (king_autoscaling_runtime.managed_nodes[index].active) {
            active_nodes++;
        }
    }

    return active_nodes;
}

void king_autoscaling_runtime_sync_instance_count(void)
{
    king_current_instances = (uint32_t) (1 + king_autoscaling_runtime_count_active_nodes());
}

int king_autoscaling_runtime_load_state(void)
{
    FILE *stream;
    char line[512];
    const char *state_path = king_autoscaling_runtime.config.state_path;

    if (state_path == NULL || state_path[0] == '\0') {
        return SUCCESS;
    }

    stream = fopen(state_path, "rb");
    if (stream == NULL) {
        if (errno == ENOENT) {
            return SUCCESS;
        }

        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to open autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    king_autoscaling_clear_managed_nodes();

    while (fgets(line, sizeof(line), stream) != NULL) {
        char *saveptr = NULL;
        char *kind;
        char *fields[10] = {0};
        size_t field_count = 0;
        king_autoscaling_managed_node_t *node;
        zend_long server_id;
        zend_bool active;
        time_t created_at;
        time_t registered_at = 0;
        time_t ready_at = 0;
        time_t draining_at = 0;
        time_t deleted_at = 0;
        const char *lifecycle_raw = NULL;
        const char *status_raw = NULL;
        const char *name_raw = NULL;

        if (line[0] == '\n' || line[0] == '\r' || line[0] == '#') {
            continue;
        }

        kind = strtok_r(line, "\t\r\n", &saveptr);
        if (kind == NULL || strcmp(kind, "node") != 0) {
            continue;
        }

        while (field_count < 10 && (fields[field_count] = strtok_r(NULL, "\t\r\n", &saveptr)) != NULL) {
            field_count++;
        }

        if (field_count != 6 && field_count != 10) {
            continue;
        }

        server_id = ZEND_STRTOL(fields[0], NULL, 10);
        active = ZEND_STRTOL(fields[1], NULL, 10) > 0;
        created_at = (time_t) ZEND_STRTOL(fields[2], NULL, 10);

        if (field_count == 6) {
            deleted_at = (time_t) ZEND_STRTOL(fields[3], NULL, 10);
            status_raw = fields[4];
            name_raw = fields[5];
        } else {
            registered_at = (time_t) ZEND_STRTOL(fields[3], NULL, 10);
            ready_at = (time_t) ZEND_STRTOL(fields[4], NULL, 10);
            draining_at = (time_t) ZEND_STRTOL(fields[5], NULL, 10);
            deleted_at = (time_t) ZEND_STRTOL(fields[6], NULL, 10);
            lifecycle_raw = fields[7];
            status_raw = fields[8];
            name_raw = fields[9];
        }

        if (king_autoscaling_runtime_append_node(
            server_id,
            name_raw,
            status_raw,
            created_at,
            active
        ) != SUCCESS) {
            fclose(stream);
            return FAILURE;
        }

        node = &king_autoscaling_runtime.managed_nodes[king_autoscaling_runtime.managed_node_count - 1];
        node->registered_at = registered_at;
        node->ready_at = ready_at;
        node->draining_at = draining_at;
        node->deleted_at = deleted_at;
        node->lifecycle_state = king_autoscaling_node_lifecycle_from_string(
            lifecycle_raw,
            active,
            deleted_at,
            registered_at,
            ready_at,
            draining_at
        );
        king_autoscaling_runtime_normalize_node(node);
    }

    fclose(stream);
    king_autoscaling_runtime_sync_instance_count();
    return SUCCESS;
}

int king_autoscaling_runtime_persist_state(void)
{
    char tmp_path[512];
    FILE *stream;
    size_t index;
    const char *state_path = king_autoscaling_runtime.config.state_path;

    if (state_path == NULL || state_path[0] == '\0') {
        return SUCCESS;
    }

    snprintf(tmp_path, sizeof(tmp_path), "%s.tmp.%ld", state_path, (long) getpid());
    stream = fopen(tmp_path, "wb");
    if (stream == NULL) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to write autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    fprintf(stream, "version\t%d\n", KING_AUTOSCALING_STATE_VERSION);
    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];
        fprintf(
            stream,
            "node\t%ld\t%d\t%ld\t%ld\t%ld\t%ld\t%ld\t%s\t%s\t%s\n",
            (long) node->server_id,
            node->active ? 1 : 0,
            (long) node->created_at,
            (long) node->registered_at,
            (long) node->ready_at,
            (long) node->draining_at,
            (long) node->deleted_at,
            king_autoscaling_node_lifecycle_to_string(node->lifecycle_state),
            node->provider_status,
            node->name
        );
    }

    if (fclose(stream) != 0) {
        unlink(tmp_path);
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to finalize autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    if (rename(tmp_path, state_path) != 0) {
        unlink(tmp_path);
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Failed to replace autoscaling state '%s': %s",
            state_path,
            strerror(errno)
        );
        return FAILURE;
    }

    return SUCCESS;
}

int king_autoscaling_init_system(const kg_cloud_autoscale_config_t *config)
{
    king_autoscaling_runtime_reset();

    if (config == NULL) {
        return FAILURE;
    }

    king_autoscaling_copy_runtime_config(&king_autoscaling_runtime.config, config);
    king_autoscaling_normalize_runtime_limits();
    king_autoscaling_runtime.provider_kind = king_autoscaling_detect_provider_kind(
        king_autoscaling_runtime.config.provider
    );
    king_autoscaling_runtime.controller_token_configured =
        king_autoscaling_runtime.config.hetzner_api_token != NULL
        && king_autoscaling_runtime.config.hetzner_api_token[0] != '\0';
    king_autoscaling_runtime.initialized = 1;
    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "init"
    );

    if (
        king_autoscaling_runtime.provider_kind == KING_AUTOSCALING_PROVIDER_HETZNER
        && (
            king_autoscaling_runtime.config.state_path == NULL
            || king_autoscaling_runtime.config.state_path[0] == '\0'
        )
    ) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Hetzner controller mode is active without a state_path; restart recovery is disabled."
        );
    }

    king_autoscaling_runtime_load_state();
    king_autoscaling_runtime_sync_instance_count();
    return SUCCESS;
}

void king_autoscaling_shutdown_system(void)
{
    king_autoscaling_runtime_reset();
}

int king_autoscaling_collect_metrics(king_load_metrics_t *metrics)
{
    king_autoscaling_signal_snapshot_t snapshot;

    if (metrics == NULL) {
        return FAILURE;
    }

    if (king_autoscaling_collect_live_signal_snapshot(&snapshot) != SUCCESS) {
        return FAILURE;
    }

    *metrics = snapshot.metrics;
    return SUCCESS;
}

int king_autoscaling_evaluate_scaling_decision(const king_load_metrics_t *metrics)
{
    if (metrics == NULL) {
        return 0;
    }

    if (
        metrics->cpu_utilization_percent
        > (double) king_autoscaling_runtime.config.scale_up_cpu_threshold_percent
    ) {
        return 1;
    }

    if (
        metrics->cpu_utilization_percent
        < (double) king_autoscaling_runtime.config.scale_down_cpu_threshold_percent
    ) {
        return -1;
    }

    return 0;
}

PHP_FUNCTION(king_autoscaling_init)
{
    zval *config_arr;
    kg_cloud_autoscale_config_t runtime_config;

    if (zend_parse_parameters(1, "a", &config_arr) == FAILURE) {
        RETURN_FALSE;
    }

    if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config_arr)) > 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        RETURN_THROWS();
    }

    king_autoscaling_copy_runtime_config(&runtime_config, &king_cloud_autoscale_config);

    if (
        zend_hash_num_elements(Z_ARRVAL_P(config_arr)) > 0
        && kg_config_cloud_autoscale_apply_userland_config_to(&runtime_config, config_arr) != SUCCESS
    ) {
        king_autoscaling_free_runtime_config(&runtime_config);
        RETURN_FALSE;
    }

    if (king_autoscaling_init_system(&runtime_config) != SUCCESS) {
        king_autoscaling_free_runtime_config(&runtime_config);
        RETURN_FALSE;
    }

    king_autoscaling_free_runtime_config(&runtime_config);
    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_start_monitoring)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_autoscaling_runtime.monitoring_active = 1;
    if (!king_autoscaling_monitor_tick()) {
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_stop_monitoring)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_autoscaling_runtime.monitoring_active = 0;
    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_get_metrics)
{
    king_load_metrics_t metrics;

    ZEND_PARSE_PARAMETERS_NONE();

    if (king_autoscaling_collect_metrics(&metrics) != SUCCESS) {
        RETURN_FALSE;
    }

    array_init(return_value);
    add_assoc_double(return_value, "cpu_utilization", metrics.cpu_utilization_percent);
    add_assoc_double(return_value, "memory_utilization", metrics.memory_utilization_percent);
    add_assoc_long(return_value, "active_connections", (zend_long) metrics.active_connections);
    add_assoc_long(return_value, "requests_per_second", (zend_long) metrics.requests_per_second);
    add_assoc_long(return_value, "response_time_ms", (zend_long) metrics.response_time_ms);
    add_assoc_long(return_value, "queue_depth", (zend_long) metrics.queue_depth);
    add_assoc_long(return_value, "timestamp", (zend_long) metrics.timestamp);
}

PHP_FUNCTION(king_autoscaling_get_status)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    add_assoc_bool(return_value, "initialized", king_autoscaling_runtime.initialized);
    add_assoc_bool(return_value, "monitoring_active", king_autoscaling_runtime.monitoring_active);
    add_assoc_long(return_value, "current_instances", (zend_long) king_current_instances);
    add_assoc_string(
        return_value,
        "provider",
        king_autoscaling_runtime.config.provider != NULL
            ? king_autoscaling_runtime.config.provider
            : ""
    );
    add_assoc_string(return_value, "provider_mode", (char *) king_autoscaling_get_provider_mode());
    add_assoc_bool(
        return_value,
        "controller_token_configured",
        king_autoscaling_runtime.controller_token_configured
    );
    add_assoc_long(
        return_value,
        "managed_nodes",
        (zend_long) king_autoscaling_runtime.managed_node_count
    );
    add_assoc_long(
        return_value,
        "active_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_active_nodes()
    );
    add_assoc_long(
        return_value,
        "provisioned_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_PROVISIONED)
    );
    add_assoc_long(
        return_value,
        "registered_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_REGISTERED)
    );
    add_assoc_long(
        return_value,
        "draining_managed_nodes",
        (zend_long) king_autoscaling_runtime_count_nodes_in_state(KING_AUTOSCALING_NODE_DRAINING)
    );
    add_assoc_long(
        return_value,
        "cooldown_remaining_sec",
        (zend_long) king_autoscaling_get_cooldown_remaining(time(NULL))
    );
    add_assoc_long(
        return_value,
        "last_monitor_tick_at",
        (zend_long) king_autoscaling_runtime.last_monitor_tick_at
    );
    add_assoc_long(return_value, "action_count", (zend_long) king_autoscaling_runtime.action_count);
    add_assoc_string(
        return_value,
        "api_endpoint",
        king_autoscaling_runtime.config.api_endpoint != NULL
            ? king_autoscaling_runtime.config.api_endpoint
            : ""
    );
    add_assoc_string(
        return_value,
        "state_path",
        king_autoscaling_runtime.config.state_path != NULL
            ? king_autoscaling_runtime.config.state_path
            : ""
    );
    add_assoc_string(return_value, "last_action_kind", king_autoscaling_runtime.last_action_kind);
    add_assoc_string(return_value, "last_signal_source", king_autoscaling_runtime.last_signal_source);
    add_assoc_string(return_value, "last_decision_reason", king_autoscaling_runtime.last_decision_reason);
    add_assoc_string(return_value, "last_error", king_autoscaling_runtime.last_error);
    add_assoc_string(return_value, "last_warning", king_autoscaling_runtime.last_warning);
    add_assoc_string(
        return_value,
        "hetzner_budget_path",
        king_autoscaling_runtime.config.hetzner_budget_path != NULL
            ? king_autoscaling_runtime.config.hetzner_budget_path
            : ""
    );
    add_assoc_long(
        return_value,
        "spend_warning_threshold_percent",
        king_autoscaling_runtime.config.spend_warning_threshold_percent
    );
    add_assoc_long(
        return_value,
        "spend_hard_limit_percent",
        king_autoscaling_runtime.config.spend_hard_limit_percent
    );
    add_assoc_long(
        return_value,
        "quota_warning_threshold_percent",
        king_autoscaling_runtime.config.quota_warning_threshold_percent
    );
    add_assoc_long(
        return_value,
        "quota_hard_limit_percent",
        king_autoscaling_runtime.config.quota_hard_limit_percent
    );
    add_assoc_long(
        return_value,
        "spend_usage_percent",
        king_autoscaling_runtime.spend_usage_percent
    );
    add_assoc_long(
        return_value,
        "quota_usage_percent",
        king_autoscaling_runtime.quota_usage_percent
    );
    add_assoc_string(
        return_value,
        "spend_status",
        (char *) king_autoscaling_budget_status_to_string(king_autoscaling_runtime.spend_status)
    );
    add_assoc_string(
        return_value,
        "quota_status",
        (char *) king_autoscaling_budget_status_to_string(king_autoscaling_runtime.quota_status)
    );
    add_assoc_string(return_value, "budget_probe_error", king_autoscaling_runtime.budget_probe_error);
}

static zend_bool king_autoscaling_finalize_node_update(const char *action_kind)
{
    king_autoscaling_runtime_sync_instance_count();
    if (king_autoscaling_runtime_persist_state() != SUCCESS) {
        return 0;
    }

    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "%s",
        action_kind
    );
    return 1;
}

PHP_FUNCTION(king_autoscaling_get_nodes)
{
    size_t index;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        zval node_entry;
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];

        array_init(&node_entry);
        add_assoc_long(&node_entry, "server_id", node->server_id);
        add_assoc_string(&node_entry, "name", node->name);
        add_assoc_string(&node_entry, "provider_status", node->provider_status);
        add_assoc_string(
            &node_entry,
            "lifecycle",
            (char *) king_autoscaling_node_lifecycle_to_string(node->lifecycle_state)
        );
        add_assoc_bool(&node_entry, "active", node->active);
        add_assoc_long(&node_entry, "created_at", (zend_long) node->created_at);
        add_assoc_long(&node_entry, "registered_at", (zend_long) node->registered_at);
        add_assoc_long(&node_entry, "ready_at", (zend_long) node->ready_at);
        add_assoc_long(&node_entry, "draining_at", (zend_long) node->draining_at);
        add_assoc_long(&node_entry, "deleted_at", (zend_long) node->deleted_at);
        add_next_index_zval(return_value, &node_entry);
    }
}

PHP_FUNCTION(king_autoscaling_register_node)
{
    zend_long server_id;
    char *name = NULL;
    size_t name_length = 0;
    king_autoscaling_managed_node_t *node;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_LONG(server_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING_OR_NULL(name, name_length)
    ZEND_PARSE_PARAMETERS_END();

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );

    node = king_autoscaling_runtime_find_node(server_id);
    if (node == NULL) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is unknown to the autoscaling controller.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is already deleted.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    if (name != NULL && name_length > 0) {
        snprintf(node->name, sizeof(node->name), "%s", name);
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_PROVISIONED) {
        node->lifecycle_state = KING_AUTOSCALING_NODE_REGISTERED;
        node->registered_at = time(NULL);
        snprintf(node->provider_status, sizeof(node->provider_status), "%s", "registered");
    } else if (node->registered_at == 0) {
        node->registered_at = time(NULL);
    }

    king_autoscaling_runtime_normalize_node(node);
    if (!king_autoscaling_finalize_node_update("register_node")) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_mark_node_ready)
{
    zend_long server_id;
    king_autoscaling_managed_node_t *node;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(server_id)
    ZEND_PARSE_PARAMETERS_END();

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );

    node = king_autoscaling_runtime_find_node(server_id);
    if (node == NULL) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is unknown to the autoscaling controller.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    switch (node->lifecycle_state) {
        case KING_AUTOSCALING_NODE_READY:
            break;
        case KING_AUTOSCALING_NODE_REGISTERED:
            node->lifecycle_state = KING_AUTOSCALING_NODE_READY;
            node->ready_at = time(NULL);
            snprintf(node->provider_status, sizeof(node->provider_status), "%s", "ready");
            break;
        case KING_AUTOSCALING_NODE_PROVISIONED:
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Managed node %ld must register before it can become ready.",
                (long) server_id
            );
            RETURN_FALSE;
        case KING_AUTOSCALING_NODE_DRAINING:
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Managed node %ld is draining and cannot be re-admitted directly.",
                (long) server_id
            );
            RETURN_FALSE;
        case KING_AUTOSCALING_NODE_DELETED:
        default:
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Managed node %ld is already deleted.",
                (long) server_id
            );
            RETURN_FALSE;
    }

    king_autoscaling_runtime_normalize_node(node);
    if (!king_autoscaling_finalize_node_update("mark_node_ready")) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_autoscaling_drain_node)
{
    zend_long server_id;
    size_t active_nodes;
    size_t minimum_managed;
    king_autoscaling_managed_node_t *node;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(server_id)
    ZEND_PARSE_PARAMETERS_END();

    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error)
    );
    king_autoscaling_reset_message(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning)
    );

    node = king_autoscaling_runtime_find_node(server_id);
    if (node == NULL) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is unknown to the autoscaling controller.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING) {
        RETURN_TRUE;
    }

    if (node->lifecycle_state != KING_AUTOSCALING_NODE_READY) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld is not ready and cannot be drained.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    active_nodes = king_autoscaling_runtime_count_active_nodes();
    minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
        ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
        : 0;
    if (active_nodes <= minimum_managed) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Managed node %ld cannot be drained because the cluster is already at its configured floor.",
            (long) server_id
        );
        RETURN_FALSE;
    }

    node->lifecycle_state = KING_AUTOSCALING_NODE_DRAINING;
    node->draining_at = time(NULL);
    snprintf(node->provider_status, sizeof(node->provider_status), "%s", "draining");
    king_autoscaling_runtime_normalize_node(node);
    king_autoscaling_runtime.last_scale_down_at = node->draining_at;

    if (!king_autoscaling_finalize_node_update("drain_node")) {
        RETURN_FALSE;
    }

    RETURN_TRUE;
}
