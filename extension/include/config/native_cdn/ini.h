/*
 * =========================================================================
 * FILENAME:   include/config/native_cdn/ini.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the internal C-API for handling the `php.ini`
 * entries of the Native CDN configuration module.
 * =========================================================================
 */

#ifndef KING_CONFIG_NATIVE_CDN_INI_H
#define KING_CONFIG_NATIVE_CDN_INI_H

/**
 * @brief Registers this module's php.ini entries with the Zend Engine.
 */
void kg_config_native_cdn_ini_register(void);

/**
 * @brief Unregisters this module's php.ini entries from the Zend Engine.
 */
void kg_config_native_cdn_ini_unregister(void);

#endif /* KING_CONFIG_NATIVE_CDN_INI_H */
