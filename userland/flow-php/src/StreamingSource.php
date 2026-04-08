<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;
use RuntimeException;

interface StreamingSource
{
    public function pumpBytes(callable $onChunk, ?SourceCursor $cursor = null): SourcePumpResult;

    public function pumpLines(callable $onLine, ?SourceCursor $cursor = null, string $delimiter = "\n"): SourcePumpResult;
}

final class SourceCursor
{
    /** @var array<string,mixed> */
    private array $state;

    /**
     * @param array<string,mixed> $state
     */
    public function __construct(
        private string $transport,
        private string $identity,
        private int $bytesConsumed = 0,
        private string $resumeStrategy = 'replay_and_skip',
        array $state = []
    ) {
        if ($bytesConsumed < 0) {
            throw new InvalidArgumentException('bytesConsumed must be zero or greater.');
        }

        $this->state = $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    public function withState(array $state): self
    {
        return new self(
            $this->transport,
            $this->identity,
            $this->bytesConsumed,
            $this->resumeStrategy,
            $state
        );
    }

    public function transport(): string
    {
        return $this->transport;
    }

    public function identity(): string
    {
        return $this->identity;
    }

    public function bytesConsumed(): int
    {
        return $this->bytesConsumed;
    }

    public function resumeStrategy(): string
    {
        return $this->resumeStrategy;
    }

    /**
     * @return array<string,mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'transport' => $this->transport,
            'identity' => $this->identity,
            'bytes_consumed' => $this->bytesConsumed,
            'resume_strategy' => $this->resumeStrategy,
            'state' => $this->state,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['transport'], $data['identity'])) {
            throw new InvalidArgumentException('cursor array must contain transport and identity.');
        }

        return new self(
            (string) $data['transport'],
            (string) $data['identity'],
            (int) ($data['bytes_consumed'] ?? 0),
            (string) ($data['resume_strategy'] ?? 'replay_and_skip'),
            is_array($data['state'] ?? null) ? $data['state'] : []
        );
    }
}

final class SourcePumpResult
{
    public function __construct(
        private SourceCursor $cursor,
        private bool $complete,
        private int $chunksDelivered,
        private int $bytesDelivered,
        private int $recordsDelivered = 0
    ) {
    }

    public function cursor(): SourceCursor
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function chunksDelivered(): int
    {
        return $this->chunksDelivered;
    }

    public function bytesDelivered(): int
    {
        return $this->bytesDelivered;
    }

    public function recordsDelivered(): int
    {
        return $this->recordsDelivered;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'cursor' => $this->cursor->toArray(),
            'complete' => $this->complete,
            'chunks_delivered' => $this->chunksDelivered,
            'bytes_delivered' => $this->bytesDelivered,
            'records_delivered' => $this->recordsDelivered,
        ];
    }
}

abstract class AbstractStreamingSource implements StreamingSource
{
    public function pumpLines(callable $onLine, ?SourceCursor $cursor = null, string $delimiter = "\n"): SourcePumpResult
    {
        if ($delimiter === '') {
            throw new InvalidArgumentException('delimiter must not be empty.');
        }

        $carry = (string) (($cursor?->state()['line_buffer'] ?? ''));
        $recordsDelivered = 0;

        $recordsDelivered = 0;
        $byteCursor = $cursor;
        $complete = true;
        $chunksDelivered = 0;
        $bytesDelivered = 0;

        $result = $this->pumpBytes(
            function (string $chunk, SourceCursor $nextCursor, bool $sourceComplete) use (
                &$carry,
                &$recordsDelivered,
                &$byteCursor,
                &$complete,
                &$chunksDelivered,
                &$bytesDelivered,
                $delimiter,
                $onLine
            ): bool {
                $chunksDelivered++;
                $bytesDelivered += strlen($chunk);
                $carry .= $chunk;
                $delimiterLength = strlen($delimiter);
                $byteCursor = $nextCursor;

                while (($position = strpos($carry, $delimiter)) !== false) {
                    $line = substr($carry, 0, $position);
                    $carry = substr($carry, $position + $delimiterLength);
                    $recordsDelivered++;

                    $state = $nextCursor->state();
                    $state['line_buffer'] = $carry;
                    $state['line_delimiter'] = $delimiter;
                    $lineCursor = $nextCursor->withState($state);

                    $continue = $onLine($line, $lineCursor, false);
                    if ($continue === false) {
                        $complete = false;
                        return false;
                    }
                }

                if ($sourceComplete && $carry !== '') {
                    $recordsDelivered++;
                    $state = $nextCursor->state();
                    $state['line_buffer'] = '';
                    $state['line_delimiter'] = $delimiter;
                    $lineCursor = $nextCursor->withState($state);

                    $continue = $onLine($carry, $lineCursor, true);
                    $carry = '';
                    if ($continue === false) {
                        $complete = false;
                        return false;
                    }
                }

                return true;
            },
            $cursor
        );

        $state = $result->cursor()->state();
        $state['line_buffer'] = $carry;
        $state['line_delimiter'] = $delimiter;
        $finalCursor = $result->cursor()->withState($state);

        return new SourcePumpResult(
            $finalCursor,
            $complete && $result->complete(),
            $chunksDelivered,
            $bytesDelivered,
            $recordsDelivered
        );
    }

    protected function resumeOffset(?SourceCursor $cursor, string $transport, string $identity): int
    {
        if ($cursor === null) {
            return 0;
        }

        if ($cursor->transport() !== $transport) {
            throw new InvalidArgumentException('resume cursor transport does not match this source.');
        }

        if ($cursor->identity() !== $identity) {
            throw new InvalidArgumentException('resume cursor identity does not match this source.');
        }

        return $cursor->bytesConsumed();
    }
}

final class ObjectStoreByteSource extends AbstractStreamingSource
{
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        private string $objectId,
        private int $chunkBytes = 8192,
        array $options = []
    ) {
        if ($chunkBytes <= 0) {
            throw new InvalidArgumentException('chunkBytes must be greater than zero.');
        }

        if (array_key_exists('offset', $options) || array_key_exists('length', $options)) {
            throw new InvalidArgumentException('offset and length are managed by the source cursor.');
        }

        $this->options = $options;
    }

    public function pumpBytes(callable $onChunk, ?SourceCursor $cursor = null): SourcePumpResult
    {
        $identity = $this->objectId;
        $offset = $this->resumeOffset($cursor, 'object_store', $identity);
        $meta = \king_object_store_get_metadata($this->objectId);

        if (!is_array($meta)) {
            throw new RuntimeException('object-store source could not resolve metadata for the requested object.');
        }

        $contentLength = (int) ($meta['content_length'] ?? 0);
        if ($offset > $contentLength) {
            throw new InvalidArgumentException('resume cursor is beyond the end of the current object payload.');
        }

        $chunksDelivered = 0;
        $bytesDelivered = 0;

        while ($offset < $contentLength) {
            $length = min($this->chunkBytes, $contentLength - $offset);
            $stream = fopen('php://temp/maxmemory:' . (string) max($length, 1), 'w+');
            if (!is_resource($stream)) {
                throw new RuntimeException('object-store source could not allocate a bounded read buffer.');
            }

            $readOptions = $this->options;
            $readOptions['offset'] = $offset;
            $readOptions['length'] = $length;

            try {
                $ok = \king_object_store_get_to_stream($this->objectId, $stream, $readOptions);
                if ($ok !== true) {
                    throw new RuntimeException('object-store source read failed for the requested range.');
                }

                rewind($stream);
                $chunk = (string) stream_get_contents($stream);
            } finally {
                fclose($stream);
            }

            if ($chunk === '') {
                throw new RuntimeException('object-store source read returned an empty chunk before the payload ended.');
            }

            $offset += strlen($chunk);
            $chunksDelivered++;
            $bytesDelivered += strlen($chunk);

            $nextCursor = new SourceCursor(
                'object_store',
                $identity,
                $offset,
                'range_offset',
                [
                    'object_id' => $this->objectId,
                    'content_length' => $contentLength,
                    'next_offset' => $offset,
                ]
            );

            $continue = $onChunk($chunk, $nextCursor, $offset >= $contentLength);
            if ($continue === false) {
                return new SourcePumpResult($nextCursor, false, $chunksDelivered, $bytesDelivered);
            }
        }

        return new SourcePumpResult(
            new SourceCursor(
                'object_store',
                $identity,
                $offset,
                'range_offset',
                [
                    'object_id' => $this->objectId,
                    'content_length' => $contentLength,
                    'next_offset' => $offset,
                ]
            ),
            true,
            $chunksDelivered,
            $bytesDelivered
        );
    }
}

final class HttpByteSource extends AbstractStreamingSource
{
    /** @var array<string,mixed> */
    private array $headers;

    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array<string,mixed> $headers
     * @param array<string,mixed> $options
     */
    public function __construct(
        private string $url,
        private string $method = 'GET',
        array $headers = [],
        private mixed $body = null,
        private int $chunkBytes = 8192,
        array $options = []
    ) {
        if ($chunkBytes <= 0) {
            throw new InvalidArgumentException('chunkBytes must be greater than zero.');
        }

        $this->headers = $headers;
        $this->options = $options;
        $this->options['response_stream'] = true;
    }

    public function pumpBytes(callable $onChunk, ?SourceCursor $cursor = null): SourcePumpResult
    {
        $identity = strtoupper($this->method) . ' ' . $this->url;
        $offset = $this->resumeOffset($cursor, 'http', $identity);
        $context = \king_client_send_request(
            $this->url,
            $this->method,
            $this->headers,
            $this->body,
            $this->options
        );

        if (!is_resource($context)) {
            throw new RuntimeException('http source did not receive a streaming request context.');
        }

        $response = \king_receive_response($context);
        $this->discardHttpPrefix($response, $offset);

        $chunksDelivered = 0;
        $bytesDelivered = 0;
        $bytesConsumed = $offset;

        while (!$response->isEndOfBody()) {
            $chunk = $response->read($this->chunkBytes);
            if ($chunk === '') {
                continue;
            }

            $bytesConsumed += strlen($chunk);
            $bytesDelivered += strlen($chunk);
            $chunksDelivered++;

            $nextCursor = new SourceCursor(
                'http',
                $identity,
                $bytesConsumed,
                'replay_and_skip',
                [
                    'url' => $this->url,
                    'method' => strtoupper($this->method),
                    'next_offset' => $bytesConsumed,
                ]
            );

            $continue = $onChunk($chunk, $nextCursor, $response->isEndOfBody());
            if ($continue === false) {
                return new SourcePumpResult($nextCursor, false, $chunksDelivered, $bytesDelivered);
            }
        }

        return new SourcePumpResult(
            new SourceCursor(
                'http',
                $identity,
                $bytesConsumed,
                'replay_and_skip',
                [
                    'url' => $this->url,
                    'method' => strtoupper($this->method),
                    'next_offset' => $bytesConsumed,
                ]
            ),
            true,
            $chunksDelivered,
            $bytesDelivered
        );
    }

    private function discardHttpPrefix(\King\Response $response, int $offset): void
    {
        $remaining = $offset;
        while ($remaining > 0) {
            $discard = $response->read(min($this->chunkBytes, $remaining));
            if ($discard === '' && $response->isEndOfBody()) {
                break;
            }

            $remaining -= strlen($discard);
        }

        if ($remaining > 0) {
            throw new RuntimeException('http source could not resume because the response ended before the requested offset.');
        }
    }
}

final class McpByteSource extends AbstractStreamingSource
{
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        private mixed $connection,
        private string $service,
        private string $method,
        private string $payload,
        private int $chunkBytes = 8192,
        array $options = []
    ) {
        if ($chunkBytes <= 0) {
            throw new InvalidArgumentException('chunkBytes must be greater than zero.');
        }

        $this->options = $options;
    }

