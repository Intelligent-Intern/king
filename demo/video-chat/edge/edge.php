<?php

declare(strict_types=1);

$httpHost = getenv('VIDEOCHAT_EDGE_HOST') ?: '0.0.0.0';
$httpPort = (int) (getenv('VIDEOCHAT_EDGE_HTTP_PORT') ?: '8080');
$httpsPort = (int) (getenv('VIDEOCHAT_EDGE_HTTPS_PORT') ?: '8443');
$domain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_DOMAIN') ?: getenv('VIDEOCHAT_V1_PUBLIC_HOST') ?: 'localhost')));
$apiDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_API_DOMAIN') ?: 'api.' . $domain)));
$wsDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_WS_DOMAIN') ?: 'ws.' . $domain)));
$sfuDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_SFU_DOMAIN') ?: 'sfu.' . $domain)));
$turnDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_TURN_DOMAIN') ?: 'turn.' . $domain)));
$cdnDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_CDN_DOMAIN') ?: 'cdn.' . $domain)));
$cdnAliasInput = trim((string) (getenv('VIDEOCHAT_EDGE_CDN_ALIASES') ?: 'cnd.' . $domain));
$cdnDomains = [$cdnDomain];
foreach (preg_split('/\s*,\s*/', $cdnAliasInput) ?: [] as $alias) {
    $alias = strtolower(trim((string) $alias));
    if ($alias !== '') {
        $cdnDomains[] = $alias;
    }
}
$cdnDomains = array_values(array_unique($cdnDomains));
$certFile = getenv('VIDEOCHAT_EDGE_CERT_FILE') ?: '/run/certs/live/fullchain.pem';
$keyFile = getenv('VIDEOCHAT_EDGE_KEY_FILE') ?: '/run/certs/live/privkey.pem';
$staticRoot = rtrim((string) (getenv('VIDEOCHAT_EDGE_STATIC_ROOT') ?: '/app/frontend-dist'), '/');
$apiUpstream = getenv('VIDEOCHAT_EDGE_API_UPSTREAM') ?: 'videochat-backend-v1:18080';
$wsUpstream = getenv('VIDEOCHAT_EDGE_WS_UPSTREAM') ?: 'videochat-backend-ws-v1:18080';
$sfuUpstream = getenv('VIDEOCHAT_EDGE_SFU_UPSTREAM') ?: 'videochat-backend-sfu-v1:18080';
$maxHeaderBytes = (int) (getenv('VIDEOCHAT_EDGE_MAX_HEADER_BYTES') ?: '65536');
$connectTimeout = (float) (getenv('VIDEOCHAT_EDGE_CONNECT_TIMEOUT_SECONDS') ?: '5');
$httpIdleTimeout = (int) (getenv('VIDEOCHAT_EDGE_HTTP_IDLE_TIMEOUT_SECONDS') ?: '60');
$wsIdleTimeout = (int) (getenv('VIDEOCHAT_EDGE_WS_IDLE_TIMEOUT_SECONDS') ?: '86400');
$writeStallTimeout = max(1.0, (float) (getenv('VIDEOCHAT_EDGE_WRITE_STALL_TIMEOUT_SECONDS') ?: '10'));
$zeroWriteSleepMicros = max(1000, (int) (getenv('VIDEOCHAT_EDGE_ZERO_WRITE_SLEEP_MICROS') ?: '10000'));
$readStallTimeout = max(1.0, (float) (getenv('VIDEOCHAT_EDGE_READ_STALL_TIMEOUT_SECONDS') ?: (string) $writeStallTimeout));
$maxChildren = (int) (getenv('VIDEOCHAT_EDGE_MAX_CHILDREN') ?: '512');
$assetVersion = trim((string) (getenv('VIDEOCHAT_ASSET_VERSION') ?: ''));

if ($httpPort < 1 || $httpPort > 65535 || $httpsPort < 1 || $httpsPort > 65535) {
    fwrite(STDERR, "[videochat-edge] invalid listener port configuration\n");
    exit(1);
}
if (!is_file($certFile) || !is_readable($certFile)) {
    fwrite(STDERR, "[videochat-edge] certificate is not readable: {$certFile}\n");
    exit(1);
}
if (!is_file($keyFile) || !is_readable($keyFile)) {
    fwrite(STDERR, "[videochat-edge] private key is not readable: {$keyFile}\n");
    exit(1);
}
if (!is_dir($staticRoot) || !is_readable($staticRoot . '/index.html')) {
    fwrite(STDERR, "[videochat-edge] frontend dist is not readable: {$staticRoot}\n");
    exit(1);
}

