#include "include/config/dynamic_admin_api/default.h"
#include "include/config/dynamic_admin_api/base_layer.h"

static char *king_persistent_strdup(const char *value)
{
    return pestrdup(value, 1);
}

void kg_config_dynamic_admin_api_defaults_load(void)
{
    king_dynamic_admin_api_config.bind_host = king_persistent_strdup("127.0.0.1");
    king_dynamic_admin_api_config.port = 2019;
    king_dynamic_admin_api_config.auth_mode = king_persistent_strdup("mtls");
    king_dynamic_admin_api_config.ca_file = king_persistent_strdup("");
    king_dynamic_admin_api_config.cert_file = king_persistent_strdup("");
    king_dynamic_admin_api_config.key_file = king_persistent_strdup("");
}
