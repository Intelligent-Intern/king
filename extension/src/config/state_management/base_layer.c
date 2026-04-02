/*
 * =========================================================================
 * FILENAME:   src/config/state_management/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the state-management config
 * family. The default backend selector and its companion URI both land in
 * the single `king_state_management_config` snapshot that other runtime
 * surfaces read when they need persistent state wiring.
 * =========================================================================
 */

#include "include/config/state_management/base_layer.h"

kg_state_management_config_t king_state_management_config = {0};
