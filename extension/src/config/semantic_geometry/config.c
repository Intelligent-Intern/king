/*
 * =========================================================================
 * FILENAME:   src/config/semantic_geometry/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the semantic geometry config family.
 * This file validates the `King\\Config` subset that can target either a
 * temporary config snapshot or the live module-global state and applies
 * bounded dimensionality, algorithm-selection, precision, and threshold
 * overrides.
 * =========================================================================
 */

#include "include/config/semantic_geometry/config.h"
#include "include/config/semantic_geometry/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"
#include "include/validation/config_param/validate_double_range.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static const char *k_semantic_geometry_calculation_precision_allowed[] = {"float32", "float64", NULL};
static const char *k_semantic_geometry_convex_hull_allowed[] = {"qhull", "gift_wrapping", NULL};
static const char *k_semantic_geometry_point_in_polytope_allowed[] = {"ray_casting", "barycentric", NULL};
static const char *k_semantic_geometry_hausdorff_allowed[] = {"exact", "approximated", NULL};

int kg_config_semantic_geometry_apply_userland_config_to(
    kg_semantic_geometry_config_t *target,
    zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "default_vector_dimensions")) {
            if (kg_validate_positive_long(value, &target->default_vector_dimensions) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "calculation_precision")) {
            if (kg_validate_string_from_allowlist(value, k_semantic_geometry_calculation_precision_allowed,
                    &target->calculation_precision) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "convex_hull_algorithm")) {
            if (kg_validate_string_from_allowlist(value, k_semantic_geometry_convex_hull_allowed,
                    &target->convex_hull_algorithm) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "point_in_polytope_algorithm")) {
            if (kg_validate_string_from_allowlist(value, k_semantic_geometry_point_in_polytope_allowed,
                    &target->point_in_polytope_algorithm) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "hausdorff_distance_algorithm")) {
            if (kg_validate_string_from_allowlist(value, k_semantic_geometry_hausdorff_allowed,
                    &target->hausdorff_distance_algorithm) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "spiral_search_step_size")) {
            if (kg_validate_double_range(value, 0.0, 1.0,
                    &target->spiral_search_step_size) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "core_consolidation_threshold")) {
            if (kg_validate_double_range(value, 0.0, 1.0,
                    &target->core_consolidation_threshold) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_semantic_geometry_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_semantic_geometry_apply_userland_config_to(
        &king_semantic_geometry_config,
        config_arr
    );
}
