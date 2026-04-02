/*
 * =========================================================================
 * FILENAME:   include/config/semantic_geometry/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the semantic-geometry config apply helpers for both the live
 * module-global runtime state and `King\Config` snapshot targets.
 *
 * ARCHITECTURE:
 * Semantic geometry participates directly in the central config-snapshot
 * override pipeline through
 * `kg_config_semantic_geometry_apply_userland_config_to()`. The plain
 * `kg_config_semantic_geometry_apply_userland_config()` variant remains the
 * policy-gated helper for writing into the live module-global state.
 * =========================================================================
 */

#ifndef KING_CONFIG_SEMANTIC_GEOMETRY_CONFIG_H
#define KING_CONFIG_SEMANTIC_GEOMETRY_CONFIG_H

#include "php.h"
#include "include/config/semantic_geometry/base_layer.h"

/**
 * @brief Applies semantic-geometry settings from a PHP array to the live runtime state.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_semantic_geometry_apply_userland_config(zval *config_arr);

/**
 * @brief Applies semantic-geometry settings from a PHP array to a target config struct.
 * @param target The target semantic-geometry config snapshot to mutate.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_semantic_geometry_apply_userland_config_to(
    kg_semantic_geometry_config_t *target,
    zval *config_arr);

#endif /* KING_CONFIG_SEMANTIC_GEOMETRY_CONFIG_H */
