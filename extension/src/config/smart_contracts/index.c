/*
 * =========================================================================
 * FILENAME:   src/config/smart_contracts/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the smart-contracts config family. This file
 * wires together default loading and INI registration during module init
 * and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/smart_contracts/index.h"
#include "include/config/smart_contracts/default.h"
#include "include/config/smart_contracts/ini.h"

void kg_config_smart_contracts_init(void)
{
    kg_config_smart_contracts_defaults_load();
    kg_config_smart_contracts_ini_register();
}

void kg_config_smart_contracts_shutdown(void)
{
    kg_config_smart_contracts_ini_unregister();
}