$log = static function (string $message): void {
    fwrite(STDERR, '[videochat-edge] ' . $message . "\n");
};

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certFile,
        'local_pk' => $keyFile,
        'disable_compression' => true,
        'honor_cipher_order' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => false,
        'SNI_enabled' => true,
    ],
    'socket' => [
        'so_reuseport' => false,
        'backlog' => 256,
    ],
]);

$httpServer = @stream_socket_server(
    "tcp://{$httpHost}:{$httpPort}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!is_resource($httpServer)) {
    fwrite(STDERR, "[videochat-edge] failed to bind HTTP listener {$httpHost}:{$httpPort}: {$errstr} ({$errno})\n");
    exit(1);
}

$httpsServer = @stream_socket_server(
    "tcp://{$httpHost}:{$httpsPort}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!is_resource($httpsServer)) {
    fclose($httpServer);
    fwrite(STDERR, "[videochat-edge] failed to bind HTTPS listener {$httpHost}:{$httpsPort}: {$errstr} ({$errno})\n");
    exit(1);
}

stream_set_blocking($httpServer, false);
stream_set_blocking($httpsServer, false);

$children = [];
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGCHLD, static function () use (&$children): void {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            unset($children[$pid]);
        }
    });
}

$normalizeHost = static function (string $host): string {
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }
    if (str_starts_with($host, '[')) {
        $end = strpos($host, ']');
        return $end === false ? $host : substr($host, 0, $end + 1);
    }
    $colon = strpos($host, ':');
    return $colon === false ? $host : substr($host, 0, $colon);
};

$parseUpstream = static function (string $upstream): array {
    $parts = explode(':', $upstream, 2);
    return [$parts[0] ?: '127.0.0.1', isset($parts[1]) ? (int) $parts[1] : 80];
};

$readRequestHead = static function ($client) use ($maxHeaderBytes, $readStallTimeout, $zeroWriteSleepMicros): array {
    $head = '';
    $deadline = microtime(true) + 10.0;
    $lastReadProgress = microtime(true);
    stream_set_blocking($client, false);

    while (microtime(true) < $deadline) {
        $read = [$client];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 1, 0);
        if ($ready === false) {
            return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'select_failed'];
        }
        if ($ready === 0) {
            continue;
        }

        $chunk = @fread($client, 8192);
        if ($chunk === false) {
            return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'read_failed'];
        }
        if ($chunk === '') {
            if (feof($client)) {
                return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'client_closed'];
            }
            if ((microtime(true) - $lastReadProgress) >= $readStallTimeout) {
                return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'read_stalled'];
            }
            usleep($zeroWriteSleepMicros);
            continue;
        }

        $lastReadProgress = microtime(true);
        $head .= $chunk;
        if (strlen($head) > $maxHeaderBytes) {
            return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'header_too_large'];
        }
        if (strpos($head, "\r\n\r\n") !== false) {
            return ['head' => $head, 'bytes_read' => strlen($head), 'reason' => 'complete'];
        }
    }

    return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'deadline'];
};

$parseRequest = static function (string $head) use ($normalizeHost): array {
    [$headerBlock] = explode("\r\n\r\n", $head, 2);
    $lines = preg_split('/\r\n/', $headerBlock) ?: [];
    $requestLine = array_shift($lines) ?: '';
    $parts = preg_split('/\s+/', trim($requestLine), 3) ?: [];
    $headers = [];

    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));
        $value = trim(substr($line, $colon + 1));
        if ($name !== '') {
            $headers[$name] = $value;
        }
    }

    $target = $parts[1] ?? '/';
    $path = '/';
    $queryPos = strpos($target, '?');
    $rawPath = $queryPos === false ? $target : substr($target, 0, $queryPos);
    if (is_string($rawPath) && $rawPath !== '') {
        $path = str_starts_with($rawPath, '/') ? $rawPath : '/' . $rawPath;
    }

    return [
        'method' => strtoupper((string) ($parts[0] ?? 'GET')),
        'target' => $target,
        'path' => $path,
        'host' => $normalizeHost((string) ($headers['host'] ?? '')),
        'headers' => $headers,
        'upgrade' => strtolower((string) ($headers['upgrade'] ?? '')),
    ];
};

$writeAll = static function ($stream, string $buffer): bool {
    $offset = 0;
    $length = strlen($buffer);

    while ($offset < $length) {
        $chunk = substr($buffer, $offset, 65536);
        $written = @fwrite($stream, $chunk);
        if ($written === false) {
            return false;
        }
        if ($written === 0) {
            $read = null;
            $write = [$stream];
            $except = null;
            $ready = @stream_select($read, $write, $except, 10, 0);
            if ($ready !== 1) {
                return false;
            }
            continue;
        }
        $offset += $written;
    }

    return true;
};

