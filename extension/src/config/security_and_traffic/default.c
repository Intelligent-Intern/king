/*
 * =========================================================================
 * FILENAME:   src/config/security_and_traffic/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the security and traffic config family. This
 * slice seeds the baseline policy-gate, admin-API, rate-limiter, and CORS
 * defaults before INI and any allowed userland overrides refine the live
 * security snapshot.
 * =========================================================================
 */

#include "include/config/security_and_traffic/default.h"
#include "include/config/security_and_traffic/base_layer.h"

void kg_config_security_defaults_load(void)
{
    king_security_config.allow_config_override = false;
    king_security_config.admin_api_enable = false;
    king_security_config.rate_limiter_enable = true;
    king_security_config.rate_limiter_requests_per_sec = 100;
    king_security_config.rate_limiter_burst = 50;
    king_security_config.cors_allowed_origins = NULL;
}
