/*
 * =========================================================================
 * FILENAME:   include/config/open_telemetry/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the OpenTelemetry integration.
 * =========================================================================
 */

#ifndef KING_CONFIG_OPEN_TELEMETRY_DEFAULT_H
#define KING_CONFIG_OPEN_TELEMETRY_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_open_telemetry_defaults_load(void);

#endif /* KING_CONFIG_OPEN_TELEMETRY_DEFAULT_H */
