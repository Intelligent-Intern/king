#include "include/config/http2/index.h"
#include "include/config/http2/default.h"
#include "include/config/http2/ini.h"

void kg_config_http2_init(void)
{
    kg_config_http2_defaults_load();
    kg_config_http2_ini_register();
}

void kg_config_http2_shutdown(void)
{
    kg_config_http2_ini_unregister();
}
