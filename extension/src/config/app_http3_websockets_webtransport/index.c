/*
 * =========================================================================
 * FILENAME:   src/config/app_http3_websockets_webtransport/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the app-protocol config family. This file
 * wires together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/app_http3_websockets_webtransport/index.h"
#include "include/config/app_http3_websockets_webtransport/default.h"
#include "include/config/app_http3_websockets_webtransport/ini.h"

void kg_config_app_http3_websockets_webtransport_init(void)
{
    kg_config_app_http3_websockets_webtransport_defaults_load();
    kg_config_app_http3_websockets_webtransport_ini_register();
}

void kg_config_app_http3_websockets_webtransport_shutdown(void)
{
    kg_config_app_http3_websockets_webtransport_ini_unregister();
}
