/*
 * =========================================================================
 * FILENAME:   include/config/bare_metal_tuning/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the bare metal tuning
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_BARE_METAL_INDEX_H
#define KING_CONFIG_BARE_METAL_INDEX_H

/**
 * @brief Initializes the Bare Metal Tuning configuration module.
 */
void kg_config_bare_metal_tuning_init(void);

/**
 * @brief Shuts down the Bare Metal Tuning configuration module.
 */
void kg_config_bare_metal_tuning_shutdown(void);

#endif /* KING_CONFIG_BARE_METAL_INDEX_H */
