/*
 * =========================================================================
 * FILENAME:   src/config/cloud_autoscale/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the cloud-autoscale config family. This slice
 * seeds the provider-neutral autoscale defaults plus the current Hetzner-
 * flavored endpoint/budget placeholders before INI and any allowed userland
 * overrides refine the live autoscale snapshot.
 * =========================================================================
 */

#include "include/config/cloud_autoscale/default.h"
#include "include/config/cloud_autoscale/base_layer.h"

void kg_config_cloud_autoscale_defaults_load(void)
{
    king_cloud_autoscale_config.provider = NULL;
    king_cloud_autoscale_config.region = NULL;
    king_cloud_autoscale_config.credentials_path = NULL;
    king_cloud_autoscale_config.api_endpoint = NULL;
    king_cloud_autoscale_config.state_path = NULL;
    king_cloud_autoscale_config.server_name_prefix = NULL;
    king_cloud_autoscale_config.bootstrap_user_data = NULL;
    king_cloud_autoscale_config.firewall_ids = NULL;
    king_cloud_autoscale_config.placement_group_id = NULL;
    king_cloud_autoscale_config.prepared_release_url = NULL;
    king_cloud_autoscale_config.join_endpoint = NULL;
    king_cloud_autoscale_config.hetzner_api_token = NULL;
    king_cloud_autoscale_config.hetzner_budget_path = NULL;
    king_cloud_autoscale_config.spend_warning_threshold_percent = 80;
    king_cloud_autoscale_config.spend_hard_limit_percent = 95;
    king_cloud_autoscale_config.quota_warning_threshold_percent = 80;
    king_cloud_autoscale_config.quota_hard_limit_percent = 95;

    king_cloud_autoscale_config.min_nodes = 1;
    king_cloud_autoscale_config.max_nodes = 10;
    king_cloud_autoscale_config.max_scale_step = 1;
    king_cloud_autoscale_config.scale_up_cpu_threshold_percent = 80;
    king_cloud_autoscale_config.scale_down_cpu_threshold_percent = 20;
    king_cloud_autoscale_config.scale_up_policy = NULL;
    king_cloud_autoscale_config.cooldown_period_sec = 300;
    king_cloud_autoscale_config.idle_node_timeout_sec = 600;

    king_cloud_autoscale_config.instance_type = NULL;
    king_cloud_autoscale_config.instance_image_id = NULL;
    king_cloud_autoscale_config.network_config = NULL;
    king_cloud_autoscale_config.instance_tags = NULL;
}
