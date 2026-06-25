/*
 * =========================================================================
 * FILENAME:   include/config/semantic_geometry/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for semantic geometry.
 *
 * ARCHITECTURE:
 * This struct stores the runtime settings for geometric computations.
 * =========================================================================
 */
#ifndef KING_CONFIG_SEMANTIC_GEOMETRY_BASE_H
#define KING_CONFIG_SEMANTIC_GEOMETRY_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_semantic_geometry_config_t {
    /* Default dimensionality for new vector spaces. */
    zend_long default_vector_dimensions;

    /* Floating-point precision for geometric calculations. */
    char *calculation_precision; /* e.g. "float32", "float64" */

    /* Convex hull algorithm. */
    char *convex_hull_algorithm; /* e.g. "qhull", "gift_wrapping" */

    /* Point-in-polytope algorithm. */
    char *point_in_polytope_algorithm; /* e.g. "ray_casting", "barycentric" */

    /* Hausdorff distance algorithm. */
    char *hausdorff_distance_algorithm; /* e.g. "exact", "approximated" */

    /* Step size for spiral search. */
    double spiral_search_step_size;

    /* Threshold for promoting a candidate into the core set. */
    double core_consolidation_threshold;

} kg_semantic_geometry_config_t;

/* Module-global configuration instance. */
extern kg_semantic_geometry_config_t king_semantic_geometry_config;

#endif /* KING_CONFIG_SEMANTIC_GEOMETRY_BASE_H */
