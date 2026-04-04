<?php

/* Local OTLP collector harness for telemetry export and retry contracts. */

function king_telemetry_otlp_reference_error(string $signal, string $message): array
{
    return [
        'ok' => false,
        'signal' => $signal,
        'error' => $message,
    ];
}

function king_telemetry_otlp_reference_ok(string $signal): array
{
    return [
        'ok' => true,
        'signal' => $signal,
        'error' => null,
    ];
}

function king_telemetry_otlp_reference_is_digit_string(mixed $value): bool
{
    return is_string($value) && $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
}

function king_telemetry_otlp_reference_is_hex_string(mixed $value, int $length): bool
{
    return is_string($value) && strlen($value) === $length && preg_match('/^[0-9a-f]+$/', $value) === 1;
}

function king_telemetry_otlp_reference_validate_any_value(array $value, string $path): ?string
{
    foreach (['stringValue', 'intValue', 'doubleValue', 'boolValue'] as $field) {
        if (!array_key_exists($field, $value)) {
            continue;
        }

        return match ($field) {
            'stringValue' => is_string($value[$field])
                ? null
                : $path . '.stringValue must be a string.',
            'intValue' => king_telemetry_otlp_reference_is_digit_string($value[$field])
                ? null
                : $path . '.intValue must be a base-10 integer string.',
            'doubleValue' => is_int($value[$field]) || is_float($value[$field])
                ? null
                : $path . '.doubleValue must be numeric.',
            'boolValue' => is_bool($value[$field])
                ? null
                : $path . '.boolValue must be a boolean.',
        };
    }

    return $path . ' must contain a supported OTLP AnyValue field.';
}

function king_telemetry_otlp_reference_validate_attributes(mixed $attributes, string $path): ?string
{
    if (!is_array($attributes)) {
        return $path . ' must be an array.';
    }

    foreach ($attributes as $index => $attribute) {
        if (!is_array($attribute)) {
            return $path . '[' . $index . '] must be an object.';
        }
        if (!isset($attribute['key']) || !is_string($attribute['key']) || $attribute['key'] === '') {
            return $path . '[' . $index . '].key must be a non-empty string.';
        }
        if (!isset($attribute['value']) || !is_array($attribute['value'])) {
            return $path . '[' . $index . '].value must be an OTLP AnyValue object.';
        }

        $error = king_telemetry_otlp_reference_validate_any_value(
            $attribute['value'],
            $path . '[' . $index . '].value'
        );
        if ($error !== null) {
            return $error;
        }
    }

    return null;
}

function king_telemetry_otlp_reference_validate_common_data_point(
    mixed $point,
    string $path
): ?string {
    if (!is_array($point)) {
        return $path . ' must be an object.';
    }
    if (!king_telemetry_otlp_reference_is_digit_string($point['timeUnixNano'] ?? null)) {
        return $path . '.timeUnixNano must be a digit string.';
    }
    if (array_key_exists('attributes', $point)) {
        return king_telemetry_otlp_reference_validate_attributes($point['attributes'], $path . '.attributes');
    }

    return null;
}

