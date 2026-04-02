/*
 * =========================================================================
 * FILENAME:   src/config/smart_dns/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the Smart-DNS config family. This file wires
 * together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/smart_dns/index.h"
#include "include/config/smart_dns/default.h"
#include "include/config/smart_dns/ini.h"

void kg_config_smart_dns_init(void)
{
    kg_config_smart_dns_defaults_load();
    kg_config_smart_dns_ini_register();
}

void kg_config_smart_dns_shutdown(void)
{
    kg_config_smart_dns_ini_unregister();
}
