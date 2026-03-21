#include "include/config/ssh_over_quic/index.h"
#include "include/config/ssh_over_quic/default.h"
#include "include/config/ssh_over_quic/ini.h"

void kg_config_ssh_over_quic_init(void)
{
    kg_config_ssh_over_quic_defaults_load();
    kg_config_ssh_over_quic_ini_register();
}

void kg_config_ssh_over_quic_shutdown(void)
{
    kg_config_ssh_over_quic_ini_unregister();
}
