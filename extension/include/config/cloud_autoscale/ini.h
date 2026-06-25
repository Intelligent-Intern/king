/*
 * =========================================================================
 * FILENAME:   include/config/cloud_autoscale/ini.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the internal C-API for handling the `php.ini`
 * entries of the cloud autoscaling configuration module.
 * =========================================================================
 */

#ifndef KING_CONFIG_CLOUD_AUTOSCALE_INI_H
#define KING_CONFIG_CLOUD_AUTOSCALE_INI_H

/**
 * @brief Registers this module's php.ini entries with the Zend Engine.
 */
void kg_config_cloud_autoscale_ini_register(void);

/**
 * @brief Unregisters this module's php.ini entries from the Zend Engine.
 */
void kg_config_cloud_autoscale_ini_unregister(void);

#endif /* KING_CONFIG_CLOUD_AUTOSCALE_INI_H */
