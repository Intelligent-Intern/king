/*
 * =========================================================================
 * FILENAME:   include/config/security_and_traffic/ini.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the internal C-API for the security and traffic
 * module's `php.ini` registration hooks.
 *
 * ARCHITECTURE:
 * These functions are called by the module lifecycle layer and provide the
 * direct interface to Zend's INI registration APIs.
 * =========================================================================
 */

#ifndef KING_CONFIG_SECURITY_INI_H
#define KING_CONFIG_SECURITY_INI_H

/**
 * @brief Registers this module's php.ini entries with the Zend Engine.
 * @details This function is called during `MINIT` to register the module's
 * `php.ini` directives and wire up their validation handlers.
 */
void kg_config_security_ini_register(void);

/**
 * @brief Unregisters this module's php.ini entries from the Zend Engine.
 * @details This function is called during `MSHUTDOWN` to unregister the
 * module's `php.ini` directives.
 */
void kg_config_security_ini_unregister(void);

#endif /* KING_CONFIG_SECURITY_INI_H */