function king_telemetry_otlp_reference_validate_metrics_payload(array $payload): array
{
    $resourceMetrics = $payload['resourceMetrics'] ?? null;

    if (!is_array($resourceMetrics) || $resourceMetrics === []) {
        return king_telemetry_otlp_reference_error('metrics', 'resourceMetrics must be a non-empty array.');
    }

    foreach ($resourceMetrics as $resourceIndex => $resourceMetric) {
        $scopeMetrics = $resourceMetric['scopeMetrics'] ?? null;
        if (!is_array($scopeMetrics) || $scopeMetrics === []) {
            return king_telemetry_otlp_reference_error(
                'metrics',
                'resourceMetrics[' . $resourceIndex . '].scopeMetrics must be a non-empty array.'
            );
        }

        foreach ($scopeMetrics as $scopeIndex => $scopeMetric) {
            $metrics = $scopeMetric['metrics'] ?? null;
            if (!is_array($metrics) || $metrics === []) {
                return king_telemetry_otlp_reference_error(
                    'metrics',
                    'scopeMetrics[' . $scopeIndex . '].metrics must be a non-empty array.'
                );
            }

            foreach ($metrics as $metricIndex => $metric) {
                $metricPath = 'metrics[' . $metricIndex . ']';
                $signalKey = null;
                $dataPoints = null;

                if (!is_array($metric)) {
                    return king_telemetry_otlp_reference_error('metrics', $metricPath . ' must be an object.');
                }
                if (!isset($metric['name']) || !is_string($metric['name']) || $metric['name'] === '') {
                    return king_telemetry_otlp_reference_error(
                        'metrics',
                        $metricPath . '.name must be a non-empty string.'
                    );
                }

                foreach (['sum', 'gauge', 'histogram', 'summary'] as $candidate) {
                    if (isset($metric[$candidate]) && is_array($metric[$candidate])) {
                        $signalKey = $candidate;
                        $dataPoints = $metric[$candidate]['dataPoints'] ?? null;
                        break;
                    }
                }

                if ($signalKey === null || !is_array($dataPoints) || $dataPoints === []) {
                    return king_telemetry_otlp_reference_error(
                        'metrics',
                        $metricPath . ' must contain one supported OTLP metric body with dataPoints.'
                    );
                }

                if (($signalKey === 'sum' || $signalKey === 'histogram')
                    && !in_array(($metric[$signalKey]['aggregationTemporality'] ?? null), [1, 2], true)) {
                    return king_telemetry_otlp_reference_error(
                        'metrics',
                        $metricPath . '.' . $signalKey . '.aggregationTemporality must be 1 or 2.'
                    );
                }

                if ($signalKey === 'sum' && !is_bool($metric[$signalKey]['isMonotonic'] ?? null)) {
                    return king_telemetry_otlp_reference_error(
                        'metrics',
                        $metricPath . '.sum.isMonotonic must be a boolean.'
                    );
                }

                foreach ($dataPoints as $pointIndex => $point) {
                    $pointPath = $metricPath . '.' . $signalKey . '.dataPoints[' . $pointIndex . ']';
                    $error = king_telemetry_otlp_reference_validate_common_data_point($point, $pointPath);
                    if ($error !== null) {
                        return king_telemetry_otlp_reference_error('metrics', $error);
                    }

                    if ($signalKey === 'sum' || $signalKey === 'gauge') {
                        $hasInt = array_key_exists('asInt', $point);
                        $hasDouble = array_key_exists('asDouble', $point);
                        if (!$hasInt && !$hasDouble) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . ' must contain asInt or asDouble.'
                            );
                        }
                        if ($hasInt && !king_telemetry_otlp_reference_is_digit_string($point['asInt'])) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.asInt must be a digit string.'
                            );
                        }
                        if ($hasDouble && !is_int($point['asDouble']) && !is_float($point['asDouble'])) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.asDouble must be numeric.'
                            );
                        }
                        continue;
                    }

                    if (!king_telemetry_otlp_reference_is_digit_string($point['count'] ?? null)) {
                        return king_telemetry_otlp_reference_error(
                            'metrics',
                            $pointPath . '.count must be a digit string.'
                        );
                    }
                    if (!is_int($point['sum'] ?? null) && !is_float($point['sum'] ?? null)) {
                        return king_telemetry_otlp_reference_error(
                            'metrics',
                            $pointPath . '.sum must be numeric.'
                        );
                    }

                    if ($signalKey === 'histogram') {
                        $bucketCounts = $point['bucketCounts'] ?? null;
                        $explicitBounds = $point['explicitBounds'] ?? null;

                        if (!is_array($bucketCounts) || $bucketCounts === []) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.bucketCounts must be a non-empty array.'
                            );
                        }
                        if (!is_array($explicitBounds)) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.explicitBounds must be an array.'
                            );
                        }
                        if (count($bucketCounts) !== count($explicitBounds) + 1) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.bucketCounts must have one more entry than explicitBounds.'
                            );
                        }
                        foreach ($bucketCounts as $bucketIndex => $bucketCount) {
                            if (!king_telemetry_otlp_reference_is_digit_string($bucketCount)) {
                                return king_telemetry_otlp_reference_error(
                                    'metrics',
                                    $pointPath . '.bucketCounts[' . $bucketIndex . '] must be a digit string.'
                                );
                            }
                        }
                        foreach ($explicitBounds as $boundIndex => $bound) {
                            if (!is_int($bound) && !is_float($bound)) {
                                return king_telemetry_otlp_reference_error(
                                    'metrics',
                                    $pointPath . '.explicitBounds[' . $boundIndex . '] must be numeric.'
                                );
                            }
                        }
                        continue;
                    }

                    $quantileValues = $point['quantileValues'] ?? null;
                    if (!is_array($quantileValues) || $quantileValues === []) {
                        return king_telemetry_otlp_reference_error(
                            'metrics',
                            $pointPath . '.quantileValues must be a non-empty array.'
                        );
                    }
                    foreach ($quantileValues as $quantileIndex => $quantileValue) {
                        if (!is_array($quantileValue)) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.quantileValues[' . $quantileIndex . '] must be an object.'
                            );
                        }
                        if (!is_int($quantileValue['quantile'] ?? null)
                            && !is_float($quantileValue['quantile'] ?? null)) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.quantileValues[' . $quantileIndex . '].quantile must be numeric.'
                            );
                        }
                        if (!is_int($quantileValue['value'] ?? null)
                            && !is_float($quantileValue['value'] ?? null)) {
                            return king_telemetry_otlp_reference_error(
                                'metrics',
                                $pointPath . '.quantileValues[' . $quantileIndex . '].value must be numeric.'
                            );
                        }
                    }
                }
            }
        }
    }

    return king_telemetry_otlp_reference_ok('metrics');
}

