/*
 * =========================================================================
 * FILENAME:   include/config/mcp_and_orchestrator/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the MCP & Orchestrator engine.
 * =========================================================================
 */

#ifndef KING_CONFIG_MCP_ORCHESTRATOR_DEFAULT_H
#define KING_CONFIG_MCP_ORCHESTRATOR_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_mcp_and_orchestrator_defaults_load(void);

#endif /* KING_CONFIG_MCP_ORCHESTRATOR_DEFAULT_H */
