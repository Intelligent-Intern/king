# WebSocket

The WebSocket client can be used procedurally through
`king_client_websocket_*` or through `King\WebSocket\Connection`. On the
server side, procedural upgrade is available through
`king_server_upgrade_to_websocket()` and OO through `King\WebSocket\Server`.

## Internal Layout

The shared WebSocket state and connection object contract live in
`extension/include/client/websocket.h`. The server object contract lives in
`extension/include/server/websocket.h`. Client runtime code lives under
`extension/src/client/websocket/`; server upgrade and `King\WebSocket\Server`
runtime code live under `extension/src/server/`.

Client-side WebSocket function registrations are owned by the client binding
fragments in `extension/src/client/arginfo.inc` and
`extension/src/client/function_entries.inc`. Server-side WebSocket upgrade and
listener registrations remain with the server binding surface.

## Function, Example 1: Client Echo

```php
<?php
$ws = king_client_websocket_connect(
    'ws://127.0.0.1:8081/socket',
    ['x-client' => 'invoice-console'],
    ['max_payload_size' => 1024 * 1024, 'handshake_timeout_ms' => 1500]
);

if ($ws === false) {
    throw new RuntimeException(king_client_websocket_get_last_error());
}

king_client_websocket_send($ws, json_encode([
    'type' => 'ping',
    'request_id' => 'req-1001',
], JSON_THROW_ON_ERROR));

$payload = king_client_websocket_receive($ws, 1000);
var_dump($payload);

king_client_websocket_close($ws, 1000, 'done');
```

## Function, Example 2: HTTP/1 Upgrade in a Handler

```php
<?php
king_http1_server_listen_once('127.0.0.1', 8081, null, static function (array $request): array {
    $session = $request['session'];
    $streamId = (int) $request['stream_id'];

    $ws = king_server_upgrade_to_websocket($session, $streamId);
    if ($ws === false) {
        return [
            'status' => 400,
            'headers' => ['content-type' => 'application/json'],
            'body' => '{"error":"websocket_upgrade_failed"}',
        ];
    }

    king_websocket_send($ws, json_encode([
        'type' => 'connected',
        'connection_id' => $request['headers']['sec-websocket-key'][0] ?? null,
    ], JSON_THROW_ON_ERROR));

    return ['status' => 101, 'headers' => [], 'body' => ''];
});
```

## OO, Example 1: Connection

```php
<?php
use King\WebSocket\Connection;

$connection = new Connection('ws://127.0.0.1:8081/socket', [
    'x-client' => 'ops-console',
]);

$connection->send(json_encode(['type' => 'subscribe', 'topic' => 'invoices'], JSON_THROW_ON_ERROR));
$message = $connection->receive(1000);

echo $message . PHP_EOL;
$connection->close(1000, 'done');
```

## OO, Example 2: Server with Broadcast

```php
<?php
use King\WebSocket\Server;

$server = new Server('127.0.0.1', 8081);

$connection = $server->accept();
$info = $connection->getInfo();

$connection->send(json_encode([
    'type' => 'accepted',
    'connection_id' => $info['connection_id'],
], JSON_THROW_ON_ERROR));

$server->broadcast(json_encode([
    'type' => 'system',
    'message' => 'maintenance-window-open',
], JSON_THROW_ON_ERROR));

$server->stop();
```
