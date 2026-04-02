/*
 * =========================================================================
 * FILENAME:   src/config/dynamic_admin_api/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the dynamic-admin-api config family. This slice
 * seeds the local bind/port defaults and empty mTLS path/auth placeholders
 * before INI and any allowed userland overrides refine the live admin-api
 * snapshot.
 * =========================================================================
 */

#include "include/config/dynamic_admin_api/default.h"
#include "include/config/dynamic_admin_api/base_layer.h"

void kg_config_dynamic_admin_api_defaults_load(void)
{
    king_dynamic_admin_api_config.bind_host = NULL;
    king_dynamic_admin_api_config.port = 2019;
    king_dynamic_admin_api_config.auth_mode = NULL;
    king_dynamic_admin_api_config.ca_file = NULL;
    king_dynamic_admin_api_config.cert_file = NULL;
    king_dynamic_admin_api_config.key_file = NULL;
}
