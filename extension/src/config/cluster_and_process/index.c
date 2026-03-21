#include "include/config/cluster_and_process/index.h"
#include "include/config/cluster_and_process/default.h"
#include "include/config/cluster_and_process/ini.h"

void kg_config_cluster_and_process_init(void)
{
    kg_config_cluster_and_process_defaults_load();
    kg_config_cluster_and_process_ini_register();
}

void kg_config_cluster_and_process_shutdown(void)
{
    kg_config_cluster_and_process_ini_unregister();
}
