#include "include/config/tcp_transport/index.h"
#include "include/config/tcp_transport/default.h"
#include "include/config/tcp_transport/ini.h"

void kg_config_tcp_transport_init(void)
{
    kg_config_tcp_transport_defaults_load();
    kg_config_tcp_transport_ini_register();
}

void kg_config_tcp_transport_shutdown(void)
{
    kg_config_tcp_transport_ini_unregister();
}
