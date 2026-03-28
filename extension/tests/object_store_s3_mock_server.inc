<?php

$capturePath = (string) ($argv[1] ?? '');
$stateDirectory = (string) ($argv[2] ?? '');
$port = (int) ($argv[3] ?? 0);
$bindHost = (string) ($argv[4] ?? '127.0.0.1');
$options = json_decode((string) ($argv[5] ?? '{}'), true);

if ($capturePath === '' || $stateDirectory === '' || $port <= 0) {
    fwrite(STDERR, "invalid object-store S3 mock arguments\n");
    exit(2);
}

if (!is_array($options)) {
    $options = [];
}

$objectsDirectory = $stateDirectory . '/objects';
if (!is_dir($objectsDirectory) && !mkdir($objectsDirectory, 0700, true) && !is_dir($objectsDirectory)) {
    fwrite(STDERR, "failed to create object directory\n");
    exit(2);
}

$capture = [
    'events' => [],
    'state_directory' => $stateDirectory,
];

register_shutdown_function(static function () use (&$capture, $capturePath): void {
    file_put_contents($capturePath, json_encode($capture, JSON_PRETTY_PRINT));
});

function king_object_store_s3_mock_respond($conn, int $status, string $reason, array $headers = [], string $body = ''): void
{
    $defaultHeaders = [
        'Connection' => 'close',
        'Content-Length' => (string) strlen($body),
    ];

    foreach ($defaultHeaders as $name => $value) {
        if (!array_key_exists($name, $headers)) {
            $headers[$name] = $value;
        }
    }

    fwrite($conn, "HTTP/1.1 {$status} {$reason}\r\n");
    foreach ($headers as $name => $value) {
        fwrite($conn, $name . ': ' . $value . "\r\n");
    }
    fwrite($conn, "\r\n");
    if ($body !== '') {
        fwrite($conn, $body);
    }
}

function king_object_store_s3_mock_object_path(string $objectsDirectory, string $objectId): string
{
    return $objectsDirectory . '/' . rawurlencode($objectId);
}

