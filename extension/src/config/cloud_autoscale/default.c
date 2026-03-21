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

    king_cloud_autoscale_config.min_nodes = 1;
    king_cloud_autoscale_config.max_nodes = 1;
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
