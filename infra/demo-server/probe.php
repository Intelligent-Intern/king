<?php
declare(strict_types=1);

function demo_probe_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function demo_probe_expect(bool $condition, string $message): void
{
    if (!$condition) {
        demo_probe_fail($message);
    }
}

function demo_probe_sleep_retry(int $attempt, int $maxAttempts, string $message): void
{
    if ($attempt >= $maxAttempts) {
        demo_probe_fail($message);
    }

    usleep(100000);
}

function demo_probe_get_option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $index => $argument) {
        if ($argument === $name) {
            return $argv[$index + 1] ?? $default;
        }
    }

    return $default;
}

function demo_probe_http_request(string $url, string $method = 'GET', int $maxAttempts = 20): array
{
    $lastMessage = '';

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $response = king_http1_request_send($url, $method);
            if (is_array($response)) {
                return $response;
            }

            $lastMessage = "HTTP request {$method} {$url} did not return a response array";
        } catch (Throwable $throwable) {
            $lastMessage = $throwable->getMessage();
        }

        demo_probe_sleep_retry(
            $attempt,
            $maxAttempts,
            "HTTP request {$method} {$url} failed after {$maxAttempts} attempts: {$lastMessage}"
        );
    }

    demo_probe_fail("HTTP request {$method} {$url} failed unexpectedly");
}

function demo_probe_websocket_connect(string $url, int $maxAttempts = 20)
{
    $lastMessage = '';

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $websocket = king_client_websocket_connect($url, null, [
                'handshake_timeout_ms' => 5000,
                'max_payload_size' => 1024 * 1024,
            ]);

            if (is_resource($websocket)) {
                return $websocket;
            }

            $lastMessage = king_client_websocket_get_last_error();
        } catch (Throwable $throwable) {
            $lastMessage = $throwable->getMessage();
        }

        demo_probe_sleep_retry(
            $attempt,
            $maxAttempts,
            "WebSocket connect {$url} failed after {$maxAttempts} attempts: {$lastMessage}"
        );
    }

    demo_probe_fail("WebSocket connect {$url} failed unexpectedly");
}

if (in_array('--self-check', $argv, true)) {
    demo_probe_expect(extension_loaded('king'), 'king extension is not loaded');
    demo_probe_expect(function_exists('king_http1_request_send'), 'king HTTP/1 client API is unavailable');
    demo_probe_expect(function_exists('king_client_websocket_connect'), 'king WebSocket client API is unavailable');
    fwrite(STDOUT, "self-check ok\n");
    exit(0);
}

$healthOnly = in_array('--health-only', $argv, true);
$baseUrl = demo_probe_get_option($argv, '--base-url', 'http://demo-server:8080');
$wsUrl = demo_probe_get_option($argv, '--ws-url', 'ws://demo-server:8080/ws');
$healthUrl = demo_probe_get_option($argv, '--health-url', rtrim((string) $baseUrl, '/') . '/health');

$health = demo_probe_http_request($healthUrl, 'GET');
demo_probe_expect(is_array($health), 'health request did not return a response array');
demo_probe_expect(($health['status'] ?? 0) === 200, 'health request did not return HTTP 200');
demo_probe_expect(str_contains((string) ($health['body'] ?? ''), '"ok":true'), 'health body did not contain ok=true');

if ($healthOnly) {
    fwrite(STDOUT, "health ok\n");
    exit(0);
}

$index = demo_probe_http_request(rtrim((string) $baseUrl, '/') . '/', 'GET');
demo_probe_expect(is_array($index), 'index request did not return a response array');
demo_probe_expect(($index['status'] ?? 0) === 200, 'index request did not return HTTP 200');
demo_probe_expect(
    str_contains((string) ($index['body'] ?? ''), '<!DOCTYPE html>')
    || str_contains((string) ($index['body'] ?? ''), '<html'),
    'index body did not look like HTML'
);

$websocket = demo_probe_websocket_connect($wsUrl);
demo_probe_expect(is_resource($websocket), 'websocket connect failed: ' . king_client_websocket_get_last_error());

$payload = random_bytes(128);
demo_probe_expect(
    king_client_websocket_send($websocket, $payload, true),
    'websocket send failed: ' . king_client_websocket_get_last_error()
);

$reply = king_client_websocket_receive($websocket, 5000);
demo_probe_expect($reply !== false, 'websocket receive failed: ' . king_client_websocket_get_last_error());
demo_probe_expect($reply === $payload, 'websocket echo payload mismatch');

demo_probe_expect(
    king_client_websocket_close($websocket, 1000, 'probe-done'),
    'websocket close failed: ' . king_client_websocket_get_last_error()
);

fwrite(STDOUT, "demo network probe ok\n");
