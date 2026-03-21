#include "include/config/app_http3_websockets_webtransport/default.h"
#include "include/config/app_http3_websockets_webtransport/base_layer.h"

void kg_config_app_http3_websockets_webtransport_defaults_load(void)
{
    king_app_protocols_config.http_advertise_h3_alt_svc = true;
    king_app_protocols_config.http_auto_compress = pestrdup("brotli,gzip", 1);
    king_app_protocols_config.h3_max_header_list_size = 65536;
    king_app_protocols_config.h3_qpack_max_table_capacity = 4096;
    king_app_protocols_config.h3_qpack_blocked_streams = 100;
    king_app_protocols_config.h3_server_push_enable = false;
    king_app_protocols_config.http_enable_early_hints = true;

    king_app_protocols_config.websocket_default_max_payload_size = 16777216;
    king_app_protocols_config.websocket_default_ping_interval_ms = 25000;
    king_app_protocols_config.websocket_handshake_timeout_ms = 5000;

    king_app_protocols_config.webtransport_enable = true;
    king_app_protocols_config.webtransport_max_concurrent_sessions = 10000;
    king_app_protocols_config.webtransport_max_streams_per_session = 256;
}
