#include "include/config/http2/default.h"
#include "include/config/http2/base_layer.h"

void kg_config_http2_defaults_load(void)
{
    king_http2_config.enable = true;
    king_http2_config.initial_window_size = 65535;
    king_http2_config.max_concurrent_streams = 100;
    king_http2_config.max_header_list_size = 0;
    king_http2_config.enable_push = true;
    king_http2_config.max_frame_size = 16384;
}
