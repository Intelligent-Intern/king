#include "include/config/tcp_transport/default.h"
#include "include/config/tcp_transport/base_layer.h"

void kg_config_tcp_transport_defaults_load(void)
{
    king_tcp_transport_config.enable = true;
    king_tcp_transport_config.max_connections = 10240;
    king_tcp_transport_config.connect_timeout_ms = 5000;
    king_tcp_transport_config.listen_backlog = 511;
    king_tcp_transport_config.reuse_port_enable = true;
    king_tcp_transport_config.nodelay_enable = false;
    king_tcp_transport_config.cork_enable = false;
    king_tcp_transport_config.keepalive_enable = true;
    king_tcp_transport_config.keepalive_time_sec = 7200;
    king_tcp_transport_config.keepalive_interval_sec = 75;
    king_tcp_transport_config.keepalive_probes = 9;
    king_tcp_transport_config.tls_min_version_allowed = pestrdup("TLSv1.2", 1);
    king_tcp_transport_config.tls_ciphers_tls12 = pestrdup(
        "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384",
        1);
}