$writeResponse = static function ($client, int $status, string $reason, array $headers, string $body, bool $headOnly = false) use ($writeAll): void {
    $headers['Content-Length'] = (string) strlen($body);
    $headers['Connection'] = 'close';
    $response = "HTTP/1.1 {$status} {$reason}\r\n";
    foreach ($headers as $name => $value) {
        $response .= $name . ': ' . $value . "\r\n";
    }
    $response .= "\r\n";
    if (!$headOnly) {
        $response .= $body;
    }
    $writeAll($client, $response);
};

$contentType = static function (string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'html' => 'text/html; charset=utf-8',
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json', 'map' => 'application/json; charset=utf-8',
        'wasm' => 'application/wasm',
        'data', 'tflite', 'binarypb' => 'application/octet-stream',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        default => 'application/octet-stream',
    };
};

$serveStatic = static function ($client, array $request) use ($staticRoot, $writeResponse, $contentType, $cdnDomains, $assetVersion): void {
    $path = rawurldecode((string) $request['path']);
    $isCdnAsset = in_array($request['host'], $cdnDomains, true) || str_starts_with($path, '/cdn/');
    $corsHeaders = $isCdnAsset
        ? [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Origin, Accept, Content-Type, Range',
            'Access-Control-Max-Age' => '86400',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]
        : [];

    if ($request['method'] === 'OPTIONS') {
        $writeResponse($client, 204, 'No Content', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], '', true);
        return;
    }

    if (!in_array($request['method'], ['GET', 'HEAD'], true)) {
        $writeResponse($client, 405, 'Method Not Allowed', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], "Method Not Allowed\n");
        return;
    }

    if ($path === '/' || $path === '') {
        $path = '/index.html';
    }

    $candidate = realpath($staticRoot . '/' . ltrim($path, '/'));
    $rootReal = realpath($staticRoot);
    if ($candidate === false || $rootReal === false || !str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        if (str_starts_with($path, '/assets/') || preg_match('/\.[A-Za-z0-9]{1,12}$/', $path) === 1) {
            $writeResponse($client, 404, 'Not Found', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], "Not Found\n", $request['method'] === 'HEAD');
            return;
        }
        $candidate = $staticRoot . '/index.html';
    }

    $body = (string) @file_get_contents($candidate);
    $headers = [
        'Content-Type' => $contentType($candidate),
        'Cache-Control' => basename($candidate) === 'index.html'
            ? 'no-store'
            : 'public, max-age=31536000, immutable',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
    ] + $corsHeaders;
    if ($assetVersion !== '') {
        $headers['X-KingRT-Asset-Version'] = $assetVersion;
    }
    $writeResponse($client, 200, 'OK', $headers, $body, $request['method'] === 'HEAD');
};

$injectForwardHeaders = static function (string $head, array $request): string {
    $parts = explode("\r\n\r\n", $head, 2);
    $headerBlock = $parts[0] ?? '';
    $body = $parts[1] ?? '';
    $lines = preg_split('/\r\n/', $headerBlock) ?: [];
    $requestLine = array_shift($lines) ?: '';
    $filtered = [];

    foreach ($lines as $line) {
        $name = strtolower(trim(strtok($line, ':') ?: ''));
        if (in_array($name, ['connection', 'proxy-connection', 'x-forwarded-proto', 'x-forwarded-host'], true)) {
            continue;
        }
        $filtered[] = $line;
    }

    $filtered[] = $request['upgrade'] === 'websocket'
        ? 'Connection: Upgrade'
        : 'Connection: close';
    $filtered[] = 'X-Forwarded-Proto: https';
    $host = $request['headers']['host'] ?? '';
    if ($host !== '') {
        $filtered[] = "X-Forwarded-Host: {$host}";
    }

    return $requestLine . "\r\n" . implode("\r\n", $filtered) . "\r\n\r\n" . $body;
};

