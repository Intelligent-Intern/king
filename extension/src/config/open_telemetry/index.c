/*
 * =========================================================================
 * FILENAME:   src/config/open_telemetry/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the OpenTelemetry config family. This file
 * wires together default loading and INI registration during module init
 * and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/open_telemetry/index.h"
#include "include/config/open_telemetry/default.h"
#include "include/config/open_telemetry/ini.h"

void kg_config_open_telemetry_init(void)
{
    kg_config_open_telemetry_defaults_load();
    kg_config_open_telemetry_ini_register();
}

void kg_config_open_telemetry_shutdown(void)
{
    kg_config_open_telemetry_ini_unregister();
}