    public function pumpBytes(callable $onChunk, ?SourceCursor $cursor = null): SourcePumpResult
    {
        $identity = $this->service . ':' . $this->method . ':' . $this->payload;
        $offset = $this->resumeOffset($cursor, 'mcp_transfer', $identity);
        $target = CallbackWriteTarget::open(
            'mcp_transfer',
            $identity,
            $offset,
            $this->chunkBytes,
            $onChunk,
            [
                'service' => $this->service,
                'method' => $this->method,
                'payload' => $this->payload,
                'next_offset' => $offset,
            ]
        );

        $ok = false;
        $stopped = false;

        try {
            if (is_object($this->connection) && $this->connection instanceof \King\MCP) {
                $this->connection->downloadToStream(
                    $this->service,
                    $this->method,
                    $this->payload,
                    $target->stream(),
                    $this->options
                );
                $ok = true;
            } else {
                $ok = \king_mcp_download_to_stream(
                    $this->connection,
                    $this->service,
                    $this->method,
                    $this->payload,
                    $target->stream(),
                    $this->options
                );
            }
        } catch (SourceConsumerStopped) {
            $stopped = true;
        } finally {
            $target->close();
        }

        if ($stopped || $target->session()->stopped()) {
            return new SourcePumpResult(
                $target->session()->cursor(),
                false,
                $target->session()->chunksDelivered(),
                $target->session()->bytesDelivered()
            );
        }

        if ($ok !== true) {
            throw new RuntimeException('mcp source download failed for the requested transfer identity.');
        }

        return new SourcePumpResult(
            $target->session()->cursor(),
            true,
            $target->session()->chunksDelivered(),
            $target->session()->bytesDelivered()
        );
    }
}