$proxy = static function ($client, string $head, array $request, string $upstream) use ($parseUpstream, $connectTimeout, $httpIdleTimeout, $wsIdleTimeout, $writeStallTimeout, $readStallTimeout, $zeroWriteSleepMicros, $writeResponse, $injectForwardHeaders): void {
    [$upstreamHost, $upstreamPort] = $parseUpstream($upstream);
    $upstreamStream = @stream_socket_client(
        "tcp://{$upstreamHost}:{$upstreamPort}",
        $errno,
        $errstr,
        $connectTimeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($upstreamStream)) {
        $writeResponse($client, 502, 'Bad Gateway', ['Content-Type' => 'text/plain; charset=utf-8'], "Bad Gateway\n");
        return;
    }

    stream_set_blocking($client, false);
    stream_set_blocking($upstreamStream, false);

    $toUpstream = $injectForwardHeaders($head, $request);
    $toClient = '';
    $clientOpen = true;
    $upstreamOpen = true;
    $isWebSocket = $request['upgrade'] === 'websocket';
    $idleTimeout = $isWebSocket ? $wsIdleTimeout : $httpIdleTimeout;
    $lastActivity = microtime(true);
    $lastClientReadProgress = $lastActivity;
    $lastUpstreamReadProgress = $lastActivity;
    $lastClientWriteProgress = $lastActivity;
    $lastUpstreamWriteProgress = $lastActivity;

    while ($clientOpen || $upstreamOpen || $toUpstream !== '' || $toClient !== '') {
        if ((microtime(true) - $lastActivity) > $idleTimeout) {
            break;
        }
        if (!$clientOpen) {
            $toClient = '';
        }
        if (!$upstreamOpen) {
            $toUpstream = '';
        }
        if (!$clientOpen && $toClient === '') {
            break;
        }
        if (!$clientOpen && !$upstreamOpen) {
            break;
        }

        $read = [];
        if ($clientOpen && strlen($toUpstream) < 1048576) {
            $read[] = $client;
        }
        if ($upstreamOpen && strlen($toClient) < 1048576) {
            $read[] = $upstreamStream;
        }

        $write = [];
        if ($upstreamOpen && $toUpstream !== '') {
            $write[] = $upstreamStream;
        }
        if ($clientOpen && $toClient !== '') {
            $write[] = $client;
        }

        $except = null;
        if ($read === [] && $write === []) {
            break;
        }

        $ready = @stream_select($read, $write, $except, 1, 0);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            continue;
        }

        $madeProgress = false;
        $needsBackoff = false;
        foreach ($read as $stream) {
            $chunk = @fread($stream, 16384);
            if ($chunk === false) {
                if ($stream === $client) {
                    $clientOpen = false;
                } else {
                    $upstreamOpen = false;
                }
                continue;
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    if ($stream === $client) {
                        $clientOpen = false;
                    } else {
                        $upstreamOpen = false;
                    }
                    $madeProgress = true;
                    continue;
                }
                if ($isWebSocket) {
                    // TLS/nonblocking WebSocket streams can report readability
                    // without yielding bytes. Keep the tunnel alive and let the
                    // websocket idle timeout decide, otherwise live SFU streams
                    // are cut while the browser is still connected.
                    $needsBackoff = true;
                    continue;
                }
                if ($stream === $client) {
                    if ((microtime(true) - $lastClientReadProgress) >= $readStallTimeout) {
                        $clientOpen = false;
                        $madeProgress = true;
                    } else {
                        $needsBackoff = true;
                    }
                } else {
                    if ((microtime(true) - $lastUpstreamReadProgress) >= $readStallTimeout) {
                        $upstreamOpen = false;
                        $madeProgress = true;
                    } else {
                        $needsBackoff = true;
                    }
                }
                continue;
            }
            $lastActivity = microtime(true);
            if ($stream === $client) {
                $lastClientReadProgress = $lastActivity;
                if ($toUpstream === '') {
                    $lastUpstreamWriteProgress = $lastActivity;
                }
                $toUpstream .= $chunk;
            } else {
                $lastUpstreamReadProgress = $lastActivity;
                if ($toClient === '') {
                    $lastClientWriteProgress = $lastActivity;
                }
                $toClient .= $chunk;
            }
            $madeProgress = true;
        }

        foreach ($write as $stream) {
            if ($stream === $upstreamStream && $toUpstream !== '') {
                $written = @fwrite($upstreamStream, $toUpstream);
                if ($written === false) {
                    $upstreamOpen = false;
                    $toUpstream = '';
                    continue;
                }
                if ($written === 0) {
                    if ((microtime(true) - $lastUpstreamWriteProgress) >= $writeStallTimeout) {
                        $upstreamOpen = false;
                        $toUpstream = '';
                    } else {
                        usleep($zeroWriteSleepMicros);
                    }
                    continue;
                }
                $toUpstream = substr($toUpstream, $written);
                $lastActivity = microtime(true);
                $lastUpstreamWriteProgress = $lastActivity;
                $madeProgress = true;
            }
            if ($stream === $client && $toClient !== '') {
                $written = @fwrite($client, $toClient);
                if ($written === false) {
                    $clientOpen = false;
                    $toClient = '';
                    continue;
                }
                if ($written === 0) {
                    if ((microtime(true) - $lastClientWriteProgress) >= $writeStallTimeout) {
                        $clientOpen = false;
                        $toClient = '';
                    } else {
                        usleep($zeroWriteSleepMicros);
                    }
                    continue;
                }
                $toClient = substr($toClient, $written);
                $lastActivity = microtime(true);
                $lastClientWriteProgress = $lastActivity;
                $madeProgress = true;
            }
        }

        if (!$madeProgress && $needsBackoff) {
            usleep($zeroWriteSleepMicros);
        }
    }

    @fclose($upstreamStream);
};

