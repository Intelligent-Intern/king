/*
 * =========================================================================
 * FILENAME:   src/config/tcp_transport/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the TCP transport config family. This file
 * wires together default loading and INI registration during module init
 * and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/tcp_transport/index.h"
#include "include/config/tcp_transport/default.h"
#include "include/config/tcp_transport/ini.h"

void kg_config_tcp_transport_init(void)
{
    kg_config_tcp_transport_defaults_load();
    kg_config_tcp_transport_ini_register();
}

void kg_config_tcp_transport_shutdown(void)
{
    kg_config_tcp_transport_ini_unregister();
}
