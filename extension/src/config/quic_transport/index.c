#include "include/config/quic_transport/index.h"
#include "include/config/quic_transport/default.h"
#include "include/config/quic_transport/ini.h"

void kg_config_quic_transport_init(void)
{
    kg_config_quic_transport_defaults_load();
    kg_config_quic_transport_ini_register();
}

void kg_config_quic_transport_shutdown(void)
{
    kg_config_quic_transport_ini_unregister();
}