function king_telemetry_otlp_reference_validate_traces_payload(array $payload): array
{
    $resourceSpans = $payload['resourceSpans'] ?? null;

    if (!is_array($resourceSpans) || $resourceSpans === []) {
        return king_telemetry_otlp_reference_error('traces', 'resourceSpans must be a non-empty array.');
    }

    foreach ($resourceSpans as $resourceIndex => $resourceSpan) {
        $scopeSpans = $resourceSpan['scopeSpans'] ?? null;
        if (!is_array($scopeSpans) || $scopeSpans === []) {
            return king_telemetry_otlp_reference_error(
                'traces',
                'resourceSpans[' . $resourceIndex . '].scopeSpans must be a non-empty array.'
            );
        }

        foreach ($scopeSpans as $scopeIndex => $scopeSpan) {
            $spans = $scopeSpan['spans'] ?? null;
            if (!is_array($spans) || $spans === []) {
                return king_telemetry_otlp_reference_error(
                    'traces',
                    'scopeSpans[' . $scopeIndex . '].spans must be a non-empty array.'
                );
            }

            foreach ($spans as $spanIndex => $span) {
                $spanPath = 'spans[' . $spanIndex . ']';
                if (!is_array($span)) {
                    return king_telemetry_otlp_reference_error('traces', $spanPath . ' must be an object.');
                }
                if (!king_telemetry_otlp_reference_is_hex_string($span['traceId'] ?? null, 32)) {
                    return king_telemetry_otlp_reference_error(
                        'traces',
                        $spanPath . '.traceId must be 32 lowercase hex chars.'
                    );
                }
                if (!king_telemetry_otlp_reference_is_hex_string($span['spanId'] ?? null, 16)) {
                    return king_telemetry_otlp_reference_error(
                        'traces',
                        $spanPath . '.spanId must be 16 lowercase hex chars.'
                    );
                }
                if (!isset($span['name']) || !is_string($span['name']) || $span['name'] === '') {
                    return king_telemetry_otlp_reference_error(
                        'traces',
                        $spanPath . '.name must be a non-empty string.'
                    );
                }
                if (!king_telemetry_otlp_reference_is_digit_string($span['startTimeUnixNano'] ?? null)) {
                    return king_telemetry_otlp_reference_error(
                        'traces',
                        $spanPath . '.startTimeUnixNano must be a digit string.'
                    );
                }
                if (!king_telemetry_otlp_reference_is_digit_string($span['endTimeUnixNano'] ?? null)) {
                    return king_telemetry_otlp_reference_error(
                        'traces',
                        $spanPath . '.endTimeUnixNano must be a digit string.'
                    );
                }
                if (!in_array(($span['kind'] ?? null), [1, 2, 3, 4, 5], true)) {
                    return king_telemetry_otlp_reference_error(
                        'traces',
                        $spanPath . '.kind must be a valid OTLP span kind.'
                    );
                }
                if (isset($span['parentSpanId'])
                    && !king_telemetry_otlp_reference_is_hex_string($span['parentSpanId'], 16)) {
                    return king_telemetry_otlp_reference_error(
                        'traces',
                        $spanPath . '.parentSpanId must be 16 lowercase hex chars.'
                    );
                }
                if (array_key_exists('attributes', $span)) {
                    $error = king_telemetry_otlp_reference_validate_attributes($span['attributes'], $spanPath . '.attributes');
                    if ($error !== null) {
                        return king_telemetry_otlp_reference_error('traces', $error);
                    }
                }
            }
        }
    }

    return king_telemetry_otlp_reference_ok('traces');
}

