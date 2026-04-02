<?php

/* Local S3-compatible mock server for object-store cloud and backup contracts. */

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
$metadataDirectory = $stateDirectory . '/metadata';
$multipartDirectory = $stateDirectory . '/multipart';
$gcsSessionsDirectory = $stateDirectory . '/gcs_sessions';
$azureBlocksDirectory = $stateDirectory . '/azure_blocks';
if (!is_dir($objectsDirectory) && !mkdir($objectsDirectory, 0700, true) && !is_dir($objectsDirectory)) {
    fwrite(STDERR, "failed to create object directory\n");
    exit(2);
}
if (!is_dir($metadataDirectory) && !mkdir($metadataDirectory, 0700, true) && !is_dir($metadataDirectory)) {
    fwrite(STDERR, "failed to create object metadata directory\n");
    exit(2);
}
if (!is_dir($multipartDirectory) && !mkdir($multipartDirectory, 0700, true) && !is_dir($multipartDirectory)) {
    fwrite(STDERR, "failed to create multipart directory\n");
    exit(2);
}
if (!is_dir($gcsSessionsDirectory) && !mkdir($gcsSessionsDirectory, 0700, true) && !is_dir($gcsSessionsDirectory)) {
    fwrite(STDERR, "failed to create GCS session directory\n");
    exit(2);
}
if (!is_dir($azureBlocksDirectory) && !mkdir($azureBlocksDirectory, 0700, true) && !is_dir($azureBlocksDirectory)) {
    fwrite(STDERR, "failed to create Azure block directory\n");
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
    global $capture;
    $defaultHeaders = [
        'Connection' => 'close',
        'Content-Length' => (string) strlen($body),
    ];

    if (isset($capture['events']) && is_array($capture['events']) && count($capture['events']) > 0) {
        $capture['events'][count($capture['events']) - 1]['response_status'] = $status;
    }

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

function king_object_store_s3_mock_metadata_path(string $metadataDirectory, string $objectId): string
{
    return $metadataDirectory . '/' . rawurlencode($objectId) . '.json';
}

function king_object_store_s3_mock_metadata_prefix(string $provider): string
{
    return match ($provider) {
        'gcs' => 'x-goog-meta-',
        'azure' => 'x-ms-meta-',
        default => 'x-amz-meta-',
    };
}

function king_object_store_s3_mock_query_params(string $query): array
{
    if ($query === '') {
        return [];
    }

    parse_str($query, $params);
    return is_array($params) ? $params : [];
}

function king_object_store_s3_mock_recursive_delete(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_object_store_s3_mock_recursive_delete($path . '/' . $entry);
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_s3_mock_capture_object_metadata(array $headers, string $provider): array
{
    $metadata = [];
    $prefix = king_object_store_s3_mock_metadata_prefix($provider);

    foreach ($headers as $name => $value) {
        if (str_starts_with($name, $prefix)) {
            $metadata[substr($name, strlen($prefix))] = (string) $value;
        }
    }

    if (isset($headers['content-type'])) {
        $metadata['content-type'] = (string) $headers['content-type'];
    }
    if (isset($headers['content-encoding'])) {
        $metadata['content-encoding'] = (string) $headers['content-encoding'];
    }

    return $metadata;
}

function king_object_store_s3_mock_multipart_state_path(string $multipartDirectory, string $uploadId): string
{
    return $multipartDirectory . '/' . rawurlencode($uploadId) . '.json';
}

function king_object_store_s3_mock_multipart_parts_dir(string $multipartDirectory, string $uploadId): string
{
    return $multipartDirectory . '/' . rawurlencode($uploadId);
}

function king_object_store_s3_mock_gcs_session_path(string $gcsSessionsDirectory, string $sessionId): string
{
    return $gcsSessionsDirectory . '/' . rawurlencode($sessionId) . '.json';
}

function king_object_store_s3_mock_gcs_payload_path(string $gcsSessionsDirectory, string $sessionId): string
{
    return $gcsSessionsDirectory . '/' . rawurlencode($sessionId) . '.bin';
}

function king_object_store_s3_mock_azure_blocks_dir(string $azureBlocksDirectory, string $objectId): string
{
    return $azureBlocksDirectory . '/' . rawurlencode($objectId);
}

function king_object_store_s3_mock_load_object_metadata(string $metadataDirectory, string $objectId): array
{
    $path = king_object_store_s3_mock_metadata_path($metadataDirectory, $objectId);

    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function king_object_store_s3_mock_build_object_headers(
    string $provider,
    string $payload,
    array $metadata,
    int $modifiedAt,
    ?array $range = null
): array {
    $headers = [
        'Content-Length' => (string) strlen($payload),
        'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $modifiedAt),
        'ETag' => '"' . (($metadata['etag'] ?? '') !== '' ? (string) $metadata['etag'] : sha1($payload)) . '"',
    ];
    $prefix = king_object_store_s3_mock_metadata_prefix($provider);

    if ($provider === 'azure') {
        $headers['x-ms-blob-content-length'] = (string) strlen($payload);
    }

    if (($metadata['content-type'] ?? '') !== '') {
        $headers['Content-Type'] = (string) $metadata['content-type'];
    }
    if (($metadata['content-encoding'] ?? '') !== '') {
        $headers['Content-Encoding'] = (string) $metadata['content-encoding'];
    }

    foreach ($metadata as $key => $value) {
        if ($key === 'content-type' || $key === 'content-encoding') {
            continue;
        }
        $headers[$prefix . $key] = (string) $value;
    }

    if ($range !== null) {
        $headers['Content-Range'] = sprintf(
            'bytes %d-%d/%d',
            $range['start'],
            $range['end'],
            $range['total']
        );
    }

    return $headers;
}

function king_object_store_s3_mock_parse_range(?string $rangeHeader, int $payloadLength): array|false|null
{
    if ($rangeHeader === null || $rangeHeader === '' || $payloadLength < 0) {
        return null;
    }

    if (preg_match('/^bytes=(\d+)-(\d+)?$/', trim($rangeHeader), $matches) !== 1) {
        return false;
    }

    $start = (int) $matches[1];
    $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : ($payloadLength - 1);

    if ($payloadLength === 0 || $start >= $payloadLength || $start < 0 || $end < $start) {
        return false;
    }

    if ($end >= $payloadLength) {
        $end = $payloadLength - 1;
    }

    return [
        'start' => $start,
        'end' => $end,
        'total' => $payloadLength,
    ];
}

function king_object_store_s3_mock_parse_content_range(?string $header): ?array
{
    if ($header === null || $header === '') {
        return null;
    }

    if (preg_match('/^bytes (\d+)-(\d+)\/(\*|\d+)$/', trim($header), $matches) !== 1) {
        return null;
    }

    return [
        'start' => (int) $matches[1],
        'end' => (int) $matches[2],
        'total' => $matches[3] === '*' ? null : (int) $matches[3],
    ];
}

function king_object_store_s3_mock_parse_multipart_complete(string $body): array
{
    if ($body === '') {
        return [];
    }

    preg_match_all('/<PartNumber>(\d+)<\/PartNumber>.*?<ETag>(.*?)<\/ETag>/s', $body, $matches, PREG_SET_ORDER);

    $parts = [];
    foreach ($matches as $match) {
        $parts[] = [
            'part_number' => (int) $match[1],
            'etag' => (string) $match[2],
        ];
    }

    return $parts;
}

function king_object_store_s3_mock_parse_azure_block_list(string $body): array
{
    if ($body === '') {
        return [];
    }

    preg_match_all('/<(?:Latest|Committed|Uncommitted)>(.*?)<\/(?:Latest|Committed|Uncommitted)>/s', $body, $matches);
    return array_map('strval', $matches[1] ?? []);
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

function king_object_store_s3_mock_extract_bearer_token(array $headers): string
{
    $authorization = (string) ($headers['authorization'] ?? '');

    if ($authorization === '') {
        return '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim((string) $matches[1]);
    }

    return '';
}

function king_object_store_s3_mock_auth_error(array $headers, array $options): ?array
{
    $provider = (string) ($options['provider'] ?? 's3');
    $expectedAccessKey = (string) ($options['expected_access_key'] ?? '');
    $expectedSessionToken = (string) ($options['expected_session_token'] ?? '');
    $expectedAccessToken = (string) ($options['expected_access_token'] ?? '');
    $errorCode = (string) ($options['auth_error_code'] ?? 'InvalidAccessKeyId');
    $errorMessage = (string) ($options['auth_error_message'] ?? 'The provided object-store credentials were rejected.');
    $receivedAccessKey = king_object_store_s3_mock_extract_access_key($headers);
    $receivedSessionToken = (string) ($headers['x-amz-security-token'] ?? '');
    $receivedAccessToken = king_object_store_s3_mock_extract_bearer_token($headers);

    if ($provider === 'gcs' || $provider === 'azure') {
        if ($expectedAccessToken !== '' && $receivedAccessToken !== $expectedAccessToken) {
            return [
                'code' => $errorCode,
                'message' => $errorMessage,
                'received_access_token' => $receivedAccessToken,
            ];
        }

        return null;
    }

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

function king_object_store_s3_mock_persist_object(
    string $objectsDirectory,
    string $metadataDirectory,
    string $objectId,
    string $body,
    array $headers,
    string $provider
): void
{
    if ($objectId === '') {
        return;
    }

    $objectPath = king_object_store_s3_mock_object_path($objectsDirectory, $objectId);
    $metadataPath = king_object_store_s3_mock_metadata_path($metadataDirectory, $objectId);
    file_put_contents($objectPath, $body);
    file_put_contents(
        $metadataPath,
        json_encode(king_object_store_s3_mock_capture_object_metadata($headers, $provider), JSON_PRETTY_PRINT)
    );
    touch($objectPath);
    touch($metadataPath);
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

function king_object_store_azure_mock_list_xml(string $objectsDirectory): string
{
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<EnumerationResults><Blobs>";
    $entries = glob($objectsDirectory . '/*');
    sort($entries, SORT_STRING);

    foreach ($entries as $entry) {
        if (!is_file($entry)) {
            continue;
        }
        $objectId = rawurldecode(basename($entry));
        $size = filesize($entry);
        $modified = gmdate('D, d M Y H:i:s \G\M\T', filemtime($entry));
        $xml .= '<Blob>';
        $xml .= '<Name>' . htmlspecialchars($objectId, ENT_XML1) . '</Name>';
        $xml .= '<Properties>';
        $xml .= '<Last-Modified>' . $modified . '</Last-Modified>';
        $xml .= '<Content-Length>' . (int) $size . '</Content-Length>';
        $xml .= '</Properties>';
        $xml .= '</Blob>';
    }

    $xml .= '</Blobs></EnumerationResults>';
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
    $provider = (string) ($options['provider'] ?? 's3');
    $path = parse_url($target, PHP_URL_PATH) ?: '/';
    $query = (string) (parse_url($target, PHP_URL_QUERY) ?? '');
    $queryParams = king_object_store_s3_mock_query_params($query);
    $segments = explode('/', ltrim($path, '/'));
    $bucket = rawurldecode((string) ($segments[0] ?? ''));
    $objectId = count($segments) > 1 ? rawurldecode(implode('/', array_slice($segments, 1))) : '';
    $objectPath = $objectId === '' ? '' : king_object_store_s3_mock_object_path($objectsDirectory, $objectId);

    $capture['events'][] = [
        'method' => $method,
        'target' => $target,
        'bucket' => $bucket,
        'object_id' => $objectId,
        'content_length' => strlen($body),
        'headers' => $headers,
        'authorization_access_key' => king_object_store_s3_mock_extract_access_key($headers),
        'authorization_bearer_token' => king_object_store_s3_mock_extract_bearer_token($headers),
    ];

    if (str_starts_with($path, '/__gcs_resumable/')) {
        $sessionId = rawurldecode(substr($path, strlen('/__gcs_resumable/')));
        $sessionPath = king_object_store_s3_mock_gcs_session_path($gcsSessionsDirectory, $sessionId);
        $payloadPath = king_object_store_s3_mock_gcs_payload_path($gcsSessionsDirectory, $sessionId);
        $session = is_file($sessionPath)
            ? json_decode((string) file_get_contents($sessionPath), true)
            : null;

        if (!is_array($session)) {
            king_object_store_s3_mock_respond($conn, 404, 'Not Found');
            fclose($conn);
            continue;
        }

        if ($method === 'DELETE') {
            king_object_store_s3_mock_recursive_delete($sessionPath);
            king_object_store_s3_mock_recursive_delete($payloadPath);
            king_object_store_s3_mock_respond($conn, 204, 'No Content');
            fclose($conn);
            continue;
        }

        if ($method !== 'PUT') {
            king_object_store_s3_mock_respond($conn, 405, 'Method Not Allowed');
            fclose($conn);
            continue;
        }

        $contentRange = king_object_store_s3_mock_parse_content_range($headers['content-range'] ?? null);
        if (!is_array($contentRange)) {
            king_object_store_s3_mock_respond($conn, 400, 'Bad Request');
            fclose($conn);
            continue;
        }

        $expectedStart = (int) ($session['received_bytes'] ?? 0);
        if ($contentRange['start'] !== $expectedStart) {
            king_object_store_s3_mock_respond($conn, 409, 'Conflict');
            fclose($conn);
            continue;
        }

        file_put_contents($payloadPath, $body, FILE_APPEND);
        $session['received_bytes'] = $contentRange['end'] + 1;
        file_put_contents($sessionPath, json_encode($session, JSON_PRETTY_PRINT));

        if ($contentRange['total'] === null) {
            king_object_store_s3_mock_respond($conn, 308, 'Resume Incomplete', [
                'Range' => 'bytes=0-' . $contentRange['end'],
            ]);
            fclose($conn);
            continue;
        }

        $persistedPayload = (string) file_get_contents($payloadPath);
        king_object_store_s3_mock_persist_object(
            $objectsDirectory,
            $metadataDirectory,
            (string) ($session['object_id'] ?? ''),
            $persistedPayload,
            (array) ($session['headers'] ?? []),
            'gcs'
        );
        king_object_store_s3_mock_recursive_delete($sessionPath);
        king_object_store_s3_mock_recursive_delete($payloadPath);
        king_object_store_s3_mock_respond($conn, 200, 'OK', [
            'ETag' => '"' . sha1($persistedPayload) . '"',
        ]);
        fclose($conn);
        continue;
    }

    if ($bucket === '') {
        king_object_store_s3_mock_respond($conn, 400, 'Bad Request', [], 'missing bucket');
        fclose($conn);
        continue;
    }

    $authError = king_object_store_s3_mock_auth_error($headers, $options);
    if ($authError !== null) {
        $capture['events'][count($capture['events']) - 1]['auth_rejected'] = true;
        $capture['events'][count($capture['events']) - 1]['auth_error_code'] = $authError['code'];
        if (isset($authError['received_access_key'])) {
            $capture['events'][count($capture['events']) - 1]['received_access_key'] = $authError['received_access_key'];
        }
        if (isset($authError['received_access_token'])) {
            $capture['events'][count($capture['events']) - 1]['received_access_token'] = $authError['received_access_token'];
        }
        king_object_store_s3_mock_respond($conn, 403, 'Forbidden', [
            'Content-Type' => 'application/xml',
        ], king_object_store_s3_mock_error_body($authError['code'], $authError['message']));
        fclose($conn);
        continue;
    }

    $forcedResponse = king_object_store_s3_mock_take_forced_response($options, $method, $target);
    if ($forcedResponse !== null) {
        $persistObjectBeforeReply = !empty($forcedResponse['persist_object_before_reply']);
        $closeConnectionWithoutResponse = !empty($forcedResponse['close_connection_without_response']);
        $rawResponsePrefix = (string) ($forcedResponse['raw_response_prefix'] ?? '');
        $status = (int) ($forcedResponse['status'] ?? 503);
        $reason = (string) ($forcedResponse['reason'] ?? king_object_store_s3_mock_reason_phrase($status));
        $headersOut = is_array($forcedResponse['headers'] ?? null) ? $forcedResponse['headers'] : [];
        $bodyOut = (string) ($forcedResponse['body'] ?? '');
        $errorCode = (string) ($forcedResponse['error_code'] ?? '');
        $errorMessage = (string) ($forcedResponse['error_message'] ?? 'The object-store endpoint is temporarily throttling requests.');

        if ($persistObjectBeforeReply && $method === 'PUT' && $objectId !== '') {
            king_object_store_s3_mock_persist_object(
                $objectsDirectory,
                $metadataDirectory,
                $objectId,
                $body,
                $headers,
                $provider
            );
            $capture['events'][count($capture['events']) - 1]['persisted_before_forced_reply'] = true;
        }

        if ($closeConnectionWithoutResponse) {
            $capture['events'][count($capture['events']) - 1]['forced_disconnect'] = true;
            if ($rawResponsePrefix !== '') {
                fwrite($conn, $rawResponsePrefix);
            }
            fclose($conn);
            continue;
        }

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

    if ($provider === 'gcs' && $method === 'POST' && ($queryParams['uploadType'] ?? null) === 'resumable') {
        $sessionId = bin2hex(random_bytes(12));
        $sessionPath = king_object_store_s3_mock_gcs_session_path($gcsSessionsDirectory, $sessionId);
        $payloadPath = king_object_store_s3_mock_gcs_payload_path($gcsSessionsDirectory, $sessionId);
        $sessionUrl = 'http://' . $bindHost . ':' . $port . '/__gcs_resumable/' . rawurlencode($sessionId);

        file_put_contents($sessionPath, json_encode([
            'object_id' => $objectId,
            'headers' => $headers,
            'received_bytes' => 0,
        ], JSON_PRETTY_PRINT));
        file_put_contents($payloadPath, '');

        king_object_store_s3_mock_respond($conn, 200, 'OK', [
            'Location' => $sessionUrl,
        ]);
        fclose($conn);
        continue;
    }

    if ($provider === 's3' && $method === 'POST' && (array_key_exists('uploads', $queryParams) || $query === 'uploads')) {
        $uploadId = bin2hex(random_bytes(12));
        $sessionPath = king_object_store_s3_mock_multipart_state_path($multipartDirectory, $uploadId);
        $partsDir = king_object_store_s3_mock_multipart_parts_dir($multipartDirectory, $uploadId);

        if (!is_dir($partsDir) && !mkdir($partsDir, 0700, true) && !is_dir($partsDir)) {
            king_object_store_s3_mock_respond($conn, 500, 'Internal Server Error');
            fclose($conn);
            continue;
        }

        file_put_contents($sessionPath, json_encode([
            'object_id' => $objectId,
            'headers' => $headers,
        ], JSON_PRETTY_PRINT));

        king_object_store_s3_mock_respond($conn, 200, 'OK', [
            'Content-Type' => 'application/xml',
        ], '<InitiateMultipartUploadResult><UploadId>' . htmlspecialchars($uploadId, ENT_XML1) . '</UploadId></InitiateMultipartUploadResult>');
        fclose($conn);
        continue;
    }

    if ($provider === 's3' && isset($queryParams['uploadId']) && $queryParams['uploadId'] !== '') {
        $uploadId = (string) $queryParams['uploadId'];
        $sessionPath = king_object_store_s3_mock_multipart_state_path($multipartDirectory, $uploadId);
        $partsDir = king_object_store_s3_mock_multipart_parts_dir($multipartDirectory, $uploadId);
        $session = is_file($sessionPath)
            ? json_decode((string) file_get_contents($sessionPath), true)
            : null;

        if (!is_array($session)) {
            king_object_store_s3_mock_respond($conn, 404, 'Not Found');
            fclose($conn);
            continue;
        }

        if ($method === 'PUT' && isset($queryParams['partNumber'])) {
            $partNumber = (int) $queryParams['partNumber'];
            $partPath = $partsDir . '/' . $partNumber . '.bin';
            $etag = '"' . sha1($body) . '"';

            file_put_contents($partPath, $body);
            king_object_store_s3_mock_respond($conn, 200, 'OK', [
                'ETag' => $etag,
            ]);
            fclose($conn);
            continue;
        }

        if ($method === 'POST') {
            $parts = king_object_store_s3_mock_parse_multipart_complete($body);
            $payload = '';

            foreach ($parts as $part) {
                $partPath = $partsDir . '/' . $part['part_number'] . '.bin';
                if (!is_file($partPath)) {
                    king_object_store_s3_mock_respond($conn, 400, 'Bad Request');
                    fclose($conn);
                    continue 2;
                }

                $payload .= (string) file_get_contents($partPath);
            }

            king_object_store_s3_mock_persist_object(
                $objectsDirectory,
                $metadataDirectory,
                (string) ($session['object_id'] ?? $objectId),
                $payload,
                (array) ($session['headers'] ?? []),
                's3'
            );
            king_object_store_s3_mock_recursive_delete($sessionPath);
            king_object_store_s3_mock_recursive_delete($partsDir);
            king_object_store_s3_mock_respond($conn, 200, 'OK', [
                'Content-Type' => 'application/xml',
            ], '<CompleteMultipartUploadResult><ETag>"' . sha1($payload) . '"</ETag></CompleteMultipartUploadResult>');
            fclose($conn);
            continue;
        }

        if ($method === 'DELETE') {
            king_object_store_s3_mock_recursive_delete($sessionPath);
            king_object_store_s3_mock_recursive_delete($partsDir);
            king_object_store_s3_mock_respond($conn, 204, 'No Content');
            fclose($conn);
            continue;
        }

        king_object_store_s3_mock_respond($conn, 405, 'Method Not Allowed');
        fclose($conn);
        continue;
    }

    if ($provider === 'azure'
        && $method === 'PUT'
        && ($queryParams['comp'] ?? null) === 'block'
        && isset($queryParams['blockid'])
        && $objectId !== '') {
        $blocksDir = king_object_store_s3_mock_azure_blocks_dir($azureBlocksDirectory, $objectId);
        $blockId = (string) $queryParams['blockid'];

        if (!is_dir($blocksDir) && !mkdir($blocksDir, 0700, true) && !is_dir($blocksDir)) {
            king_object_store_s3_mock_respond($conn, 500, 'Internal Server Error');
            fclose($conn);
            continue;
        }

        file_put_contents($blocksDir . '/' . rawurlencode($blockId) . '.bin', $body);
        king_object_store_s3_mock_respond($conn, 201, 'Created');
        fclose($conn);
        continue;
    }

    if ($provider === 'azure'
        && $method === 'PUT'
        && ($queryParams['comp'] ?? null) === 'blocklist'
        && $objectId !== '') {
        $blocksDir = king_object_store_s3_mock_azure_blocks_dir($azureBlocksDirectory, $objectId);
        $blockIds = king_object_store_s3_mock_parse_azure_block_list($body);
        $payload = '';

        foreach ($blockIds as $blockId) {
            $blockPath = $blocksDir . '/' . rawurlencode($blockId) . '.bin';
            if (!is_file($blockPath)) {
                king_object_store_s3_mock_respond($conn, 400, 'Bad Request');
                fclose($conn);
                continue 2;
            }

            $payload .= (string) file_get_contents($blockPath);
        }

        king_object_store_s3_mock_persist_object(
            $objectsDirectory,
            $metadataDirectory,
            $objectId,
            $payload,
            $headers,
            'azure'
        );
        king_object_store_s3_mock_recursive_delete($blocksDir);
        king_object_store_s3_mock_respond($conn, 201, 'Created', [
            'ETag' => '"' . sha1($payload) . '"',
        ]);
        fclose($conn);
        continue;
    }

    if ($method === 'GET' && (
            str_contains($query, 'list-type=2')
            || ($provider === 'gcs' && $objectId === '')
            || ($provider === 'azure' && $objectId === '' && str_contains($query, 'restype=container') && str_contains($query, 'comp=list'))
        )) {
        $xml = $provider === 'azure'
            ? king_object_store_azure_mock_list_xml($objectsDirectory)
            : king_object_store_s3_mock_list_xml($objectsDirectory);
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
        king_object_store_s3_mock_persist_object(
            $objectsDirectory,
            $metadataDirectory,
            $objectId,
            $body,
            $headers,
            $provider
        );
        if ($provider === 'azure') {
            king_object_store_s3_mock_respond($conn, 201, 'Created', [
                'ETag' => '"' . sha1($body) . '"',
            ]);
        } else {
            king_object_store_s3_mock_respond($conn, 200, 'OK');
        }
        fclose($conn);
        continue;
    }

    if (!is_file($objectPath)) {
        king_object_store_s3_mock_respond($conn, 404, 'Not Found');
        fclose($conn);
        continue;
    }

    if ($method === 'HEAD') {
        $payload = (string) file_get_contents($objectPath);
        $metadata = king_object_store_s3_mock_load_object_metadata($metadataDirectory, $objectId);
        $headersOut = king_object_store_s3_mock_build_object_headers(
            $provider,
            $payload,
            $metadata,
            filemtime($objectPath)
        );
        king_object_store_s3_mock_respond($conn, 200, 'OK', $headersOut);
        fclose($conn);
        continue;
    }

    if ($method === 'GET') {
        $payload = (string) file_get_contents($objectPath);
        $metadata = king_object_store_s3_mock_load_object_metadata($metadataDirectory, $objectId);
        $range = king_object_store_s3_mock_parse_range($headers['range'] ?? null, strlen($payload));

        if ($range === false) {
            king_object_store_s3_mock_respond($conn, 416, 'Range Not Satisfiable', [
                'Content-Range' => 'bytes */' . strlen($payload),
            ]);
            fclose($conn);
            continue;
        }

        if (is_array($range)) {
            $payload = substr($payload, $range['start'], ($range['end'] - $range['start']) + 1);
            $headersOut = king_object_store_s3_mock_build_object_headers(
                $provider,
                $payload,
                $metadata,
                filemtime($objectPath),
                $range
            );
            king_object_store_s3_mock_respond($conn, 206, 'Partial Content', $headersOut, $payload);
            fclose($conn);
            continue;
        }

        $headersOut = king_object_store_s3_mock_build_object_headers(
            $provider,
            $payload,
            $metadata,
            filemtime($objectPath)
        );
        king_object_store_s3_mock_respond($conn, 200, 'OK', $headersOut, $payload);
        fclose($conn);
        continue;
    }

    if ($method === 'DELETE') {
        @unlink($objectPath);
        @unlink(king_object_store_s3_mock_metadata_path($metadataDirectory, $objectId));
        if ($provider === 'azure') {
            king_object_store_s3_mock_respond($conn, 202, 'Accepted');
        } else {
            king_object_store_s3_mock_respond($conn, 204, 'No Content');
        }
        fclose($conn);
        continue;
    }

    king_object_store_s3_mock_respond($conn, 405, 'Method Not Allowed');
    fclose($conn);
}

fclose($server);
