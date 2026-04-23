--TEST--
Repo-local Flow PHP SQL/pgvector bridge keeps the boundary non-native and enforces MCP request/response shape
--SKIPIF--
<?php
require __DIR__ . '/skipif_capability.inc';

king_skipif_require_functions([
    'proc_open',
    'stream_socket_server',
    'king_mcp_connect',
    'king_mcp_request',
    'king_mcp_close',
]);
king_skipif_require_loopback_bind('tcp');
?>
--FILE--
<?php
require_once __DIR__ . '/mcp_test_helper.inc';
require_once __DIR__ . '/../../demo/userland/flow-php/src/SqlVectorBridge.php';

use King\Flow\McpResourceSqlVectorTransport;
use King\Flow\McpSqlVectorBridge;
use King\Flow\SqlVectorBridgeTransport;
use King\Flow\SqlVectorSearchRequest;

final class InvalidResultTransport implements SqlVectorBridgeTransport
{
    public function request(string $service, string $method, string $payload, array $options = []): string
    {
        return '{"schema":"broken"}';
    }
}

$server = king_mcp_test_start_server_script(__DIR__ . '/sql_vector_bridge_mcp_server.inc');
$capture = [];

try {
    $connection = king_mcp_connect('127.0.0.1', (int) $server['port'], null);
    var_dump(is_resource($connection));

    $bridge = new McpSqlVectorBridge(new McpResourceSqlVectorTransport($connection));
    $request = new SqlVectorSearchRequest(
        'docs_embeddings',
        [0.12, -0.41, 0.77],
        2,
        ['tenant' => 'acme', 'lang' => 'de'],
        'req-671'
    );

    $response = $bridge->search($request, ['timeout_ms' => 1200, 'deadline_ms' => ((int) (hrtime(true) / 1000000)) + 5000]);
    $result = $response->toArray();

    var_dump(($result['schema'] ?? null) === 'king.sql_vector.result.v1');
    var_dump(($result['request_id'] ?? null) === 'req-671');
    var_dump(($result['index'] ?? null) === 'docs_embeddings');
    var_dump(count($result['matches'] ?? []) === 2);
    var_dump(($result['matches'][0]['id'] ?? null) === 'doc-1');
    var_dump(($result['matches'][0]['metadata']['tenant'] ?? null) === 'acme');
    var_dump(($result['stats']['backend'] ?? null) === 'pgvector');
    var_dump(($result['stats']['engine'] ?? null) === 'postgresql');

    var_dump(king_mcp_close($connection));
} finally {
    $capture = king_mcp_test_stop_server($server);
}

$events = is_array($capture['events'] ?? null) ? $capture['events'] : [];
$requestEvent = $events[0] ?? [];
$requestPayload = is_array($requestEvent['payload'] ?? null) ? $requestEvent['payload'] : [];

var_dump(($requestEvent['service'] ?? null) === 'sql_vector');
var_dump(($requestEvent['method'] ?? null) === 'search');
var_dump(($requestEvent['timeout_budget_ms'] ?? 0) > 0);
var_dump(($requestEvent['deadline_budget_ms'] ?? 0) > 0);
var_dump(($requestPayload['schema'] ?? null) === 'king.sql_vector.query.v1');
var_dump(($requestPayload['operation'] ?? null) === 'similarity_search');
var_dump(($requestPayload['request_id'] ?? null) === 'req-671');
var_dump(($requestPayload['index'] ?? null) === 'docs_embeddings');
var_dump(($requestPayload['limit'] ?? null) === 2);
var_dump(($requestPayload['filters']['tenant'] ?? null) === 'acme');
var_dump(($requestPayload['filters']['lang'] ?? null) === 'de');
var_dump(($requestPayload['query_vector'] ?? null) === [0.12, -0.41, 0.77]);

$invalidBridge = new McpSqlVectorBridge(new InvalidResultTransport());
$invalidFailedClosed = false;
try {
    $invalidBridge->search(new SqlVectorSearchRequest('docs_embeddings', [1.0], 1, [], 'req-671-invalid'));
} catch (InvalidArgumentException $error) {
    $invalidFailedClosed = str_contains($error->getMessage(), 'king.sql_vector.result.v1');
}

var_dump($invalidFailedClosed);
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
