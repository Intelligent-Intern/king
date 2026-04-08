--TEST--
King coordinated runtime gates new websocket upgrades and accepted peers while the system is not ready
--FILE--
<?php
function king_system_readiness_pick_tcp_port(): int
{
    $server = stream_socket_server(
        'tcp://127.0.0.1:0',
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
    );
    if ($server === false) {
        throw new RuntimeException("failed to reserve websocket test port: $errstr");
    }

    $address = stream_socket_get_name($server, false);
    fclose($server);
    [, $port] = explode(':', $address, 2);

    return (int) $port;
}

$blockedUpgrade = [];
$readyStatus = [];
$acceptExceptionClass = '';
$acceptExceptionMessage = '';

var_dump(king_system_init(['component_timeout_seconds' => 1]));
var_dump(king_system_restart_component('telemetry'));

$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['admission']['websocket_upgrades']);
var_dump($status['admission']['websocket_peer_accepts']);

var_dump(king_http1_server_listen(
    '127.0.0.1',
    8080,
    null,
    static function (array $request) use (&$blockedUpgrade): array {
        $upgrade = king_server_upgrade_to_websocket(
            $request['session'],
            $request['stream_id']
        );
        $blockedUpgrade = [
            'is_resource' => is_resource($upgrade),
            'error' => king_get_last_error(),
            'stats' => king_get_stats($request['session']),
        ];

        return [
            'status' => 426,
            'headers' => [
                'Content-Type' => 'text/plain',
            ],
            'body' => 'upgrade-blocked',
        ];
    }
));
var_dump($blockedUpgrade['is_resource']);
var_dump(str_contains($blockedUpgrade['error'], 'cannot admit websocket_upgrades'));
var_dump(str_contains($blockedUpgrade['error'], "lifecycle is 'draining'"));
var_dump($blockedUpgrade['stats']['server_websocket_upgrade_count']);

$server = new King\WebSocket\Server('127.0.0.1', king_system_readiness_pick_tcp_port());
try {
    $server->accept();
} catch (Throwable $e) {
    $acceptExceptionClass = get_class($e);
    $acceptExceptionMessage = $e->getMessage();
}

var_dump($acceptExceptionClass);
var_dump(str_contains($acceptExceptionMessage, 'cannot admit websocket_peer_accepts'));
var_dump(str_contains($acceptExceptionMessage, "lifecycle is 'draining'"));

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['admission']['websocket_upgrades']);
var_dump($status['admission']['websocket_peer_accepts']);

sleep(1);
$readyStatus = king_system_get_status();
var_dump($readyStatus['lifecycle']);
var_dump($readyStatus['admission']['websocket_upgrades']);
var_dump($readyStatus['admission']['websocket_peer_accepts']);
var_dump(king_system_shutdown());
var_dump(king_system_get_status()['initialized']);
?>
--EXPECTF--
bool(true)
bool(true)
string(8) "draining"
bool(false)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
int(0)
string(%d) "King\RuntimeException"
bool(true)
bool(true)
string(8) "starting"
bool(false)
bool(false)
string(5) "ready"
bool(true)
bool(true)
bool(true)
bool(false)
