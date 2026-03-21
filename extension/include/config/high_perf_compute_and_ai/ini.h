/*
 * =========================================================================
 * FILENAME:   include/config/high_perf_compute_and_ai/ini.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the internal C-API for handling the `php.ini`
 * entries of the High-Performance Compute & AI configuration module.
 * =========================================================================
 */

#ifndef KING_CONFIG_HIGH_PERF_COMPUTE_AI_INI_H
#define KING_CONFIG_HIGH_PERF_COMPUTE_AI_INI_H

/**
 * @brief Registers this module's php.ini entries with the Zend Engine.
 */
void kg_config_high_perf_compute_and_ai_ini_register(void);

/**
 * @brief Unregisters this module's php.ini entries from the Zend Engine.
 */
void kg_config_high_perf_compute_and_ai_ini_unregister(void);

#endif /* KING_CONFIG_HIGH_PERF_COMPUTE_AI_INI_H */
