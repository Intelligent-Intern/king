/*
 * =========================================================================
 * FILENAME:   src/config/iibin/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the IIBIN config family. This file wires
 * together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/iibin/index.h"
#include "include/config/iibin/default.h"
#include "include/config/iibin/ini.h"

void kg_config_iibin_init(void)
{
    kg_config_iibin_defaults_load();
    kg_config_iibin_ini_register();
}

void kg_config_iibin_shutdown(void)
{
    kg_config_iibin_ini_unregister();
}
