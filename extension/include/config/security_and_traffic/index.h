/*
 * =========================================================================
 * FILENAME:   include/config/security_and_traffic/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the lifecycle hooks for the security and traffic
 * configuration module.
 *
 * ARCHITECTURE:
 * These functions are called by `king_init.c` during `MINIT` and
 * `MSHUTDOWN` to register and unregister the module's `php.ini`
 * directives.
 * =========================================================================
 */

#ifndef KING_CONFIG_SECURITY_INDEX_H
#define KING_CONFIG_SECURITY_INDEX_H

/**
 * @brief Initializes the Security & Traffic configuration module.
 * @details This function loads the module defaults and registers the INI
 * handlers that control security-related settings. It also establishes the
 * global policy for whether userland overrides are allowed.
 */
void kg_config_security_and_traffic_init(void);

/**
 * @brief Shuts down the Security & Traffic configuration module.
 * @details This function unregisters the module's `php.ini` settings during
 * shutdown to release the module-owned INI state cleanly.
 */
void kg_config_security_and_traffic_shutdown(void);

#endif /* KING_CONFIG_SECURITY_INDEX_H */
