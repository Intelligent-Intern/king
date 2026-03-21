/*
 * =========================================================================
 * FILENAME:   include/config/mcp_and_orchestrator/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the MCP & Orchestrator
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_MCP_ORCHESTRATOR_INDEX_H
#define KING_CONFIG_MCP_ORCHESTRATOR_INDEX_H

/**
 * @brief Initializes the MCP & Orchestrator configuration module.
 */
void kg_config_mcp_and_orchestrator_init(void);

/**
 * @brief Shuts down the MCP & Orchestrator configuration module.
 */
void kg_config_mcp_and_orchestrator_shutdown(void);

#endif /* KING_CONFIG_MCP_ORCHESTRATOR_INDEX_H */
