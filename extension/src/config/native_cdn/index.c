/*
 * =========================================================================
 * FILENAME:   src/config/native_cdn/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the native CDN config family. This file wires
 * together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/native_cdn/index.h"
#include "include/config/native_cdn/default.h"
#include "include/config/native_cdn/ini.h"

void kg_config_native_cdn_init(void)
{
    kg_config_native_cdn_defaults_load();
    kg_config_native_cdn_ini_register();
}

void kg_config_native_cdn_shutdown(void)
{
    kg_config_native_cdn_ini_unregister();
}