function king_telemetry_otlp_reference_validate_logs_payload(array $payload): array
{
    $resourceLogs = $payload['resourceLogs'] ?? null;

    if (!is_array($resourceLogs) || $resourceLogs === []) {
        return king_telemetry_otlp_reference_error('logs', 'resourceLogs must be a non-empty array.');
    }

    foreach ($resourceLogs as $resourceIndex => $resourceLog) {
        $scopeLogs = $resourceLog['scopeLogs'] ?? null;
        if (!is_array($scopeLogs) || $scopeLogs === []) {
            return king_telemetry_otlp_reference_error(
                'logs',
                'resourceLogs[' . $resourceIndex . '].scopeLogs must be a non-empty array.'
            );
        }

        foreach ($scopeLogs as $scopeIndex => $scopeLog) {
            $records = $scopeLog['logRecords'] ?? null;
            if (!is_array($records) || $records === []) {
                return king_telemetry_otlp_reference_error(
                    'logs',
                    'scopeLogs[' . $scopeIndex . '].logRecords must be a non-empty array.'
                );
            }

            foreach ($records as $recordIndex => $record) {
                $recordPath = 'logRecords[' . $recordIndex . ']';
                if (!is_array($record)) {
                    return king_telemetry_otlp_reference_error('logs', $recordPath . ' must be an object.');
                }
                if (!king_telemetry_otlp_reference_is_digit_string($record['timeUnixNano'] ?? null)) {
                    return king_telemetry_otlp_reference_error(
                        'logs',
                        $recordPath . '.timeUnixNano must be a digit string.'
                    );
                }
                if (!is_int($record['severityNumber'] ?? null)) {
                    return king_telemetry_otlp_reference_error(
                        'logs',
                        $recordPath . '.severityNumber must be an integer.'
                    );
                }
                if (!isset($record['severityText']) || !is_string($record['severityText']) || $record['severityText'] === '') {
                    return king_telemetry_otlp_reference_error(
                        'logs',
                        $recordPath . '.severityText must be a non-empty string.'
                    );
                }
                if (!isset($record['body']) || !is_array($record['body'])) {
                    return king_telemetry_otlp_reference_error(
                        'logs',
                        $recordPath . '.body must be an OTLP AnyValue object.'
                    );
                }
                $error = king_telemetry_otlp_reference_validate_any_value($record['body'], $recordPath . '.body');
                if ($error !== null) {
                    return king_telemetry_otlp_reference_error('logs', $error);
                }
                if (isset($record['traceId'])
                    && !king_telemetry_otlp_reference_is_hex_string($record['traceId'], 32)) {
                    return king_telemetry_otlp_reference_error(
                        'logs',
                        $recordPath . '.traceId must be 32 lowercase hex chars.'
                    );
                }
                if (isset($record['spanId'])
                    && !king_telemetry_otlp_reference_is_hex_string($record['spanId'], 16)) {
                    return king_telemetry_otlp_reference_error(
                        'logs',
                        $recordPath . '.spanId must be 16 lowercase hex chars.'
                    );
                }
                if (array_key_exists('attributes', $record)) {
                    $error = king_telemetry_otlp_reference_validate_attributes($record['attributes'], $recordPath . '.attributes');
                    if ($error !== null) {
                        return king_telemetry_otlp_reference_error('logs', $error);
                    }
                }
            }
        }
    }

    return king_telemetry_otlp_reference_ok('logs');
}

