/*
 * =========================================================================
 * FILENAME:   src/config/security_and_traffic/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the security and traffic
 * config family. The global config-override gate, admin-API enablement,
 * rate-limiter toggles and thresholds, and the CORS origin policy all land
 * in the single `king_security_config` snapshot.
 * =========================================================================
 */

#include "include/config/security_and_traffic/base_layer.h"

kg_security_config_t king_security_config;
