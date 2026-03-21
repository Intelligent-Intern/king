#include "include/config/cluster_and_process/default.h"
#include "include/config/cluster_and_process/base_layer.h"

static char *king_persistent_strdup(const char *value)
{
    return pestrdup(value, 1);
}

void kg_config_cluster_and_process_defaults_load(void)
{
    king_cluster_config.cluster_workers = 0;
    king_cluster_config.cluster_graceful_shutdown_ms = 30000;
    king_cluster_config.server_max_fd_per_worker = 8192;
    king_cluster_config.cluster_restart_crashed_workers = true;
    king_cluster_config.cluster_max_restarts_per_worker = 10;
    king_cluster_config.cluster_restart_interval_sec = 60;
    king_cluster_config.cluster_worker_niceness = 0;
    king_cluster_config.cluster_worker_scheduler_policy = king_persistent_strdup("other");
    king_cluster_config.cluster_worker_cpu_affinity_map = king_persistent_strdup("");
    king_cluster_config.cluster_worker_cgroup_path = king_persistent_strdup("");
    king_cluster_config.cluster_worker_user_id = king_persistent_strdup("");
    king_cluster_config.cluster_worker_group_id = king_persistent_strdup("");
}
