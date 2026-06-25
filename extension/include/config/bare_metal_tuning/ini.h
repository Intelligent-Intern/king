/*
 * =========================================================================
 * FILENAME:   include/config/bare_metal_tuning/ini.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the internal C-API for handling the `php.ini`
 * entries of the bare metal tuning configuration module.
 * =========================================================================
 */

#ifndef KING_CONFIG_BARE_METAL_INI_H
#define KING_CONFIG_BARE_METAL_INI_H

/**
 * @brief Registers this module's php.ini entries with the Zend Engine.
 */
void kg_config_bare_metal_tuning_ini_register(void);

/**
 * @brief Unregisters this module's php.ini entries from the Zend Engine.
 */
void kg_config_bare_metal_tuning_ini_unregister(void);

#endif /* KING_CONFIG_BARE_METAL_INI_H */