final class SourceConsumerStopped extends RuntimeException
{
}

final class CallbackWriteTarget
{
    private const SCHEME = 'kingflowcallback';

    private static bool $registered = false;

    private static int $nextIdentifier = 0;

    /** @var array<string,CallbackWriteSession> */
    private static array $sessions = [];

    private function __construct(
        private string $identifier,
        private CallbackWriteSession $session,
        private mixed $stream
    ) {
    }

    /**
     * @param array<string,mixed> $cursorState
     */
    public static function open(
        string $transport,
        string $identity,
        int $resumeOffset,
        int $chunkBytes,
        callable $onChunk,
        array $cursorState = []
    ): self {
        self::register();
        $identifier = (string) (++self::$nextIdentifier);
        $session = new CallbackWriteSession(
            $transport,
            $identity,
            $resumeOffset,
            $chunkBytes,
            $onChunk,
            $cursorState
        );
        self::$sessions[$identifier] = $session;
        $stream = fopen(self::SCHEME . '://' . $identifier, 'wb');
        if (!is_resource($stream)) {
            unset(self::$sessions[$identifier]);
            throw new RuntimeException('failed to allocate callback write target.');
        }

        return new self($identifier, $session, $stream);
    }

    public function stream(): mixed
    {
        return $this->stream;
    }

