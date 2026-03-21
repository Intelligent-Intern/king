#include "include/config/state_management/default.h"
#include "include/config/state_management/base_layer.h"

void kg_config_state_management_defaults_load(void)
{
    king_state_management_config.default_backend = pestrdup("memory", 1);
    king_state_management_config.default_uri = pestrdup("", 1);
}
