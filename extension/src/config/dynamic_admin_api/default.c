/*
 * =========================================================================
 * FILENAME:   src/config/dynamic_admin_api/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the dynamic-admin-api config family. This slice
 * seeds the local bind/port defaults and unset mTLS path/auth fields
 * before INI and any allowed userland overrides refine the live admin-api
 * snapshot.
 * =========================================================================
 */

#include "config/dynamic_admin_api/default.h"
#include "config/dynamic_admin_api/base_layer.h"

static void dynamic_admin_api_release_owned_string(char **value)
{
    if (*value != NULL) {
        pefree(*value, 1);
        *value = NULL;
    }
}

void kg_config_dynamic_admin_api_defaults_load(void)
{
    king_dynamic_admin_api_config.bind_host = NULL;
    king_dynamic_admin_api_config.port = 2019;
    king_dynamic_admin_api_config.auth_mode = NULL;
    king_dynamic_admin_api_config.ca_file = NULL;
    king_dynamic_admin_api_config.cert_file = NULL;
    king_dynamic_admin_api_config.key_file = NULL;
}

void kg_config_dynamic_admin_api_defaults_release(void)
{
    /*
     * bind_host is managed by the engine's STD_PHP_INI_ENTRY storage.
     * The remaining string fields are persistent copies created by the
     * dynamic-admin-api INI callbacks in this config family.
     */
    king_dynamic_admin_api_config.bind_host = NULL;
    king_dynamic_admin_api_config.port = 2019;
    dynamic_admin_api_release_owned_string(&king_dynamic_admin_api_config.auth_mode);
    dynamic_admin_api_release_owned_string(&king_dynamic_admin_api_config.ca_file);
    dynamic_admin_api_release_owned_string(&king_dynamic_admin_api_config.cert_file);
    dynamic_admin_api_release_owned_string(&king_dynamic_admin_api_config.key_file);
}
