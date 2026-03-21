#include "include/config/semantic_geometry/index.h"
#include "include/config/semantic_geometry/default.h"
#include "include/config/semantic_geometry/ini.h"

void kg_config_semantic_geometry_init(void)
{
    kg_config_semantic_geometry_defaults_load();
    kg_config_semantic_geometry_ini_register();
}

void kg_config_semantic_geometry_shutdown(void)
{
    kg_config_semantic_geometry_ini_unregister();
}
