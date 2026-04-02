/*
 * =========================================================================
 * FILENAME:   src/config/bare_metal_tuning/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the bare-metal tuning config family. This file
 * wires together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/bare_metal_tuning/index.h"
#include "include/config/bare_metal_tuning/default.h"
#include "include/config/bare_metal_tuning/ini.h"

void kg_config_bare_metal_tuning_init(void)
{
    kg_config_bare_metal_tuning_defaults_load();
    kg_config_bare_metal_tuning_ini_register();
}

void kg_config_bare_metal_tuning_shutdown(void)
{
    kg_config_bare_metal_tuning_ini_unregister();
}
