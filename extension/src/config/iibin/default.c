#include "include/config/iibin/default.h"
#include "include/config/iibin/base_layer.h"

void kg_config_iibin_defaults_load(void)
{
    king_iibin_config.max_schema_fields = 256;
    king_iibin_config.max_recursion_depth = 32;
    king_iibin_config.string_interning_enable = true;
    king_iibin_config.use_shared_memory_buffers = false;
    king_iibin_config.default_buffer_size_kb = 64;
    king_iibin_config.shm_total_memory_mb = 256;
    king_iibin_config.shm_path = NULL;
}
