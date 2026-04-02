/*
 * =========================================================================
 * FILENAME:   src/config/semantic_geometry/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the semantic geometry config
 * family. Default vector dimensionality, calculation precision, selected
 * convex-hull / point-in-polytope / Hausdorff algorithms, and the bounded
 * spiral-search and consolidation thresholds all land in the single
 * `king_semantic_geometry_config` snapshot.
 * =========================================================================
 */

#include "include/config/semantic_geometry/base_layer.h"

kg_semantic_geometry_config_t king_semantic_geometry_config = {0};
