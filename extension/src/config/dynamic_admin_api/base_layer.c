/*
 * =========================================================================
 * FILENAME:   src/config/dynamic_admin_api/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the dynamic-admin-api config
 * family. Bind host, port, auth mode, and the optional mTLS CA/cert/key
 * paths all land in the single `king_dynamic_admin_api_config` snapshot.
 * =========================================================================
 */

#include "include/config/dynamic_admin_api/base_layer.h"

kg_dynamic_admin_api_config_t king_dynamic_admin_api_config;
