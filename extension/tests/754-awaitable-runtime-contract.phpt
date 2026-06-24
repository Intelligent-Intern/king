--TEST--
King Awaitable exposes procedural and OO async runtime contracts
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

function awaitable_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('unexpected input payload');
    }

    $input['history'][] = 'awaitable-handler';
    return ['output' => $input];
}

$awaitableReflection = new ReflectionClass(King\Awaitable::class);
var_dump($awaitableReflection->isInternal());
var_dump($awaitableReflection->isFinal());
var_dump($awaitableReflection->getConstructor()->isPrivate());

foreach ([
    'king_await',
    'king_awaitable_poll',
    'king_awaitable_cancel',
    'king_awaitable_status',
    'king_pipeline_orchestrator_run_async',
] as $function) {
    var_dump(function_exists($function));
}

foreach ([
    [King\Client\HttpClient::class, 'requestAsync'],
    [King\MCP::class, 'requestAsync'],
    [King\PipelineOrchestrator::class, 'runAsync'],
    [King\PipelineOrchestrator::class, 'dispatchAsync'],
    [King\WebSocket\Connection::class, 'sendAsync'],
    [King\WebSocket\Connection::class, 'receiveAsync'],
] as [$class, $method]) {
    $methodReflection = new ReflectionMethod($class, $method);
    var_dump($methodReflection->isPublic());
}

var_dump(king_pipeline_orchestrator_register_tool('awaitable-tool', [
    'model' => 'gpt-sim',
]));
var_dump(king_pipeline_orchestrator_register_handler('awaitable-tool', 'awaitable_handler'));

$awaitable = king_pipeline_orchestrator_run_async(
    ['history' => []],
    [['tool' => 'awaitable-tool']]
);
var_dump($awaitable instanceof King\Awaitable);
var_dump($awaitable->getStatus());
var_dump($awaitable->getOperation());
var_dump(king_awaitable_status($awaitable));
var_dump(king_awaitable_poll($awaitable, 0));
var_dump($awaitable->getStatus());
$result = $awaitable->await();
var_dump($result['history']);

$ooAwaitable = King\PipelineOrchestrator::runAsync(
    ['history' => []],
    [['tool' => 'awaitable-tool']]
);
var_dump($ooAwaitable instanceof King\Awaitable);
$ooResult = king_await($ooAwaitable);
var_dump($ooResult['history']);

$server = king_mcp_test_start_server();
try {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], []);
    $mcpAwaitable = king_mcp_request_async($connection, 'svc', 'ping', '{}');
    var_dump($mcpAwaitable instanceof King\Awaitable);
    var_dump(king_await($mcpAwaitable));
    king_mcp_close($connection);

    $mcp = new King\MCP('127.0.0.1', $server['port']);
    $ooMcpAwaitable = $mcp->requestAsync('svc', 'ping', '{}');
    var_dump($ooMcpAwaitable instanceof King\Awaitable);
    var_dump($ooMcpAwaitable->await());
    $mcp->close();
} finally {
    king_mcp_test_stop_server($server);
}

$cancelled = king_pipeline_orchestrator_run_async(
    ['history' => []],
    [['tool' => 'awaitable-tool']]
);
var_dump($cancelled->cancel());
var_dump($cancelled->isCancelled());
var_dump(king_awaitable_cancel($cancelled));
try {
    $cancelled->await();
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'cancelled'));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(7) "pending"
string(36) "king_pipeline_orchestrator_run_async"
string(7) "pending"
bool(true)
string(8) "resolved"
array(1) {
  [0]=>
  string(17) "awaitable-handler"
}
bool(true)
array(1) {
  [0]=>
  string(17) "awaitable-handler"
}
bool(true)
string(12) "{"res":"{}"}"
bool(true)
string(12) "{"res":"{}"}"
bool(true)
bool(true)
bool(false)
string(21) "King\RuntimeException"
bool(true)
