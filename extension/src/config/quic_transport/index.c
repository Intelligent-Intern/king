/*
 * =========================================================================
 * FILENAME:   src/config/quic_transport/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the QUIC transport config family. This file
 * wires together default loading and INI registration during module init
 * and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/quic_transport/index.h"
#include "include/config/quic_transport/default.h"
#include "include/config/quic_transport/ini.h"

void kg_config_quic_transport_init(void)
{
    kg_config_quic_transport_defaults_load();
    kg_config_quic_transport_ini_register();
}

void kg_config_quic_transport_shutdown(void)
{
    kg_config_quic_transport_ini_unregister();
}
