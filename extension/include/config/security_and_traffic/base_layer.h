/*
 * =========================================================================
 * FILENAME:   include/config/security_and_traffic/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for security and traffic policy.
 *
 * ARCHITECTURE:
 * This struct stores the override policy, rate limiter, and CORS settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_SECURITY_BASE_H
#define KING_CONFIG_SECURITY_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_security_config_t {
    /* --- Core Override Policy --- */
    bool allow_config_override;
    bool admin_api_enable;

    /* --- Rate Limiter --- */
    bool rate_limiter_enable;
    zend_long rate_limiter_requests_per_sec;
    zend_long rate_limiter_burst;

    /* --- CORS --- */
    char *cors_allowed_origins;

} kg_security_config_t;

/* Module-global configuration instance. */
extern kg_security_config_t king_security_config;

#endif /* KING_CONFIG_SECURITY_BASE_H */
