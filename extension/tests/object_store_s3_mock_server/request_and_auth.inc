<?php

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
