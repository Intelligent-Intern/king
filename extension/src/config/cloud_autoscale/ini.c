/*
 * =========================================================================
 * FILENAME:   src/config/cloud_autoscale/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the cloud-autoscale config
 * family. This file exposes the system-level provider, threshold, budget,
 * cooldown, and instance-shape directives and keeps the shared
 * `king_cloud_autoscale_config` snapshot aligned with validated updates.
 * =========================================================================
 */

#include "include/config/cloud_autoscale/ini.h"
#include "include/config/cloud_autoscale/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <zend_ini.h>
#include <strings.h>

static ZEND_INI_MH(OnUpdateAutoscalePositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an autoscale directive. A positive integer is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_min_nodes")) {
        king_cloud_autoscale_config.min_nodes = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_max_nodes")) {
        king_cloud_autoscale_config.max_nodes = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_max_scale_step")) {
        king_cloud_autoscale_config.max_scale_step = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_cooldown_period_sec")) {
        king_cloud_autoscale_config.cooldown_period_sec = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_idle_node_timeout_sec")) {
        king_cloud_autoscale_config.idle_node_timeout_sec = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateAutoscalePercent)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val < 0 || val > 100) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an autoscale threshold directive. An integer between 0 and 100 is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_scale_up_cpu_threshold_percent")) {
        king_cloud_autoscale_config.scale_up_cpu_threshold_percent = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_scale_down_cpu_threshold_percent")) {
        king_cloud_autoscale_config.scale_down_cpu_threshold_percent = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateAutoscaleOptionalPercent)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val < 0 || val > 100) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an autoscale threshold directive. An integer between 0 and 100 is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_spend_warning_threshold_percent")) {
        king_cloud_autoscale_config.spend_warning_threshold_percent = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_spend_hard_limit_percent")) {
        king_cloud_autoscale_config.spend_hard_limit_percent = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_quota_warning_threshold_percent")) {
        king_cloud_autoscale_config.quota_warning_threshold_percent = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_autoscale_quota_hard_limit_percent")) {
        king_cloud_autoscale_config.quota_hard_limit_percent = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateAutoscaleProvider)
{
    /* Empty string keeps the provider unset, which is the default. */
    const char *const allowed[] = {"aws", "azure", "hetzner", "google_cloud", "digitalocean", "", NULL};
    bool is_allowed = false;

    for (int i = 0; allowed[i] != NULL; i++) {
        if (strcasecmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            is_allowed = true;
            break;
        }
    }

    if (!is_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for autoscale provider. Unsupported provider specified.");
        return FAILURE;
    }

    if (king_cloud_autoscale_config.provider) {
        pefree(king_cloud_autoscale_config.provider, 1);
    }
    king_cloud_autoscale_config.provider = pestrdup(ZSTR_VAL(new_value), 1);
    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_provider", "", PHP_INI_SYSTEM, OnUpdateAutoscaleProvider, NULL)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_region", "", PHP_INI_SYSTEM, OnUpdateString, region, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_credentials_path", "", PHP_INI_SYSTEM, OnUpdateString, credentials_path, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_api_endpoint", "https://api.hetzner.cloud/v1", PHP_INI_SYSTEM, OnUpdateString, api_endpoint, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_state_path", "", PHP_INI_SYSTEM, OnUpdateString, state_path, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_server_name_prefix", "king-node", PHP_INI_SYSTEM, OnUpdateString, server_name_prefix, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_bootstrap_user_data", "", PHP_INI_SYSTEM, OnUpdateString, bootstrap_user_data, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_firewall_ids", "", PHP_INI_SYSTEM, OnUpdateString, firewall_ids, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_placement_group_id", "", PHP_INI_SYSTEM, OnUpdateString, placement_group_id, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_prepared_release_url", "", PHP_INI_SYSTEM, OnUpdateString, prepared_release_url, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_join_endpoint", "", PHP_INI_SYSTEM, OnUpdateString, join_endpoint, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_hetzner_api_token", "", PHP_INI_SYSTEM, OnUpdateString, hetzner_api_token, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_hetzner_budget_path", "", PHP_INI_SYSTEM, OnUpdateString, hetzner_budget_path, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)

    ZEND_INI_ENTRY_EX("king.cluster_autoscale_min_nodes", "1", PHP_INI_SYSTEM, OnUpdateAutoscalePositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_max_nodes", "10", PHP_INI_SYSTEM, OnUpdateAutoscalePositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_max_scale_step", "1", PHP_INI_SYSTEM, OnUpdateAutoscalePositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_scale_up_cpu_threshold_percent", "80", PHP_INI_SYSTEM, OnUpdateAutoscalePercent, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_scale_down_cpu_threshold_percent", "20", PHP_INI_SYSTEM, OnUpdateAutoscalePercent, NULL)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_scale_up_policy", "add_nodes:1", PHP_INI_SYSTEM, OnUpdateString, scale_up_policy, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_spend_warning_threshold_percent", "80", PHP_INI_SYSTEM, OnUpdateAutoscaleOptionalPercent, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_spend_hard_limit_percent", "95", PHP_INI_SYSTEM, OnUpdateAutoscaleOptionalPercent, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_quota_warning_threshold_percent", "80", PHP_INI_SYSTEM, OnUpdateAutoscaleOptionalPercent, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_quota_hard_limit_percent", "95", PHP_INI_SYSTEM, OnUpdateAutoscaleOptionalPercent, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_cooldown_period_sec", "300", PHP_INI_SYSTEM, OnUpdateAutoscalePositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_autoscale_idle_node_timeout_sec", "600", PHP_INI_SYSTEM, OnUpdateAutoscalePositiveLong, NULL)

    STD_PHP_INI_ENTRY("king.cluster_autoscale_instance_type", "", PHP_INI_SYSTEM, OnUpdateString, instance_type, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_instance_image_id", "", PHP_INI_SYSTEM, OnUpdateString, instance_image_id, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_network_config", "", PHP_INI_SYSTEM, OnUpdateString, network_config, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
    STD_PHP_INI_ENTRY("king.cluster_autoscale_instance_tags", "", PHP_INI_SYSTEM, OnUpdateString, instance_tags, kg_cloud_autoscale_config_t, king_cloud_autoscale_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_cloud_autoscale_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_cloud_autoscale_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
