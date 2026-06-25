/*
 * =========================================================================
 * FILENAME:   include/config/http2/ini.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the internal C-API for handling the `php.ini`
 * entries of the HTTP/2 configuration module.
 * =========================================================================
 */

#ifndef KING_CONFIG_HTTP2_INI_H
#define KING_CONFIG_HTTP2_INI_H

/**
 * @brief Registers this module's php.ini entries with the Zend Engine.
 */
void kg_config_http2_ini_register(void);

/**
 * @brief Unregisters this module's php.ini entries from the Zend Engine.
 */
void kg_config_http2_ini_unregister(void);

#endif /* KING_CONFIG_HTTP2_INI_H */
