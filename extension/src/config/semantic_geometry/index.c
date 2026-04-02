/*
 * =========================================================================
 * FILENAME:   src/config/semantic_geometry/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the semantic geometry config family. This
 * file wires together default loading and INI registration during module
 * init and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/semantic_geometry/index.h"
#include "include/config/semantic_geometry/default.h"
#include "include/config/semantic_geometry/ini.h"

void kg_config_semantic_geometry_init(void)
{
    kg_config_semantic_geometry_defaults_load();
    kg_config_semantic_geometry_ini_register();
}

void kg_config_semantic_geometry_shutdown(void)
{
    kg_config_semantic_geometry_ini_unregister();
}
