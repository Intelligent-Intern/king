/*
 * =========================================================================
 * FILENAME:   src/config/semantic_geometry/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the semantic geometry
 * config family. This file exposes the system-level dimensionality,
 * precision, algorithm-choice, and threshold directives and keeps
 * `king_semantic_geometry_config` aligned with validated updates.
 * =========================================================================
 */

#include "include/config/semantic_geometry/ini.h"
#include "include/config/semantic_geometry/base_layer.h"

#include "php.h"
#include <zend_ini.h>

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.geometry_default_vector_dimensions", "768",
        PHP_INI_SYSTEM, OnUpdateLong, default_vector_dimensions,
        kg_semantic_geometry_config_t, king_semantic_geometry_config)
    STD_PHP_INI_ENTRY("king.geometry_calculation_precision", "float64",
        PHP_INI_SYSTEM, OnUpdateString, calculation_precision,
        kg_semantic_geometry_config_t, king_semantic_geometry_config)
    STD_PHP_INI_ENTRY("king.geometry_convex_hull_algorithm", "qhull",
        PHP_INI_SYSTEM, OnUpdateString, convex_hull_algorithm,
        kg_semantic_geometry_config_t, king_semantic_geometry_config)
    STD_PHP_INI_ENTRY("king.geometry_point_in_polytope_algorithm", "ray_casting",
        PHP_INI_SYSTEM, OnUpdateString, point_in_polytope_algorithm,
        kg_semantic_geometry_config_t, king_semantic_geometry_config)
    STD_PHP_INI_ENTRY("king.geometry_hausdorff_distance_algorithm", "exact",
        PHP_INI_SYSTEM, OnUpdateString, hausdorff_distance_algorithm,
        kg_semantic_geometry_config_t, king_semantic_geometry_config)
    STD_PHP_INI_ENTRY("king.geometry_spiral_search_step_size", "0.1",
        PHP_INI_SYSTEM, OnUpdateReal, spiral_search_step_size,
        kg_semantic_geometry_config_t, king_semantic_geometry_config)
    STD_PHP_INI_ENTRY("king.geometry_core_consolidation_threshold", "0.95",
        PHP_INI_SYSTEM, OnUpdateReal, core_consolidation_threshold,
        kg_semantic_geometry_config_t, king_semantic_geometry_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_semantic_geometry_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_semantic_geometry_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
