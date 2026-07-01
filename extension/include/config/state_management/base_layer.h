/*
 * =========================================================================
 * FILENAME:   include/config/state_management/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for state management.
 *
 * ARCHITECTURE:
 * This struct stores the default backend and URI for the state manager.
 * =========================================================================
 */
#ifndef KING_CONFIG_STATE_MANAGEMENT_BASE_H
#define KING_CONFIG_STATE_MANAGEMENT_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_state_management_config_t {
    char *default_backend;
    char *default_uri;

} kg_state_management_config_t;

/* Module-global configuration instance. */
extern kg_state_management_config_t king_state_management_config;

#endif /* KING_CONFIG_STATE_MANAGEMENT_BASE_H */
