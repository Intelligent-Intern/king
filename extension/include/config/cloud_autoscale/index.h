/*
 * =========================================================================
 * FILENAME:   include/config/cloud_autoscale/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the cloud autoscaling
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_CLOUD_AUTOSCALE_INDEX_H
#define KING_CONFIG_CLOUD_AUTOSCALE_INDEX_H

/**
 * @brief Initializes the Cloud Autoscaling configuration module.
 */
void kg_config_cloud_autoscale_init(void);

/**
 * @brief Shuts down the Cloud Autoscaling configuration module.
 */
void kg_config_cloud_autoscale_shutdown(void);

#endif /* KING_CONFIG_CLOUD_AUTOSCALE_INDEX_H */
