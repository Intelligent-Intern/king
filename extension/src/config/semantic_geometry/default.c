#include "include/config/semantic_geometry/default.h"
#include "include/config/semantic_geometry/base_layer.h"

void kg_config_semantic_geometry_defaults_load(void)
{
    king_semantic_geometry_config.default_vector_dimensions = 768;
    king_semantic_geometry_config.calculation_precision = NULL;
    king_semantic_geometry_config.convex_hull_algorithm = NULL;
    king_semantic_geometry_config.point_in_polytope_algorithm = NULL;
    king_semantic_geometry_config.hausdorff_distance_algorithm = NULL;
    king_semantic_geometry_config.spiral_search_step_size = 0.1;
    king_semantic_geometry_config.core_consolidation_threshold = 0.95;
}
