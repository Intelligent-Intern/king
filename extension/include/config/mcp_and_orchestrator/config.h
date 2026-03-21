/*
 * =========================================================================
 * FILENAME:   include/config/mcp_and_orchestrator/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for applying runtime
 * configuration changes from PHP userland to the MCP & Orchestrator module.
 * =========================================================================
 */

#ifndef KING_CONFIG_MCP_ORCHESTRATOR_CONFIG_H
#define KING_CONFIG_MCP_ORCHESTRATOR_CONFIG_H

#include "php.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"

/**
 * @brief Applies runtime configuration settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_mcp_and_orchestrator_apply_userland_config(zval *config_arr);
int kg_config_mcp_and_orchestrator_apply_userland_config_to(
    kg_mcp_orchestrator_config_t *target,
    zval *config_arr);

#endif /* KING_CONFIG_MCP_ORCHESTRATOR_CONFIG_H */
