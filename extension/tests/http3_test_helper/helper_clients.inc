<?php

function king_http3_send_delayed_body_request(string $url, string $body, int $delayMs = 250): array
{
    $binary = king_http3_delayed_body_client_binary();
    $command = [$binary, $url, (string) $delayMs, $body];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch delayed HTTP/3 body client helper');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "delayed HTTP/3 body client helper failed:\n" . trim($stdout . "\n" . $stderr)
        );
    }

    $result = [
        'status' => 0,
        'body' => '',
        'early_response' => false,
    ];

    foreach (preg_split('/\r?\n/', trim($stdout)) as $line) {
        if (!is_string($line) || $line === '') {
            continue;
        }

        if (strncmp($line, 'STATUS ', 7) === 0) {
            $result['status'] = (int) substr($line, 7);
            continue;
        }

        if (strncmp($line, 'BODY ', 5) === 0) {
            $result['body'] = substr($line, 5);
            continue;
        }

        if (strncmp($line, 'EARLY_RESPONSE ', 15) === 0) {
            $result['early_response'] = substr($line, 15) === '1';
        }
    }

    return $result;
}

function king_http3_send_abort_request(string $url, int $delayMs = 100): array
{
    $binary = king_http3_abort_client_binary();
    $command = [$binary, $url, (string) $delayMs];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch HTTP/3 abort client helper');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "HTTP/3 abort client helper failed:\n" . trim($stdout . "\n" . $stderr)
        );
    }

    $result = [
        'aborted' => false,
        'stream_id' => null,
    ];

    foreach (preg_split('/\r?\n/', trim($stdout)) as $line) {
        if (!is_string($line) || $line === '') {
            continue;
        }

        if (preg_match('/^ABORTED (\d+)$/', $line, $matches) === 1) {
            $result['aborted'] = true;
            $result['stream_id'] = (int) $matches[1];
        }
    }

    return $result;
}
