--TEST--
Repo-local Flow PHP MCP host provides startup dispatch and shutdown/error lifecycle behavior
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/McpHost.php';

use King\Flow\McpHost;
use King\Flow\McpHostRequest;
use King\Flow\McpHostResponse;

function king_flow_mcp_host_decode_response(string $line): array
{
    if ($line === '') {
        return ['opcode' => '', 'payload' => null];
    }

    $parts = explode("\t", $line, 2);
    $payload = null;

    if (isset($parts[1])) {
        $decoded = base64_decode($parts[1], true);
        $payload = $decoded === false ? null : $decoded;
    }

    return [
        'opcode' => $parts[0],
        'payload' => $payload,
    ];
}

$host = new McpHost('127.0.0.1', 0);
$host->start();

var_dump($host->isRunning());
var_dump($host->port() > 0);

$client = @stream_socket_client('tcp://127.0.0.1:' . $host->port(), $errno, $errstr, 1.0);
if (!is_resource($client)) {
    throw new RuntimeException('failed to connect to MCP host: ' . $errstr);
}

stream_set_blocking($client, true);

$frames = [
    "REQ\t" . base64_encode('svc') . "\t" . base64_encode('ping') . "\t" . base64_encode('hello') . "\t250\t400",
    "PUT\t" . base64_encode('svc') . "\t" . base64_encode('blob') . "\t" . base64_encode('run-1') . "\t" . base64_encode('blob-data'),
    "GET\t" . base64_encode('svc') . "\t" . base64_encode('blob') . "\t" . base64_encode('run-1'),
    "GET\t" . base64_encode('svc') . "\t" . base64_encode('blob') . "\t" . base64_encode('missing'),
    "REQ\t" . base64_encode('svc') . "\t" . base64_encode('explode') . "\t" . base64_encode('x'),
    "BANG\tbad",
    'STOP',
];

fwrite($client, implode("\n", $frames) . "\n");
fflush($client);

$uploads = [];
$seen = [];

$result = $host->serve(
    static function (McpHostRequest $request) use (&$uploads, &$seen): McpHostResponse {
        $seen[] = $request->toArray();

        if ($request->operation() === 'upload') {
            $uploads[(string) $request->streamIdentifier()] = (string) $request->payload();
            return McpHostResponse::ok();
        }

        if ($request->operation() === 'download') {
            $identifier = (string) $request->streamIdentifier();
            if (!array_key_exists($identifier, $uploads)) {
                return McpHostResponse::miss();
            }

            return McpHostResponse::ok($uploads[$identifier]);
        }

        if ($request->method() === 'explode') {
            throw new RuntimeException('forced handler failure.');
        }

        return McpHostResponse::ok(
            json_encode(
                [
                    'service' => $request->service(),
                    'method' => $request->method(),
                    'payload' => $request->payload(),
                ],
                JSON_UNESCAPED_SLASHES
            )
        );
    },
    null,
    20
);

$rawResponses = (string) stream_get_contents($client);
fclose($client);

$lines = array_values(array_filter(
    explode("\n", str_replace("\r", '', trim($rawResponses))),
    static fn (string $line): bool => $line !== ''
));

$response0 = king_flow_mcp_host_decode_response($lines[0] ?? '');
$response2 = king_flow_mcp_host_decode_response($lines[2] ?? '');
$response4 = king_flow_mcp_host_decode_response($lines[4] ?? '');
$response5 = king_flow_mcp_host_decode_response($lines[5] ?? '');

var_dump(count($lines) === 7);
var_dump(count($seen) === 5);
var_dump(($seen[0]['timeout_budget_ms'] ?? null) === 250);
var_dump(($seen[0]['deadline_budget_ms'] ?? null) === 400);
var_dump(($response0['opcode'] ?? null) === 'OK');
var_dump(str_contains((string) ($response0['payload'] ?? ''), '"method":"ping"'));
var_dump(($lines[1] ?? null) === 'OK');
var_dump(($response2['opcode'] ?? null) === 'OK');
var_dump(($response2['payload'] ?? null) === 'blob-data');
var_dump(($lines[3] ?? null) === 'MISS');
var_dump(($response4['opcode'] ?? null) === 'ERR');
var_dump(str_contains((string) ($response4['payload'] ?? ''), 'forced handler failure'));
var_dump(($response5['opcode'] ?? null) === 'ERR');
var_dump(str_contains((string) ($response5['payload'] ?? ''), 'unsupported command frame'));
var_dump(($lines[6] ?? null) === 'OK');

var_dump($result->connectionsAccepted() === 1);
var_dump($result->commandsHandled() === 7);
var_dump($result->protocolErrors() === 1);
var_dump($result->handlerErrors() === 1);
var_dump($result->stopReason() === 'stop_command');
var_dump($host->isRunning() === false);

$serveAfterShutdownThrows = false;
try {
    $host->serve(static fn (McpHostRequest $request): McpHostResponse => McpHostResponse::ok(), 1, 10);
} catch (RuntimeException $error) {
    $serveAfterShutdownThrows = str_contains($error->getMessage(), 'not running');
}

var_dump($serveAfterShutdownThrows);
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
bool(true)
