/*
 * =========================================================================
 * FILENAME:   src/config/state_management/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the state-management config family. This slice
 * seeds the baseline backend and URI placeholders before INI and any
 * allowed userland overrides refine the live state-management snapshot.
 * =========================================================================
 */

#include "include/config/state_management/default.h"
#include "include/config/state_management/base_layer.h"

void kg_config_state_management_defaults_load(void)
{
    king_state_management_config.default_backend = NULL;
    king_state_management_config.default_uri = NULL;
}
