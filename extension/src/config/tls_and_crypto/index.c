/*
 * =========================================================================
 * FILENAME:   src/config/tls_and_crypto/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the TLS and crypto config family. This file
 * wires together default loading and INI registration during module init
 * and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/tls_and_crypto/index.h"
#include "include/config/tls_and_crypto/default.h"
#include "include/config/tls_and_crypto/ini.h"

void kg_config_tls_and_crypto_init(void)
{
    kg_config_tls_and_crypto_defaults_load();
    kg_config_tls_and_crypto_ini_register();
}

void kg_config_tls_and_crypto_shutdown(void)
{
    kg_config_tls_and_crypto_ini_unregister();
}
