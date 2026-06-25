# Config, Session, TLS, and QUIC

`King\Config` is the OO configuration surface. `king_new_config()` is the
procedural variant. Low-level sessions are created with `king_connect()` or
`new King\Session(...)`.

## Internal Layout

The config include-side umbrella lives in `extension/include/config/index.h`.
The native config snapshot lives in `extension/include/config/config.h`; the
PHP-visible `King\Config` object contract lives in
`extension/include/config/object.h`. `King\CancelToken` is shared across
client, awaitable, inference, MCP, and orchestrator paths, so its object
contract lives in `extension/include/awaitable/cancel_token.h`.

Client session, TLS, request, and polling function registrations are owned by
`extension/src/client/arginfo.inc` and
`extension/src/client/function_entries.inc`, with the public include-side anchor
at `extension/include/client/arginfo/index.h`.

Server session, TLS reload, cancellation, and admin listener registrations are
owned by `extension/src/server/arginfo.inc` and
`extension/src/server/function_entries.inc`, with the public include-side anchor
at `extension/include/server/arginfo/index.h`.

Core exception, class, and object-handler registration is factored into
`extension/src/php_king/class_registration.inc`; `extension/src/php_king/lifecycle.inc`
keeps the MINIT/MSHUTDOWN/RINIT/RSHUTDOWN ordering.

## Function, Example 1: Config Resource and Session Stats

```php
<?php
$config = king_new_config([
    'tcp.enable' => true,
    'tls.ca_file' => __DIR__ . '/certs/ca.pem',
    'quic.ping_interval_ms' => 1000,
]);

$session = king_connect('127.0.0.1', 443, $config);
if ($session === false) {
    throw new RuntimeException(king_get_last_error());
}

king_poll($session, 50);
$stats = king_get_stats($session);

printf("state=%s tx=%d rx=%d\n",
    $stats['state'] ?? 'unknown',
    $stats['transport_tx_bytes'] ?? 0,
    $stats['transport_rx_bytes'] ?? 0
);

king_close($session);
```

## Function, Example 2: TLS Defaults and Session Tickets

```php
<?php
king_client_tls_set_ca_file(__DIR__ . '/certs/ca.pem');
king_client_tls_set_client_cert(
    __DIR__ . '/certs/client.pem',
    __DIR__ . '/certs/client-key.pem'
);

$first = king_connect('gateway.internal.local', 443, [
    'tls.enable_early_data' => true,
    'tcp.connect_timeout_ms' => 1500,
]);

$ticket = king_client_tls_export_session_ticket($first);
king_close($first);

$second = king_connect('gateway.internal.local', 443, [
    'tls.enable_early_data' => true,
]);

king_client_tls_import_session_ticket($second, $ticket);
king_poll($second, 20);

$stats = king_get_stats($second);
echo ($stats['tls_ticket_source'] ?? 'none') . PHP_EOL;

king_close($second);
```

## OO, Example 1: Set and Read Config

```php
<?php
use King\Config;

$config = Config::new([
    'http2.enable' => true,
    'tls.ca_file' => __DIR__ . '/certs/ca.pem',
]);

$config->set('cdn.allowed_http_methods', 'GET,HEAD,QUERY');

var_dump($config->get('http2.enable'));
var_dump($config->toArray()['cdn.allowed_http_methods']);
```

## OO, Example 2: Session, Stream, Response, and CancelToken

```php
<?php
use King\CancelToken;
use King\Config;
use King\Session;

$config = new Config([
    'tls.ca_file' => __DIR__ . '/certs/ca.pem',
    'quic.ping_interval_ms' => 500,
]);

$session = new Session('invoice-api.internal.local', 443, $config, [
    'sni' => 'invoice-api.internal.local',
]);

$cancel = new CancelToken();
$stream = $session->sendRequest(
    'GET',
    '/v1/health',
    ['accept' => 'application/json'],
    '',
    $cancel
);

$response = $stream->receiveResponse(2000, $cancel);
if ($response !== null) {
    echo $response->getStatusCode() . PHP_EOL;
    echo $response->getBody() . PHP_EOL;
}

var_dump($session->stats());
$session->close();
```
