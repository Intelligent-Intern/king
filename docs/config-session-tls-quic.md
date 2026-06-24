# Config, Session, TLS und QUIC

`King\Config` ist die OO-Konfiguration. `king_new_config()` ist die
procedural Variante. Low-level Sessions werden mit `king_connect()` oder
`new King\Session(...)` aufgebaut.

## Function, Beispiel 1: Config Resource und Session Stats

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

## Function, Beispiel 2: TLS Defaults und Session Tickets

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

## OO, Beispiel 1: Config setzen und auslesen

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

## OO, Beispiel 2: Session, Stream, Response und CancelToken

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