$route = static function (array $request) use ($domain, $apiDomain, $wsDomain, $sfuDomain, $turnDomain, $cdnDomains, $apiUpstream, $wsUpstream, $sfuUpstream): ?string {
    $host = $request['host'];
    $path = $request['path'];
    if (in_array($host, $cdnDomains, true)) {
        return 'static';
    }
    if ($path === '/ws' || $host === $wsDomain) {
        return $wsUpstream;
    }
    if ($path === '/sfu' || $host === $sfuDomain) {
        return $sfuUpstream;
    }
    if ($host === $apiDomain || $path === '/api' || str_starts_with($path, '/api/') || $path === '/health') {
        return $apiUpstream;
    }
    if ($host === $turnDomain) {
        return null;
    }
    if ($host === $domain || $host === '') {
        return 'static';
    }
    return 'static';
};

$handleClient = static function ($client, bool $tls) use ($domain, $readRequestHead, $parseRequest, $writeResponse, $route, $serveStatic, $proxy): void {
    stream_set_timeout($client, 10);
    if ($tls) {
        $crypto = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($crypto !== true) {
            @fclose($client);
            return;
        }
    }

    $requestHead = $readRequestHead($client);
    $head = $requestHead['head'] ?? null;
    if ($head === null) {
        if ((int) ($requestHead['bytes_read'] ?? 0) <= 0) {
            @fclose($client);
            return;
        }
        $writeResponse($client, 400, 'Bad Request', ['Content-Type' => 'text/plain; charset=utf-8'], "Bad Request\n");
        @fclose($client);
        return;
    }

    $request = $parseRequest($head);
    if (!$tls) {
        $host = (string) ($request['headers']['host'] ?? $domain);
        $host = preg_replace('/:\d+$/', '', $host) ?: $domain;
        $target = (string) ($request['target'] ?: '/');
        $location = 'https://' . $host . $target;
        $writeResponse($client, 301, 'Moved Permanently', [
            'Location' => $location,
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], "Redirecting to {$location}\n", $request['method'] === 'HEAD');
        @fclose($client);
        return;
    }

    $upstream = $route($request);
    if ($upstream === 'static') {
        $serveStatic($client, $request);
        @fclose($client);
        return;
    }
    if ($upstream === null) {
        $writeResponse($client, 404, 'Not Found', ['Content-Type' => 'text/plain; charset=utf-8'], "Not Found\n", $request['method'] === 'HEAD');
        @fclose($client);
        return;
    }

    $proxy($client, $head, $request, $upstream);
    @fclose($client);
};

$log("listening on {$httpHost}:{$httpPort} redirect and {$httpHost}:{$httpsPort} tls for {$domain}");

while (true) {
    $read = [$httpServer, $httpsServer];
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 1, 0);
    if ($ready === false) {
        usleep(100000);
        continue;
    }
    if ($ready === 0) {
        if (function_exists('pcntl_waitpid')) {
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                unset($children[$pid]);
            }
        }
        continue;
    }

    foreach ($read as $server) {
        while (count($children) >= $maxChildren && function_exists('pcntl_waitpid')) {
            $pid = pcntl_waitpid(-1, $status);
            if ($pid > 0) {
                unset($children[$pid]);
            } else {
                break;
            }
        }

        $client = @stream_socket_accept($server, 0);
        if (!is_resource($client)) {
            continue;
        }
        $tls = $server === $httpsServer;

        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $log('fork failed; handling connection in master');
                $handleClient($client, $tls);
                continue;
            }
            if ($pid === 0) {
                @fclose($httpServer);
                @fclose($httpsServer);
                $handleClient($client, $tls);
                exit(0);
            }
            $children[$pid] = true;
            @fclose($client);
            continue;
        }

        $handleClient($client, $tls);
    }
}
