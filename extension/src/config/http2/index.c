/*
 * =========================================================================
 * FILENAME:   src/config/http2/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the HTTP/2 config family. This file wires
 * together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/http2/index.h"
#include "include/config/http2/default.h"
#include "include/config/http2/ini.h"

void kg_config_http2_init(void)
{
    kg_config_http2_defaults_load();
    kg_config_http2_ini_register();
}

void kg_config_http2_shutdown(void)
{
    kg_config_http2_ini_unregister();
}
