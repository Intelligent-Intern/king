<?php

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