function king_object_store_s3_mock_parse_request($conn): ?array
{
    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n") && strlen($buffer) < 131072) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }

    if ($buffer === '') {
        return null;
    }

    if (strncmp($buffer, "STOP\n", 5) === 0) {
        return ['stop' => true];
    }

    if (!str_contains($buffer, "\r\n\r\n")) {
        return null;
    }

    [$head, $body] = explode("\r\n\r\n", $buffer, 2);
    $lines = $head === '' ? [] : explode("\r\n", $head);
    $requestLine = array_shift($lines) ?? '';
    $parts = explode(' ', $requestLine, 3);
    $method = $parts[0] ?? '';
    $target = $parts[1] ?? '/';
    $headers = [];

    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));
        $value = trim(substr($line, $colon + 1));
        $headers[$name] = $value;
    }

    $contentLength = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
    while (strlen($body) < $contentLength) {
        $chunk = fread($conn, $contentLength - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    return [
        'method' => $method,
        'target' => $target,
        'headers' => $headers,
        'body' => substr($body, 0, $contentLength),
    ];
}

function king_object_store_s3_mock_extract_access_key(array $headers): string
{
    $authorization = (string) ($headers['authorization'] ?? '');

    if ($authorization === '') {
        return '';
    }

    if (preg_match('/Credential=([^\\/ ,]+)\\//', $authorization, $matches) === 1) {
        return (string) $matches[1];
    }

    return '';
}

function king_object_store_s3_mock_auth_error(array $headers, array $options): ?array
{
    $expectedAccessKey = (string) ($options['expected_access_key'] ?? '');
    $expectedSessionToken = (string) ($options['expected_session_token'] ?? '');
    $errorCode = (string) ($options['auth_error_code'] ?? 'InvalidAccessKeyId');
    $errorMessage = (string) ($options['auth_error_message'] ?? 'The provided object-store credentials were rejected.');
    $receivedAccessKey = king_object_store_s3_mock_extract_access_key($headers);
    $receivedSessionToken = (string) ($headers['x-amz-security-token'] ?? '');

    if ($expectedAccessKey !== '' && $receivedAccessKey !== $expectedAccessKey) {
        return [
            'code' => $errorCode,
            'message' => $errorMessage,
            'received_access_key' => $receivedAccessKey,
        ];
    }

    if ($expectedSessionToken !== '' && $receivedSessionToken !== $expectedSessionToken) {
        return [
            'code' => $errorCode,
            'message' => $errorMessage,
            'received_access_key' => $receivedAccessKey,
        ];
    }

    return null;
}

function king_object_store_s3_mock_reason_phrase(int $status): string
{
    return match ($status) {
        200 => 'OK',
        204 => 'No Content',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        429 => 'Too Many Requests',
        503 => 'Service Unavailable',
        default => 'Error',
    };
}

function king_object_store_s3_mock_take_forced_response(array &$options, string $method, string $target): ?array
{
    if (!isset($options['forced_responses']) || !is_array($options['forced_responses'])) {
        return null;
    }

    foreach ($options['forced_responses'] as $index => $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $expectedMethod = (string) ($rule['method'] ?? '');
        $expectedTarget = (string) ($rule['target'] ?? '');
        $remaining = isset($rule['times']) ? (int) $rule['times'] : -1;

        if ($expectedMethod !== '' && strcasecmp($expectedMethod, $method) !== 0) {
            continue;
        }
        if ($expectedTarget !== '' && $expectedTarget !== $target) {
            continue;
        }
        if ($remaining === 0) {
            continue;
        }

        if ($remaining > 0) {
            $options['forced_responses'][$index]['times'] = $remaining - 1;
        }

        return $rule;
    }

    return null;
}

function king_object_store_s3_mock_error_body(string $code, string $message): string
{
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Error><Code>"
        . htmlspecialchars($code, ENT_XML1)
        . "</Code><Message>"
        . htmlspecialchars($message, ENT_XML1)
        . "</Message></Error>";
}

function king_object_store_s3_mock_list_xml(string $objectsDirectory): string
{
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ListBucketResult>";
    $entries = glob($objectsDirectory . '/*');
    sort($entries, SORT_STRING);

    foreach ($entries as $entry) {
        if (!is_file($entry)) {
            continue;
        }
        $objectId = rawurldecode(basename($entry));
        $size = filesize($entry);
        $modified = gmdate('Y-m-d\TH:i:s\Z', filemtime($entry));
        $xml .= '<Contents>';
        $xml .= '<Key>' . htmlspecialchars($objectId, ENT_XML1) . '</Key>';
        $xml .= '<LastModified>' . $modified . '</LastModified>';
        $xml .= '<Size>' . (int) $size . '</Size>';
        $xml .= '</Contents>';
    }

    $xml .= '</ListBucketResult>';
    return $xml;
}

$server = @stream_socket_server("tcp://{$bindHost}:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");

while (($conn = @stream_socket_accept($server, 10)) !== false) {
    stream_set_timeout($conn, 5);
    stream_set_write_buffer($conn, 0);

    $request = king_object_store_s3_mock_parse_request($conn);
    if ($request === null) {
        fclose($conn);
        continue;
    }
    if (!empty($request['stop'])) {
        fclose($conn);
        break;
    }

    $method = (string) $request['method'];
    $target = (string) $request['target'];
    $headers = (array) $request['headers'];
    $body = (string) $request['body'];
    $path = parse_url($target, PHP_URL_PATH) ?: '/';
    $query = (string) (parse_url($target, PHP_URL_QUERY) ?? '');
    $segments = explode('/', ltrim($path, '/'));
    $bucket = rawurldecode((string) ($segments[0] ?? ''));
    $objectId = count($segments) > 1 ? rawurldecode(implode('/', array_slice($segments, 1))) : '';

    $capture['events'][] = [
        'method' => $method,
        'target' => $target,
        'bucket' => $bucket,
        'object_id' => $objectId,
        'content_length' => strlen($body),
        'authorization_access_key' => king_object_store_s3_mock_extract_access_key($headers),
    ];

    if ($bucket === '') {
        king_object_store_s3_mock_respond($conn, 400, 'Bad Request', [], 'missing bucket');
        fclose($conn);
        continue;
    }

    $authError = king_object_store_s3_mock_auth_error($headers, $options);
    if ($authError !== null) {
        $capture['events'][count($capture['events']) - 1]['auth_rejected'] = true;
        $capture['events'][count($capture['events']) - 1]['auth_error_code'] = $authError['code'];
        $capture['events'][count($capture['events']) - 1]['received_access_key'] = $authError['received_access_key'];
        king_object_store_s3_mock_respond($conn, 403, 'Forbidden', [
            'Content-Type' => 'application/xml',
        ], king_object_store_s3_mock_error_body($authError['code'], $authError['message']));
        fclose($conn);
        continue;
    }

    $forcedResponse = king_object_store_s3_mock_take_forced_response($options, $method, $target);
    if ($forcedResponse !== null) {
        $status = (int) ($forcedResponse['status'] ?? 503);
        $reason = (string) ($forcedResponse['reason'] ?? king_object_store_s3_mock_reason_phrase($status));
        $headersOut = is_array($forcedResponse['headers'] ?? null) ? $forcedResponse['headers'] : [];
        $bodyOut = (string) ($forcedResponse['body'] ?? '');
        $errorCode = (string) ($forcedResponse['error_code'] ?? '');
        $errorMessage = (string) ($forcedResponse['error_message'] ?? 'The object-store endpoint is temporarily throttling requests.');

        if ($bodyOut === '' && $errorCode !== '') {
            $bodyOut = king_object_store_s3_mock_error_body($errorCode, $errorMessage);
            if (!isset($headersOut['Content-Type'])) {
                $headersOut['Content-Type'] = 'application/xml';
            }
        }

        $capture['events'][count($capture['events']) - 1]['forced_status'] = $status;
        if ($errorCode !== '') {
            $capture['events'][count($capture['events']) - 1]['forced_error_code'] = $errorCode;
        }

        king_object_store_s3_mock_respond($conn, $status, $reason, $headersOut, $bodyOut);
        fclose($conn);
        continue;
    }

    if ($method === 'GET' && str_contains($query, 'list-type=2')) {
        $xml = king_object_store_s3_mock_list_xml($objectsDirectory);
        king_object_store_s3_mock_respond($conn, 200, 'OK', [
            'Content-Type' => 'application/xml',
        ], $xml);
        fclose($conn);
        continue;
    }

    if ($objectId === '') {
        king_object_store_s3_mock_respond($conn, 404, 'Not Found');
        fclose($conn);
        continue;
    }

    $objectPath = king_object_store_s3_mock_object_path($objectsDirectory, $objectId);

    if ($method === 'PUT') {
        file_put_contents($objectPath, $body);
        touch($objectPath);
        king_object_store_s3_mock_respond($conn, 200, 'OK');
        fclose($conn);
        continue;
    }

    if (!is_file($objectPath)) {
        king_object_store_s3_mock_respond($conn, 404, 'Not Found');
        fclose($conn);
        continue;
    }

    if ($method === 'HEAD') {
        king_object_store_s3_mock_respond($conn, 200, 'OK', [
            'Content-Length' => (string) filesize($objectPath),
            'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', filemtime($objectPath)),
        ]);
        fclose($conn);
        continue;
    }

    if ($method === 'GET') {
        $payload = (string) file_get_contents($objectPath);
        king_object_store_s3_mock_respond($conn, 200, 'OK', [
            'Content-Length' => (string) strlen($payload),
            'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', filemtime($objectPath)),
        ], $payload);
        fclose($conn);
        continue;
    }

    if ($method === 'DELETE') {
        @unlink($objectPath);
        king_object_store_s3_mock_respond($conn, 204, 'No Content');
        fclose($conn);
        continue;
    }

    king_object_store_s3_mock_respond($conn, 405, 'Method Not Allowed');
    fclose($conn);
}

fclose($server);
