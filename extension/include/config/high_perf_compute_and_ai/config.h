/*
 * =========================================================================
 * FILENAME:   include/config/high_perf_compute_and_ai/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the module-local helper for applying high-performance compute and
 * AI overrides to the live module config state.
 *
 * In the current build, the main `King\Config` snapshot pipeline does not
 * route through a dedicated high-perf `*_apply_userland_config_to()` helper,
 * so this header describes the direct module helper rather than a generic
 * config-snapshot entry point.
 * =========================================================================
 */

#ifndef KING_CONFIG_HIGH_PERF_COMPUTE_AI_CONFIG_H
#define KING_CONFIG_HIGH_PERF_COMPUTE_AI_CONFIG_H

#include "php.h"

/**
 * @brief Applies high-performance compute and AI settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_high_perf_compute_and_ai_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_HIGH_PERF_COMPUTE_AI_CONFIG_H */
