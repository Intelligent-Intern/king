/*
 * =========================================================================
 * FILENAME:   include/config/cloud_autoscale/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for cloud autoscaling.
 *
 * ARCHITECTURE:
 * This struct stores provider, scaling, and provisioning settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_CLOUD_AUTOSCALE_BASE_H
#define KING_CONFIG_CLOUD_AUTOSCALE_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_cloud_autoscale_config_t {
    /* --- Provider & Credentials --- */
    char *provider;
    char *region;
    char *credentials_path;
    char *api_endpoint;
    char *state_path;
    char *server_name_prefix;
    char *bootstrap_user_data;
    char *firewall_ids;
    char *placement_group_id;
    char *prepared_release_url;
    char *join_endpoint;
    char *hetzner_api_token;
    char *hetzner_budget_path;

    /* --- Scaling Triggers & Policy --- */
    zend_long min_nodes;
    zend_long max_nodes;
    zend_long max_scale_step;
    zend_long scale_up_cpu_threshold_percent;
    zend_long scale_down_cpu_threshold_percent;
    char *scale_up_policy;
    zend_long cooldown_period_sec;
    zend_long idle_node_timeout_sec;

    /* --- Budget / Quota Watch --- */
    zend_long spend_warning_threshold_percent;
    zend_long spend_hard_limit_percent;
    zend_long quota_warning_threshold_percent;
    zend_long quota_hard_limit_percent;

    /* --- Node Provisioning --- */
    char *instance_type;
    char *instance_image_id;
    char *network_config;
    char *instance_tags;

} kg_cloud_autoscale_config_t;

/* Module-global configuration instance. */
extern kg_cloud_autoscale_config_t king_cloud_autoscale_config;

#endif /* KING_CONFIG_CLOUD_AUTOSCALE_BASE_H */
