/*
 * =========================================================================
 * FILENAME:   include/config/dynamic_admin_api/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for the dynamic Admin API module.
 *
 * ARCHITECTURE:
 * This struct stores the bootstrap settings for the secure Admin API
 * endpoint. The enable flag itself lives in `security_and_traffic`.
 * =========================================================================
 */
#ifndef KING_CONFIG_DYNAMIC_ADMIN_API_BASE_H
#define KING_CONFIG_DYNAMIC_ADMIN_API_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_dynamic_admin_api_config_t {
    /*
     * NOTE: The 'admin_api_enable' flag is intentionally located in the
     * `security_and_traffic` module to centralize security policy control.
     * This module will read that configuration to decide whether to activate.
     */
    char *bind_host;
    zend_long port;
    char *auth_mode;
    char *ca_file;
    char *cert_file;
    char *key_file;

} kg_dynamic_admin_api_config_t;

/* Module-global configuration instance. */
extern kg_dynamic_admin_api_config_t king_dynamic_admin_api_config;

#endif /* KING_CONFIG_DYNAMIC_ADMIN_API_BASE_H */
