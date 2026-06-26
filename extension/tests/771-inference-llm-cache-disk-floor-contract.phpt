--TEST--
King memory-enabled inference checks LLM cache disk floor before streaming
--INI--
king.security_allow_config_override=1
--SKIPIF--
<?php
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $candidate = dirname(__DIR__, 2) . '/var/inference-models/gemma3-1b.gguf';
    $modelPath = is_file($candidate) ? $candidate : '';
}
if ($modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_TEST_MODEL_PATH must point to a local GGUF model artifact\n";
}
?>
--FILE--
<?php
$root = dirname(__DIR__, 2);
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = $root . '/var/inference-models/gemma3-1b.gguf';
}
$cachePath = sys_get_temp_dir() . '/king-llm-cache-floor-' . getmypid();
$minFreeMb = 999999999;

function llmCacheGraph(): array
{
    return [
        'inputs' => ['token' => [1]],
        'ops' => [[
            'id' => 'next_token',
            'op' => 'scale',
            'input' => 'token',
            'factor' => 1.0,
        ]],
        'output' => 'next_token',
    ];
}

function llmCacheModel(string $modelPath, string $cachePath, int $minFreeMb, bool $failClosed): King\Inference\Model
{
    return king_inference_model_load([
        'name' => $failClosed ? 'llm-cache-fail-closed' : 'llm-cache-fail-open',
        'artifact' => ['path' => $modelPath],
        'backend' => 'king_native_cpu',
        'llm_cache' => [
            'enabled' => true,
            'path' => $cachePath,
            'min_free_mb' => $minFreeMb,
            'fail_closed' => $failClosed,
            'disk_alert_webhook' => 'https://ops.example/cache-alert',
            'disk_alert_mcp_service' => 'ops.cache',
            'disk_alert_mcp_method' => 'diskFloorWarning',
        ],
    ]);
}

try {
    $config = King\Config::new([
        'inference.with_memory' => true,
        'inference.llm_cache_enable' => true,
        'inference.llm_cache_path' => $cachePath,
        'inference.llm_cache_min_free_mb' => $minFreeMb,
        'inference.llm_cache_fail_closed' => true,
        'inference.llm_cache_disk_alert_webhook' => 'https://ops.example/cache-alert',
        'inference.llm_cache_disk_alert_mcp_service' => 'ops.cache',
        'inference.llm_cache_disk_alert_mcp_method' => 'diskFloorWarning',
    ]);
    $status = king_inference_llm_cache_status($config, ['with_memory' => true]);

    var_dump($status['type']);
    var_dump($status['enabled']);
    var_dump($status['active']);
    var_dump($status['with_memory']);
    var_dump($status['path'] === $cachePath);
    var_dump($status['min_free_mb']);
    var_dump($status['ok']);
    var_dump($status['degraded']);
    var_dump($status['action']);
    var_dump($status['free_bytes'] > 0);
    var_dump($status['free_mb'] > 0);
    var_dump($status['alert']['requested']);
    var_dump($status['alert']['webhook_configured']);
    var_dump($status['alert']['mcp_configured']);

    $closedModel = llmCacheModel($modelPath, $cachePath, $minFreeMb, true);
    try {
        new King\Inference\Stream($closedModel, ['graphs' => [llmCacheGraph()]], [
            'with_memory' => true,
            'max_native_stream_tokens' => 2,
        ]);
        var_dump('fail-closed-accepted');
    } catch (Throwable $exception) {
        var_dump(get_class($exception));
        var_dump(str_contains($exception->getMessage(), 'LLM cache disk floor'));
    }

    $openModel = llmCacheModel($modelPath, $cachePath, $minFreeMb, false);
    $stream = new King\Inference\Stream($openModel, ['graphs' => [llmCacheGraph()]], [
        'with_memory' => true,
        'max_native_stream_tokens' => 2,
    ]);
    $start = $stream->next(0);
    $cacheEvent = $stream->next(0);

    var_dump($start['type']);
    var_dump($cacheEvent['type']);
    var_dump($cacheEvent['active']);
    var_dump($cacheEvent['ok']);
    var_dump($cacheEvent['degraded']);
    var_dump($cacheEvent['action']);
    var_dump($cacheEvent['alert']['requested']);
    var_dump($cacheEvent['alert']['webhook']);
    var_dump($cacheEvent['alert']['mcp_service']);
    var_dump($cacheEvent['alert']['mcp_method']);
} finally {
    if (is_dir($cachePath)) {
        @rmdir($cachePath);
    }
}
?>
--EXPECT--
string(16) "llm_cache_status"
bool(true)
bool(true)
bool(true)
bool(true)
int(999999999)
bool(false)
bool(true)
string(17) "disk_floor_failed"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "King\RuntimeException"
bool(true)
string(5) "start"
string(16) "llm_cache_status"
bool(true)
bool(false)
bool(true)
string(17) "disk_floor_failed"
bool(true)
string(31) "https://ops.example/cache-alert"
string(9) "ops.cache"
string(16) "diskFloorWarning"
