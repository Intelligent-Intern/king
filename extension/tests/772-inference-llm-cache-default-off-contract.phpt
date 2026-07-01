--TEST--
King inference LLM cache is disabled by default until explicitly configured
--INI--
king.security_allow_config_override=1
--FILE--
<?php
var_dump(ini_get('king.inference_with_memory'));
var_dump(ini_get('king.inference_llm_cache_enable'));

$defaultConfig = King\Config::new();
$defaultSnapshot = $defaultConfig->toArray();
var_dump($defaultConfig->get('inference.with_memory'));
var_dump($defaultConfig->get('inference.llm_cache_enable'));
var_dump($defaultSnapshot['inference.with_memory']);
var_dump($defaultSnapshot['inference.llm_cache_enable']);

$statusWithoutMemory = king_inference_llm_cache_status();
var_dump($statusWithoutMemory['enabled']);
var_dump($statusWithoutMemory['active']);
var_dump($statusWithoutMemory['with_memory']);
var_dump($statusWithoutMemory['ok']);
var_dump($statusWithoutMemory['degraded']);
var_dump($statusWithoutMemory['action']);

$statusWithMemoryRequest = king_inference_llm_cache_status(null, ['with_memory' => true]);
var_dump($statusWithMemoryRequest['enabled']);
var_dump($statusWithMemoryRequest['active']);
var_dump($statusWithMemoryRequest['with_memory']);
var_dump($statusWithMemoryRequest['ok']);
var_dump($statusWithMemoryRequest['degraded']);
var_dump($statusWithMemoryRequest['action']);
var_dump($statusWithMemoryRequest['path']);
var_dump($statusWithMemoryRequest['min_free_mb']);
var_dump($statusWithMemoryRequest['fail_closed']);
var_dump($statusWithMemoryRequest['alert']['requested']);

$enabledConfig = King\Config::new([
    'inference.with_memory' => true,
    'inference.llm_cache_enable' => true,
]);
$enabledStatus = king_inference_llm_cache_status($enabledConfig, ['with_memory' => true]);
var_dump($enabledConfig->get('inference.llm_cache_enable'));
var_dump($enabledStatus['enabled']);
var_dump($enabledStatus['active']);
var_dump($enabledStatus['with_memory']);
var_dump(is_bool($enabledStatus['ok']));
var_dump(is_string($enabledStatus['action']));
?>
--EXPECT--
string(1) "0"
string(1) "0"
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(true)
bool(false)
string(23) "disabled_without_memory"
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
string(18) "disabled_by_config"
string(19) "/tmp/king-llm-cache"
int(5120)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
