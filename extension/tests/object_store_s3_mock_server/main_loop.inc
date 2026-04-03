<?php

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

        if (king_object_store_s3_mock_handle_forced_response(
                $conn,
                $options,
                $method,
                $target,
                'gcs',
                $objectsDirectory,
                $metadataDirectory,
                '',
                $body,
                $headers
            )) {
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

    if (king_object_store_s3_mock_handle_forced_response(
            $conn,
            $options,
            $method,
            $target,
            $provider,
            $objectsDirectory,
            $metadataDirectory,
            $objectId,
            $body,
            $headers
        )) {
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
