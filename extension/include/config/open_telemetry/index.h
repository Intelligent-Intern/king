/*
 * =========================================================================
 * FILENAME:   include/config/open_telemetry/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the OpenTelemetry
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_OPEN_TELEMETRY_INDEX_H
#define KING_CONFIG_OPEN_TELEMETRY_INDEX_H

/**
 * @brief Initializes the OpenTelemetry configuration module.
 */
void kg_config_open_telemetry_init(void);

/**
 * @brief Shuts down the OpenTelemetry configuration module.
 */
void kg_config_open_telemetry_shutdown(void);

#endif /* KING_CONFIG_OPEN_TELEMETRY_INDEX_H */
