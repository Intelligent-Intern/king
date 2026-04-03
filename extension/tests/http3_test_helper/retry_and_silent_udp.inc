<?php

function king_http3_request_with_retry(callable $callback)
{
    $lastError = null;
    $deadline = microtime(true) + 60.0;

    while (microtime(true) < $deadline) {
        try {
            $result = $callback();
            if ($result !== false) {
                return $result;
            }

            $message = king_get_last_error();
            if (!is_string($message) || $message === '') {
                $message = 'HTTP/3 request returned false before the local server became ready.';
            }
            $lastError = new RuntimeException($message);
        } catch (Throwable $e) {
            $lastError = $e;
        }

        usleep(250000);
    }

    throw $lastError ?? new RuntimeException('HTTP/3 request retry exhausted without an exception.');
}

function king_http3_exception_with_retry(
    callable $callback,
    string $expectedClass,
    ?string $expectedMessage = null
): Throwable {
    $lastError = null;
    $deadline = microtime(true) + 60.0;

    while (microtime(true) < $deadline) {
        try {
            $callback();
            $lastError = new RuntimeException(
                'HTTP/3 failure-path request unexpectedly succeeded before the local peer reached the expected failure state.'
            );
        } catch (Throwable $e) {
            if (
                $e instanceof $expectedClass
                && ($expectedMessage === null || $e->getMessage() === $expectedMessage)
            ) {
                return $e;
            }

            $lastError = $e;
        }

        usleep(250000);
    }

    throw $lastError ?? new RuntimeException('HTTP/3 exception retry exhausted without the expected failure.');
}

function king_http3_one_shot_result_with_retry(
    callable $startPeer,
    callable $stopPeer,
    callable $attempt,
    ?callable $accept = null
): array {
    $lastError = null;
    $deadline = microtime(true) + 60.0;

    while (microtime(true) < $deadline) {
        $peer = $startPeer();
        $capture = null;

        try {
            $result = $attempt($peer);
            if ($result !== false && ($accept === null || $accept($result, $peer))) {
                $capture = $stopPeer($peer);
                return [
                    'result' => $result,
                    'capture' => $capture,
                ];
            }

            $message = king_get_last_error();
            if (!is_string($message) || $message === '') {
                $message = 'HTTP/3 request returned a result before the one-shot peer reached the expected state.';
            }
            $lastError = new RuntimeException($message);
        } catch (Throwable $e) {
            $lastError = $e;
        } finally {
            if ($capture === null) {
                $stopPeer($peer);
            }
        }

        usleep(250000);
    }

    throw $lastError ?? new RuntimeException('HTTP/3 one-shot retry exhausted without a matching result.');
}

function king_http3_one_shot_exception_with_retry(
    callable $startPeer,
    callable $stopPeer,
    callable $attempt,
    string $expectedClass,
    ?string $expectedMessage = null
): array {
    $lastError = null;
    $deadline = microtime(true) + 60.0;

    while (microtime(true) < $deadline) {
        $peer = $startPeer();
        $capture = null;

        try {
            $attempt($peer);
            $lastError = new RuntimeException(
                'HTTP/3 failure-path request unexpectedly succeeded before the one-shot peer reached the expected failure state.'
            );
        } catch (Throwable $e) {
            if (
                $e instanceof $expectedClass
                && ($expectedMessage === null || $e->getMessage() === $expectedMessage)
            ) {
                $capture = $stopPeer($peer);
                return [
                    'exception' => $e,
                    'capture' => $capture,
                ];
            }

            $lastError = $e;
        } finally {
            if ($capture === null) {
                $stopPeer($peer);
            }
        }

        usleep(250000);
    }

    throw $lastError ?? new RuntimeException('HTTP/3 one-shot exception retry exhausted without the expected failure.');
}

function king_http3_start_silent_udp_server(): array
{
    $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($server === false) {
        throw new RuntimeException("failed to reserve silent UDP test port: $errstr");
    }

    $serverName = stream_socket_get_name($server, false);
    [, $port] = explode(':', $serverName, 2);

    return [$server, (int) $port];
}

function king_http3_stop_silent_udp_server(array $server): void
{
    fclose($server[0]);
}
