/*
 * =========================================================================
 * FILENAME:   src/config/cluster_and_process/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the cluster/process config family. This file
 * wires together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

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
