/*
 * =========================================================================
 * FILENAME:   src/config/cloud_autoscale/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the cloud-autoscale config family. This file
 * wires together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/cloud_autoscale/index.h"
#include "include/config/cloud_autoscale/default.h"
#include "include/config/cloud_autoscale/ini.h"

void kg_config_cloud_autoscale_init(void)
{
    kg_config_cloud_autoscale_defaults_load();
    kg_config_cloud_autoscale_ini_register();
}

void kg_config_cloud_autoscale_shutdown(void)
{
    kg_config_cloud_autoscale_ini_unregister();
}
