<?php

function king_object_store_s3_mock_handle_forced_response(
    $conn,
    array &$options,
    string $method,
    string $target,
    string $provider,
    string $objectsDirectory,
    string $metadataDirectory,
    string $objectId,
    string $body,
    array $headers
): bool {
    global $capture;

    $forcedResponse = king_object_store_s3_mock_take_forced_response($options, $method, $target);
    if ($forcedResponse === null) {
        return false;
    }

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
        return true;
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
    return true;
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
