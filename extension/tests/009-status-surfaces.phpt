--TEST--
King telemetry runtime status and storage status functions expose live defaults
--FILE--
<?php
$telemetry = king_telemetry_get_status();
var_dump(array_keys($telemetry));
var_dump($telemetry['initialized']);
var_dump($telemetry['flush_count']);
var_dump($telemetry['active_metrics']);

$storage = king_object_store_get_stats();
var_dump(array_keys($storage));
var_dump($storage['object_store']['enabled']);
var_dump($storage['object_store']['redundancy_mode']);
var_dump($storage['object_store']['replication_factor']);
var_dump($storage['object_store']['chunk_size_mb']);
var_dump($storage['object_store']['metadata_agent_uri']);
var_dump($storage['object_store']['node_discovery_mode']);
var_dump($storage['object_store']['metadata_cache_enabled']);
var_dump($storage['object_store']['metadata_cache_ttl_sec']);
var_dump($storage['object_store']['directstorage_enabled']);
var_dump($storage['object_store']['object_count']);
var_dump($storage['object_store']['stored_bytes']);
var_dump($storage['object_store']['latest_object_at']);
var_dump($storage['cdn']['enabled']);
var_dump($storage['cdn']['cache_mode']);
var_dump($storage['cdn']['cache_memory_limit_mb']);
var_dump($storage['cdn']['default_ttl_sec']);
var_dump($storage['cdn']['max_object_size_mb']);
var_dump($storage['cdn']['origin_mcp_endpoint']);
var_dump($storage['cdn']['origin_http_endpoint']);
var_dump($storage['cdn']['origin_request_timeout_ms']);
var_dump($storage['cdn']['serve_stale_on_error']);
var_dump($storage['cdn']['allowed_http_methods']);
var_dump($storage['cdn']['local_cache_initialized']);
var_dump($storage['cdn']['cached_object_count']);
var_dump($storage['cdn']['cached_bytes']);
var_dump($storage['cdn']['latest_cached_at']);
?>
--EXPECT--
array(3) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(11) "flush_count"
  [2]=>
  string(14) "active_metrics"
}
bool(false)
int(0)
int(0)
array(2) {
  [0]=>
  string(12) "object_store"
  [1]=>
  string(3) "cdn"
}
bool(false)
string(14) "erasure_coding"
int(3)
int(64)
string(14) "127.0.0.1:9701"
string(6) "static"
bool(true)
int(60)
bool(false)
int(0)
int(0)
NULL
bool(false)
string(4) "disk"
int(512)
int(86400)
int(1024)
string(0) ""
string(0) ""
int(15000)
bool(true)
string(8) "GET,HEAD"
bool(true)
int(0)
int(0)
NULL
