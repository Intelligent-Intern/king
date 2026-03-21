#include "include/config/router_and_loadbalancer/default.h"
#include "include/config/router_and_loadbalancer/base_layer.h"

void kg_config_router_and_loadbalancer_defaults_load(void)
{
    king_router_loadbalancer_config.router_mode_enable = false;
    king_router_loadbalancer_config.hashing_algorithm = pestrdup("consistent_hash", 1);
    king_router_loadbalancer_config.connection_id_entropy_salt = pestrdup("change-this-to-a-long-random-string-in-production", 1);
    king_router_loadbalancer_config.backend_discovery_mode = pestrdup("static", 1);
    king_router_loadbalancer_config.backend_static_list = pestrdup("127.0.0.1:8443", 1);
    king_router_loadbalancer_config.backend_mcp_endpoint = pestrdup("127.0.0.1:9998", 1);
    king_router_loadbalancer_config.backend_mcp_poll_interval_sec = 10;
    king_router_loadbalancer_config.max_forwarding_pps = 1000000;
}
