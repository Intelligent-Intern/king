/*
 * =========================================================================
 * FILENAME:   src/config/native_object_store/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the native object-store config family. This
 * file wires together default loading and INI registration during module
 * init and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/native_object_store/index.h"
#include "include/config/native_object_store/default.h"
#include "include/config/native_object_store/ini.h"

void kg_config_native_object_store_init(void)
{
    kg_config_native_object_store_defaults_load();
    kg_config_native_object_store_ini_register();
}

void kg_config_native_object_store_shutdown(void)
{
    kg_config_native_object_store_ini_unregister();
}
