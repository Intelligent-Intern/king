/*
 * =========================================================================
 * FILENAME:   include/config/cloud_autoscale/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for cloud autoscaling.
 * =========================================================================
 */

#ifndef KING_CONFIG_CLOUD_AUTOSCALE_DEFAULT_H
#define KING_CONFIG_CLOUD_AUTOSCALE_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_cloud_autoscale_defaults_load(void);

#endif /* KING_CONFIG_CLOUD_AUTOSCALE_DEFAULT_H */
