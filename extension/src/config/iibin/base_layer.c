/*
 * =========================================================================
 * FILENAME:   src/config/iibin/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the IIBIN config family.
 * Schema-field limits, recursion depth, string interning, shared-memory
 * buffer usage, default buffer sizing, and shared-memory backing path all
 * land in the single `king_iibin_config` snapshot.
 * =========================================================================
 */

#include "include/config/iibin/base_layer.h"

kg_iibin_config_t king_iibin_config;