    public function session(): CallbackWriteSession
    {
        return $this->session;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public static function resolve(string $identifier): CallbackWriteSession
    {
        if (!isset(self::$sessions[$identifier])) {
            throw new RuntimeException('unknown callback write target.');
        }

        return self::$sessions[$identifier];
    }

    public static function release(string $identifier): void
    {
        unset(self::$sessions[$identifier]);
    }

    private static function register(): void
    {
        if (self::$registered) {
            return;
        }

        if (!stream_wrapper_register(self::SCHEME, CallbackWriteStreamWrapper::class)) {
            throw new RuntimeException('failed to register callback write stream wrapper.');
        }

        self::$registered = true;
    }
}

final class CallbackWriteSession
{
    /** @var callable */
    private $onChunk;

    /** @var array<string,mixed> */
    private array $cursorState;

    private int $skipBytesRemaining;

    private int $bytesDelivered = 0;

    private int $chunksDelivered = 0;

    private bool $stopped = false;

    /**
     * @param array<string,mixed> $cursorState
     */
    public function __construct(
        private string $transport,
        private string $identity,
        private int $resumeOffset,
        private int $chunkBytes,
        callable $onChunk,
        array $cursorState = []
    ) {
        $this->onChunk = $onChunk;
        $this->cursorState = $cursorState;
        $this->skipBytesRemaining = $resumeOffset;
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }

    public function chunksDelivered(): int
    {
        return $this->chunksDelivered;
    }

    public function bytesDelivered(): int
    {
        return $this->bytesDelivered;
    }

    public function cursor(): SourceCursor
    {
        $state = $this->cursorState;
        $state['next_offset'] = $this->resumeOffset + $this->bytesDelivered;

        return new SourceCursor(
            $this->transport,
            $this->identity,
            $this->resumeOffset + $this->bytesDelivered,
            'replay_and_skip',
            $state
        );
    }

    public function write(string $data): int
    {
        $writtenLength = strlen($data);

        while ($data !== '') {
            if ($this->skipBytesRemaining > 0) {
                $skip = min($this->skipBytesRemaining, strlen($data));
                $data = (string) substr($data, $skip);
                $this->skipBytesRemaining -= $skip;
                if ($data === '') {
                    break;
                }
            }

            $slice = substr($data, 0, $this->chunkBytes);
            $data = (string) substr($data, strlen($slice));
            $this->bytesDelivered += strlen($slice);
            $this->chunksDelivered++;

            $continue = ($this->onChunk)($slice, $this->cursor(), false);
            if ($continue === false) {
                $this->stopped = true;
                throw new SourceConsumerStopped('stream source stopped by consumer callback.');
            }
        }

        return $writtenLength;
    }
}

class CallbackWriteStreamWrapper
{
    public mixed $context;

    private string $identifier = '';

    private ?CallbackWriteSession $session = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $host = parse_url($path, PHP_URL_HOST);
        $pathComponent = parse_url($path, PHP_URL_PATH);
        $identifier = $host !== null && $host !== '' ? $host : ltrim((string) $pathComponent, '/');

        if ($identifier === '') {
            return false;
        }

        $this->identifier = $identifier;
        $this->session = CallbackWriteTarget::resolve($identifier);

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->session === null) {
            return 0;
        }

        return $this->session->write($data);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        if ($this->identifier !== '') {
            CallbackWriteTarget::release($this->identifier);
        }
    }
}
