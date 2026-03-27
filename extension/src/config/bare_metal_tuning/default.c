#include "include/config/bare_metal_tuning/default.h"
#include "include/config/bare_metal_tuning/base_layer.h"

void kg_config_bare_metal_tuning_defaults_load(void)
{
    king_bare_metal_config.io_engine_use_uring = true;
    king_bare_metal_config.io_uring_sq_poll_ms = 0;
    king_bare_metal_config.io_max_batch_read_packets = 64;
    king_bare_metal_config.io_max_batch_write_packets = 64;

    king_bare_metal_config.socket_receive_buffer_size = 2097152;
    king_bare_metal_config.socket_send_buffer_size = 2097152;
    king_bare_metal_config.socket_enable_busy_poll_us = 0;
    king_bare_metal_config.socket_enable_timestamping = true;

    king_bare_metal_config.io_thread_cpu_affinity = NULL;
    king_bare_metal_config.io_thread_numa_node_policy = NULL;
}
