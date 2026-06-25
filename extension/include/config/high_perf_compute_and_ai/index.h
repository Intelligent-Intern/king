/*
 * =========================================================================
 * FILENAME:   include/config/high_perf_compute_and_ai/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the High-Performance
 * Compute & AI configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_HIGH_PERF_COMPUTE_AI_INDEX_H
#define KING_CONFIG_HIGH_PERF_COMPUTE_AI_INDEX_H

/**
 * @brief Initializes the High-Performance Compute & AI configuration module.
 */
void kg_config_high_perf_compute_and_ai_init(void);

/**
 * @brief Shuts down the High-Performance Compute & AI configuration module.
 */
void kg_config_high_perf_compute_and_ai_shutdown(void);

#endif /* KING_CONFIG_HIGH_PERF_COMPUTE_AI_INDEX_H */