function king_telemetry_otlp_reference_validate_request(string $path, array $headers, string $body): array
{
    $contentType = strtolower((string) ($headers['content-type'] ?? ''));

    if ($contentType === '' || !str_starts_with($contentType, 'application/json')) {
        return king_telemetry_otlp_reference_error(
            'unknown',
            'collector expected Content-Type application/json.'
        );
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return king_telemetry_otlp_reference_error(
            'unknown',
            'collector could not decode OTLP JSON payload.'
        );
    }

    return match ($path) {
        '/v1/metrics' => king_telemetry_otlp_reference_validate_metrics_payload($payload),
        '/v1/traces' => king_telemetry_otlp_reference_validate_traces_payload($payload),
        '/v1/logs' => king_telemetry_otlp_reference_validate_logs_payload($payload),
        default => king_telemetry_otlp_reference_error(
            'unknown',
            'collector observed an unexpected OTLP path ' . $path . '.'
        ),
    };
}

$capturePath = $argv[1];
$planPath = $argv[2];
$port = (int) $argv[3];
$bindHost = $argv[4] ?? '127.0.0.1';
$plan = json_decode((string) file_get_contents($planPath), true);

if (!is_array($plan)) {
    $plan = [];
}

$server = stream_socket_server("tcp://{$bindHost}:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");
$captures = [];
$requestIndex = 0;

while (($conn = @stream_socket_accept($server, 15)) !== false) {
    stream_set_timeout($conn, 15);

    $buffer = fread($conn, 8192);
    if ($buffer === false || $buffer === '') {
        fclose($conn);
        continue;
    }

    if (strncmp($buffer, "STOP\n", 5) == 0 || strncmp($buffer, "STOP\r\n", 6) == 0) {
        fclose($conn);
        break;
    }

    while (!str_contains($buffer, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }

    [$head, $body] = array_pad(explode("\r\n\r\n", $buffer, 2), 2, '');
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    $parts = explode(' ', $requestLine, 3);
    $method = $parts[0] ?? '';
    $path = $parts[1] ?? '';
    $headers = [];
    $contentLength = 0;

    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    if (isset($headers['content-length'])) {
        $contentLength = (int) $headers['content-length'];
    }

    while (strlen($body) < $contentLength) {
        $chunk = fread($conn, $contentLength - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    $entry = $plan[$requestIndex] ?? [];
    $requestIndex++;
    $validation = null;

    if (($entry['validate'] ?? null) === 'otlp_reference') {
        $validation = king_telemetry_otlp_reference_validate_request($path, $headers, $body);
    }

    $captures[] = [
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'headers' => $headers,
        'validation' => $validation,
    ];
    file_put_contents($capturePath, json_encode($captures, JSON_PRETTY_PRINT));

    if (isset($entry['delay_ms'])) {
        usleep((int) $entry['delay_ms'] * 1000);
    }

    $status = (int) ($entry['status'] ?? (($validation['ok'] ?? true) ? 200 : 400));
    $responseBody = (string) ($entry['body'] ?? (($validation['ok'] ?? true) ? 'ok' : (string) ($validation['error'] ?? 'invalid')));
    if (isset($entry['body_bytes']) && (int) $entry['body_bytes'] > 0) {
        $responseBody = str_repeat('R', (int) $entry['body_bytes']);
    }

    $statusText = match ($status) {
        200 => 'OK',
        202 => 'Accepted',
        400 => 'Bad Request',
        413 => 'Payload Too Large',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        default => 'Status',
    };

    $response = "HTTP/1.1 {$status} {$statusText}\r\n"
        . 'Content-Length: ' . strlen($responseBody) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $responseBody;
    fwrite($conn, $response);
    fclose($conn);
}

file_put_contents($capturePath, json_encode($captures, JSON_PRETTY_PRINT));
fclose($server);
