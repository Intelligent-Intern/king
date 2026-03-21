#include "include/config/native_cdn/default.h"
#include "include/config/native_cdn/base_layer.h"

static char *king_persistent_strdup(const char *value)
{
    return pestrdup(value, 1);
}

void kg_config_native_cdn_defaults_load(void)
{
    king_native_cdn_config.enable = false;
    king_native_cdn_config.cache_mode = king_persistent_strdup("disk");
    king_native_cdn_config.cache_memory_limit_mb = 512;
    king_native_cdn_config.cache_disk_path = king_persistent_strdup("/var/cache/king_cdn");
    king_native_cdn_config.cache_default_ttl_sec = 86400;
    king_native_cdn_config.cache_max_object_size_mb = 1024;
    king_native_cdn_config.cache_respect_origin_headers = true;
    king_native_cdn_config.cache_vary_on_headers = king_persistent_strdup("Accept-Encoding");
    king_native_cdn_config.origin_mcp_endpoint = king_persistent_strdup("");
    king_native_cdn_config.origin_http_endpoint = king_persistent_strdup("");
    king_native_cdn_config.origin_request_timeout_ms = 15000;
    king_native_cdn_config.serve_stale_on_error = true;
    king_native_cdn_config.response_headers_to_add = king_persistent_strdup("X-Cache-Status: HIT");
    king_native_cdn_config.allowed_http_methods = king_persistent_strdup("GET,HEAD");
}
