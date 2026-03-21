/*
 * =========================================================================
 * FILENAME:   include/config/state_management/ini.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the internal C-API for handling the `php.ini`
 * entries of the State Management configuration module.
 * =========================================================================
 */

#ifndef KING_CONFIG_STATE_MANAGEMENT_INI_H
#define KING_CONFIG_STATE_MANAGEMENT_INI_H

/**
 * @brief Registers this module's php.ini entries with the Zend Engine.
 */
void kg_config_state_management_ini_register(void);

/**
 * @brief Unregisters this module's php.ini entries from the Zend Engine.
 */
void kg_config_state_management_ini_unregister(void);

#endif /* KING_CONFIG_STATE_MANAGEMENT_INI_H */
