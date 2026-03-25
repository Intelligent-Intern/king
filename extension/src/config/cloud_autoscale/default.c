#include "include/config/cloud_autoscale/default.h"
#include "include/config/cloud_autoscale/base_layer.h"

static char *king_persistent_strdup(const char *value)
{
    return pestrdup(value, 1);
}

void kg_config_cloud_autoscale_defaults_load(void)
{
    king_cloud_autoscale_config.provider = king_persistent_strdup("");
    king_cloud_autoscale_config.region = king_persistent_strdup("");
    king_cloud_autoscale_config.credentials_path = king_persistent_strdup("");
    king_cloud_autoscale_config.api_endpoint = king_persistent_strdup("https://api.hetzner.cloud/v1");
    king_cloud_autoscale_config.state_path = king_persistent_strdup("");
    king_cloud_autoscale_config.server_name_prefix = king_persistent_strdup("king-node");
    king_cloud_autoscale_config.bootstrap_user_data = king_persistent_strdup("");
    king_cloud_autoscale_config.firewall_ids = king_persistent_strdup("");
    king_cloud_autoscale_config.placement_group_id = king_persistent_strdup("");
    king_cloud_autoscale_config.prepared_release_url = king_persistent_strdup("");
    king_cloud_autoscale_config.join_endpoint = king_persistent_strdup("");
    king_cloud_autoscale_config.hetzner_api_token = king_persistent_strdup("");
    king_cloud_autoscale_config.hetzner_budget_path = king_persistent_strdup("");
    king_cloud_autoscale_config.spend_warning_threshold_percent = 80;
    king_cloud_autoscale_config.spend_hard_limit_percent = 95;
    king_cloud_autoscale_config.quota_warning_threshold_percent = 80;
    king_cloud_autoscale_config.quota_hard_limit_percent = 95;

    king_cloud_autoscale_config.min_nodes = 1;
    king_cloud_autoscale_config.max_nodes = 10;
    king_cloud_autoscale_config.max_scale_step = 1;
    king_cloud_autoscale_config.scale_up_cpu_threshold_percent = 80;
    king_cloud_autoscale_config.scale_down_cpu_threshold_percent = 20;
    king_cloud_autoscale_config.scale_up_policy = king_persistent_strdup("add_nodes:1");
    king_cloud_autoscale_config.cooldown_period_sec = 300;
    king_cloud_autoscale_config.idle_node_timeout_sec = 600;

    king_cloud_autoscale_config.instance_type = king_persistent_strdup("");
    king_cloud_autoscale_config.instance_image_id = king_persistent_strdup("");
    king_cloud_autoscale_config.network_config = king_persistent_strdup("");
    king_cloud_autoscale_config.instance_tags = king_persistent_strdup("");
}
