--TEST--
King telemetry runtime status and storage status functions expose live defaults
--FILE--
<?php
$telemetry = king_telemetry_get_status();
var_dump(array_keys($telemetry));
var_dump($telemetry['initialized']);
var_dump($telemetry['flush_count']);
var_dump($telemetry['active_metrics']);
var_dump($telemetry['last_export_diagnostic']);

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
var_dump($storage['object_store']['metadata_cache_max_entries']);
var_dump($storage['object_store']['directstorage_enabled']);
var_dump($storage['object_store']['runtime_metadata_cache_entries']);
var_dump($storage['object_store']['runtime_metadata_cache_eviction_count']);
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
array(25) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(11) "flush_count"
  [2]=>
  string(14) "active_metrics"
  [3]=>
  string(21) "metric_registry_limit"
  [4]=>
  string(17) "metric_drop_count"
  [5]=>
  string(10) "queue_size"
  [6]=>
  string(20) "export_success_count"
  [7]=>
  string(20) "export_failure_count"
  [8]=>
  string(16) "queue_drop_count"
  [9]=>
  string(19) "pending_entry_limit"
  [10]=>
  string(18) "pending_span_count"
  [11]=>
  string(17) "pending_log_count"
  [12]=>
  string(18) "pending_drop_count"
  [13]=>
  string(11) "queue_bytes"
  [14]=>
  string(13) "pending_bytes"
  [15]=>
  string(12) "memory_bytes"
  [16]=>
  string(17) "memory_byte_limit"
  [17]=>
  string(20) "queue_high_watermark"
  [18]=>
  string(22) "queue_high_water_bytes"
  [19]=>
  string(23) "memory_high_water_bytes"
  [20]=>
  string(19) "retry_requeue_count"
  [21]=>
  string(30) "metric_registry_high_watermark"
  [22]=>
  string(17) "last_flush_cpu_ns"
  [23]=>
  string(23) "flush_cpu_high_water_ns"
  [24]=>
  string(22) "last_export_diagnostic"
}
bool(false)
int(0)
int(0)
NULL
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
int(4096)
bool(false)
int(0)
int(0)
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
