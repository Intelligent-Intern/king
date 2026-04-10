<?php
declare(strict_types=1);

namespace King\Flow;

require_once __DIR__ . '/FailureTaxonomy.php';

use InvalidArgumentException;
use RuntimeException;
use Throwable;

interface StreamingSink
{
    public function write(string $chunk): SinkWriteResult;

    public function complete(?string $finalChunk = null): SinkWriteResult;

    public function abort(): bool;

    public function cursor(): SinkCursor;
}

final class SinkCursor
{
    /** @var array<string,mixed> */
    private array $state;

    /**
     * @param array<string,mixed> $state
     */
    public function __construct(
        private string $transport,
        private string $identity,
        private int $bytesAccepted = 0,
        private string $resumeStrategy = 'restart_request',
        array $state = []
    ) {
        if ($bytesAccepted < 0) {
            throw new InvalidArgumentException('bytesAccepted must be zero or greater.');
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
            $this->bytesAccepted,
            $this->resumeStrategy,
            $state
        );
    }

    public function withBytesAccepted(int $bytesAccepted): self
    {
        return new self(
            $this->transport,
            $this->identity,
            $bytesAccepted,
            $this->resumeStrategy,
            $this->state
        );
    }

    public function withResumeStrategy(string $resumeStrategy): self
    {
        return new self(
            $this->transport,
            $this->identity,
            $this->bytesAccepted,
            $resumeStrategy,
            $this->state
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

    public function bytesAccepted(): int
    {
        return $this->bytesAccepted;
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
            'bytes_accepted' => $this->bytesAccepted,
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
            (int) ($data['bytes_accepted'] ?? 0),
            (string) ($data['resume_strategy'] ?? 'restart_request'),
            is_array($data['state'] ?? null) ? $data['state'] : []
        );
    }
}

final class SinkFailure
{
    public function __construct(
        private string $stage,
        private string $category,
        private string $exceptionClass,
        private string $message,
        private bool $partialFailure,
        private bool $retryable
    ) {
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function exceptionClass(): string
    {
        return $this->exceptionClass;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function partialFailure(): bool
    {
        return $this->partialFailure;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'category' => $this->category,
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'partial_failure' => $this->partialFailure,
            'retryable' => $this->retryable,
        ];
    }
}

final class SinkWriteResult
{
    /** @var array<string,mixed> */
    private array $details;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        private SinkCursor $cursor,
        private bool $complete,
        private bool $transportCommitted,
        private int $bytesAccepted,
        private int $writesAccepted,
        private ?SinkFailure $failure = null,
        array $details = []
    ) {
        $this->details = $details;
    }

    public function cursor(): SinkCursor
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function transportCommitted(): bool
    {
        return $this->transportCommitted;
    }

    public function bytesAccepted(): int
    {
        return $this->bytesAccepted;
    }

    public function writesAccepted(): int
    {
        return $this->writesAccepted;
    }

    public function failure(): ?SinkFailure
    {
        return $this->failure;
    }

    /**
     * @return array<string,mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'cursor' => $this->cursor->toArray(),
            'complete' => $this->complete,
            'transport_committed' => $this->transportCommitted,
            'bytes_accepted' => $this->bytesAccepted,
            'writes_accepted' => $this->writesAccepted,
            'failure' => $this->failure?->toArray(),
            'details' => $this->details,
        ];
    }
}

abstract class AbstractStreamingSink implements StreamingSink
{
    protected function fail(
        string $stage,
        Throwable $error,
        SinkCursor $cursor,
        bool $partialFailure,
        int $writesAccepted,
        array $details = []
    ): SinkWriteResult {
        $classification = FlowFailureTaxonomy::fromThrowable(
            $error,
            'sink',
            $stage,
            [
                'transport' => $cursor->transport(),
                'identity' => $cursor->identity(),
                'native_category' => $this->classifyCategory($error),
                'native_retryable' => $this->isRetryable($error),
            ]
        );

        return new SinkWriteResult(
            $cursor,
            false,
            false,
            $cursor->bytesAccepted(),
            $writesAccepted,
            new SinkFailure(
                $stage,
                $classification->category(),
                $error::class,
                $error->getMessage(),
                $partialFailure,
                $classification->retryable()
            ),
            $details
        );
    }

    private function classifyCategory(Throwable $error): string
    {
        $class = $error::class;
        $short = ($position = strrpos($class, '\\')) === false ? $class : substr($class, $position + 1);

        return match ($short) {
            'ValidationException', 'InvalidArgumentException', 'MCPDataException' => 'validation',
            'MCPTimeoutException' => 'timeout',
            'ProtocolException', 'MCPProtocolException' => 'protocol',
            'TlsException' => 'tls',
            'NetworkException', 'MCPConnectionException' => 'transport',
            'StreamException', 'StreamBlockedException', 'StreamLimitException', 'StreamStoppedException' => 'stream',
            'SystemException' => 'system',
            default => 'runtime',
        };
    }

    private function isRetryable(Throwable $error): bool
    {
        return match ($this->classifyCategory($error)) {
            'timeout', 'transport', 'stream', 'system', 'runtime' => true,
            default => false,
        };
    }
}

final class ObjectStoreByteSink extends AbstractStreamingSink
{
    /** @var array<string,mixed> */
    private array $options;

    private ?string $mode = null;

    private ?string $uploadId = null;

    private int $chunkSizeBytes = 0;

    private string $pendingBuffer = '';

    private ?LocalReplaySpool $spool = null;

    private int $bytesAccepted = 0;

    private int $writesAccepted = 0;

    private bool $completed = false;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        private string $objectId,
        array $options = [],
        ?SinkCursor $cursor = null
    ) {
        $this->options = $options;

        if ($cursor !== null) {
            $this->restoreFromCursor($cursor);
        }
    }

    public function write(string $chunk): SinkWriteResult
    {
        $this->assertWritable();

        if ($chunk === '') {
            return $this->successResult(false);
        }

        try {
            $this->ensureMode();

            if ($this->mode === 'staged_put') {
                $this->spool()->write($chunk);
                $this->writesAccepted++;
                $this->bytesAccepted += strlen($chunk);

                return $this->successResult(false);
            }

            $this->pendingBuffer .= $chunk;
            $this->writesAccepted++;
            $this->bytesAccepted += strlen($chunk);
            $this->flushNonFinalUploadChunks();

            return $this->successResult(false);
        } catch (Throwable $error) {
            return $this->fail(
                'write',
                $error,
                $this->cursor(),
                $this->bytesAccepted > 0,
                $this->writesAccepted,
                $this->details()
            );
        }
    }

    public function complete(?string $finalChunk = null): SinkWriteResult
    {
        $this->assertWritable();

        try {
            if ($finalChunk !== null && $finalChunk !== '') {
                $writeResult = $this->write($finalChunk);
                if ($writeResult->failure() !== null) {
                    return $writeResult;
                }
            }

            if ($this->bytesAccepted === 0) {
                \king_object_store_put($this->objectId, '', $this->options);
                $this->mode = 'direct_put';
                $this->completed = true;

                return new SinkWriteResult(
                    $this->cursor(),
                    true,
                    true,
                    $this->bytesAccepted,
                    $this->writesAccepted,
                    null,
                    $this->details()
                );
            }

            $this->ensureMode();

            if ($this->mode === 'staged_put') {
                $spool = $this->spool();
                $spool->rewind();
                \king_object_store_put_from_stream($this->objectId, $spool->stream(), $this->options);
                $spool->delete();
                $this->completed = true;

                return new SinkWriteResult(
                    $this->cursor(),
                    true,
                    true,
                    $this->bytesAccepted,
                    $this->writesAccepted,
                    null,
                    $this->details()
                );
            }

            $this->ensureUploadSession();

            if ($this->pendingBuffer !== '') {
                $this->appendUploadChunk($this->pendingBuffer, true);
                $this->pendingBuffer = '';
            }

            \king_object_store_complete_resumable_upload((string) $this->uploadId);
            $this->completed = true;

            return new SinkWriteResult(
                $this->cursor(),
                true,
                true,
                $this->bytesAccepted,
                $this->writesAccepted,
                null,
                $this->details()
            );
        } catch (Throwable $error) {
            return $this->fail(
                'complete',
                $error,
                $this->cursor(),
                $this->bytesAccepted > 0,
                $this->writesAccepted,
                $this->details()
            );
        }
    }

    public function abort(): bool
    {
        if ($this->completed) {
            return true;
        }

        if ($this->uploadId !== null) {
            $aborted = \king_object_store_abort_resumable_upload($this->uploadId);
            $this->uploadId = null;
            $this->pendingBuffer = '';

            return $aborted;
        }

        if ($this->spool !== null) {
            $this->spool->delete();
            $this->spool = null;
        }

        return true;
    }

    public function cursor(): SinkCursor
    {
        return new SinkCursor(
            'object_store',
            $this->objectId,
            $this->bytesAccepted,
            match ($this->mode) {
                'resumable_upload' => 'resume_upload_session',
                'staged_put' => 'replay_local_spool',
                default => 'none',
            },
            $this->state()
        );
    }

    private function restoreFromCursor(SinkCursor $cursor): void
    {
        if ($cursor->transport() !== 'object_store' || $cursor->identity() !== $this->objectId) {
            throw new InvalidArgumentException('sink cursor does not match this object-store sink.');
        }

        $state = $cursor->state();
        $this->mode = (string) ($state['mode'] ?? '');
        $this->writesAccepted = (int) ($state['writes_accepted'] ?? 0);

        if ($this->mode === 'staged_put') {
            $path = (string) ($state['spool_path'] ?? '');
            if ($path === '') {
                throw new InvalidArgumentException('staged object-store cursor is missing spool_path.');
            }

            $this->spool = LocalReplaySpool::resume($path, 'king-flow-object-store-sink-');
            $this->bytesAccepted = $this->spool->bytesWritten();

            if ($this->bytesAccepted !== $cursor->bytesAccepted()) {
                throw new InvalidArgumentException('staged object-store cursor bytes do not match the spool state.');
            }

            return;
        }

        if ($this->mode !== 'resumable_upload') {
            throw new InvalidArgumentException('object-store sink cursor does not carry a supported mode.');
        }

        $uploadId = (string) ($state['upload_id'] ?? '');
        if ($uploadId === '') {
            throw new InvalidArgumentException('resumable object-store cursor is missing upload_id.');
        }

        $status = \king_object_store_get_resumable_upload_status($uploadId);
        if (!is_array($status)) {
            throw new RuntimeException('object-store upload session could not be rehydrated from the provided cursor.');
        }

        $pendingBuffer = $this->decodePendingBuffer((string) ($state['pending_buffer_base64'] ?? ''));
        $this->uploadId = $uploadId;
        $this->chunkSizeBytes = (int) ($status['chunk_size_bytes'] ?? 0);
        $this->pendingBuffer = $pendingBuffer;
        $this->bytesAccepted = (int) ($status['uploaded_bytes'] ?? 0) + strlen($pendingBuffer);

        if ($this->bytesAccepted !== $cursor->bytesAccepted()) {
            throw new InvalidArgumentException('resumable object-store cursor bytes do not match upload session state.');
        }
    }

    private function ensureMode(): void
    {
        if ($this->mode !== null) {
            return;
        }

        $stats = \king_object_store_get_stats();
        $backend = (string) (($stats['object_store']['runtime_primary_backend'] ?? ''));
        $this->mode = str_starts_with($backend, 'cloud_') ? 'resumable_upload' : 'staged_put';

        if ($this->mode === 'staged_put') {
            $this->spool = LocalReplaySpool::create('king-flow-object-store-sink-');
        }
    }

    private function ensureUploadSession(): void
    {
        if ($this->uploadId !== null) {
            return;
        }

        $started = \king_object_store_begin_resumable_upload($this->objectId, $this->options);
        $this->uploadId = (string) ($started['upload_id'] ?? '');
        $this->chunkSizeBytes = (int) ($started['chunk_size_bytes'] ?? 0);

        if ($this->uploadId === '' || $this->chunkSizeBytes <= 0) {
            throw new RuntimeException('object-store sink did not receive a valid resumable upload session.');
        }
    }

    private function flushNonFinalUploadChunks(): void
    {
        $this->ensureUploadSession();

        while (strlen($this->pendingBuffer) > $this->chunkSizeBytes) {
            $slice = substr($this->pendingBuffer, 0, $this->chunkSizeBytes);
            $this->appendUploadChunk($slice, false);
            $this->pendingBuffer = (string) substr($this->pendingBuffer, $this->chunkSizeBytes);
        }
    }

    private function appendUploadChunk(string $payload, bool $final): void
    {
        $stream = fopen('php://temp/maxmemory:' . (string) max(strlen($payload), 1), 'w+');
        if (!is_resource($stream)) {
            throw new RuntimeException('object-store sink could not allocate an upload chunk buffer.');
        }

        try {
            fwrite($stream, $payload);
            rewind($stream);
            \king_object_store_append_resumable_upload_chunk(
                (string) $this->uploadId,
                $stream,
                ['final' => $final]
            );
        } finally {
            fclose($stream);
        }
    }

    private function spool(): LocalReplaySpool
    {
        if ($this->spool === null) {
            $this->spool = LocalReplaySpool::create('king-flow-object-store-sink-');
        }

        return $this->spool;
    }

    /**
     * @return array<string,mixed>
     */
    private function state(): array
    {
        if ($this->mode === 'direct_put') {
            return [
                'mode' => 'direct_put',
                'object_id' => $this->objectId,
                'writes_accepted' => $this->writesAccepted,
                'complete' => $this->completed,
            ];
        }

        if ($this->mode === 'staged_put') {
            return [
                'mode' => 'staged_put',
                'object_id' => $this->objectId,
                'spool_path' => $this->spool?->path(),
                'writes_accepted' => $this->writesAccepted,
                'complete' => $this->completed,
            ];
        }

        return [
            'mode' => 'resumable_upload',
            'object_id' => $this->objectId,
            'upload_id' => $this->uploadId,
            'chunk_size_bytes' => $this->chunkSizeBytes,
            'uploaded_bytes' => $this->bytesAccepted - strlen($this->pendingBuffer),
            'pending_buffer_base64' => $this->pendingBuffer === '' ? '' : base64_encode($this->pendingBuffer),
            'writes_accepted' => $this->writesAccepted,
            'complete' => $this->completed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function details(): array
    {
        $details = $this->state();
        $details['pending_bytes'] = strlen($this->pendingBuffer);

        return $details;
    }

    private function successResult(bool $complete): SinkWriteResult
    {
        return new SinkWriteResult(
            $this->cursor(),
            $complete,
            $complete,
            $this->bytesAccepted,
            $this->writesAccepted,
            null,
            $this->details()
        );
    }

    private function assertWritable(): void
    {
        if ($this->completed) {
            throw new InvalidArgumentException('sink is already complete.');
        }
    }

    private function decodePendingBuffer(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded)) {
            throw new InvalidArgumentException('resumable object-store cursor carries an invalid pending buffer.');
        }

        return $decoded;
    }
}

final class HttpByteSink extends AbstractStreamingSink
{
    /** @var array<string,string|string[]> */
    private array $headers;

    /** @var array<string,mixed> */
    private array $options;

    private ?\King\Stream $stream = null;

    private int $bytesAccepted = 0;

    private int $writesAccepted = 0;

    private bool $completed = false;

    /** @var array<string,string|string[]> */
    private array $responseHeaders = [];

    private ?int $responseStatus = null;

    /**
     * @param array<string,string|string[]> $headers
     * @param array<string,mixed> $options
     */
    public function __construct(
        private \King\Session $session,
        private string $method,
        private string $path,
        array $headers = [],
        array $options = []
    ) {
        $this->headers = $headers;
        $this->options = $options;
    }

    public function write(string $chunk): SinkWriteResult
    {
        $this->assertWritable();

        if ($chunk === '') {
            return $this->successResult(false);
        }

        $this->writesAccepted++;

        try {
            $this->ensureStream();
            $remaining = $chunk;

            while ($remaining !== '') {
                $accepted = $this->stream->send($remaining);
                if ($accepted <= 0) {
                    throw new RuntimeException('http sink write made no progress.');
                }

                $this->bytesAccepted += $accepted;
                $remaining = (string) substr($remaining, $accepted);
            }

            return $this->successResult(false);
        } catch (Throwable $error) {
            return $this->fail(
                'write',
                $error,
                $this->cursor(),
                $this->bytesAccepted > 0,
                $this->writesAccepted,
                $this->details()
            );
        }
    }

    public function complete(?string $finalChunk = null): SinkWriteResult
    {
        $this->assertWritable();

        try {
            $this->ensureStream();
            if ($finalChunk !== null) {
                $this->stream->finish($finalChunk);
                $this->bytesAccepted += strlen($finalChunk);
                if ($finalChunk !== '') {
                    $this->writesAccepted++;
                }
            } else {
                $this->stream->finish();
            }

            $response = $this->stream->receiveResponse(
                isset($this->options['response_timeout_ms']) ? (int) $this->options['response_timeout_ms'] : null
            );
            if (!$response instanceof \King\Response) {
                throw new RuntimeException('http sink did not receive a terminal response.');
            }

            $this->responseStatus = $response->getStatusCode();
            $this->responseHeaders = $response->getHeaders();
            $this->completed = true;

            return new SinkWriteResult(
                $this->cursor(),
                true,
                true,
                $this->bytesAccepted,
                $this->writesAccepted,
                null,
                $this->details()
            );
        } catch (Throwable $error) {
            return $this->fail(
                'complete',
                $error,
                $this->cursor(),
                $this->bytesAccepted > 0,
                $this->writesAccepted,
                $this->details()
            );
        }
    }

    public function abort(): bool
    {
        if ($this->stream !== null && !$this->stream->isClosed()) {
            $this->stream->close();
        }

        return true;
    }

    public function cursor(): SinkCursor
    {
        return new SinkCursor(
            'http',
            strtoupper($this->method) . ' ' . $this->path,
            $this->bytesAccepted,
            'restart_request',
            $this->details()
        );
    }

    private function ensureStream(): void
    {
        if ($this->stream !== null) {
            return;
        }

        $this->stream = $this->session->sendRequest(
            strtoupper($this->method),
            $this->path,
            $this->headers,
            ''
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function details(): array
    {
        return [
            'method' => strtoupper($this->method),
            'path' => $this->path,
            'response_status' => $this->responseStatus,
            'response_headers' => $this->responseHeaders,
            'complete' => $this->completed,
        ];
    }

    private function successResult(bool $complete): SinkWriteResult
    {
        return new SinkWriteResult(
            $this->cursor(),
            $complete,
            $complete,
            $this->bytesAccepted,
            $this->writesAccepted,
            null,
            $this->details()
        );
    }

    private function assertWritable(): void
    {
        if ($this->completed) {
            throw new InvalidArgumentException('sink is already complete.');
        }
    }
}

final class McpByteSink extends AbstractStreamingSink
{
    /** @var array<string,mixed> */
    private array $options;

    private LocalReplaySpool $spool;

    private int $bytesAccepted = 0;

    private int $writesAccepted = 0;

    private bool $completed = false;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        private mixed $connection,
        private string $service,
        private string $method,
        private string $payload,
        ?SinkCursor $cursor = null,
        array $options = []
    ) {
        $this->options = $options;

        if ($cursor !== null) {
            $this->restoreFromCursor($cursor);
        } else {
            $this->spool = LocalReplaySpool::create('king-flow-mcp-sink-');
        }
    }

    public function write(string $chunk): SinkWriteResult
    {
        $this->assertWritable();

        if ($chunk === '') {
            return $this->successResult(false);
        }

        $this->writesAccepted++;

        try {
            $this->spool->write($chunk);
            $this->bytesAccepted += strlen($chunk);

            return $this->successResult(false);
        } catch (Throwable $error) {
            return $this->fail(
                'write',
                $error,
                $this->cursor(),
                $this->bytesAccepted > 0,
                $this->writesAccepted,
                $this->details()
            );
        }
    }

    public function complete(?string $finalChunk = null): SinkWriteResult
    {
        $this->assertWritable();

        try {
            if ($finalChunk !== null && $finalChunk !== '') {
                $writeResult = $this->write($finalChunk);
                if ($writeResult->failure() !== null) {
                    return $writeResult;
                }
            }

            $this->spool->rewind();

            if (is_object($this->connection) && $this->connection instanceof \King\MCP) {
                $this->connection->uploadFromStream(
                    $this->service,
                    $this->method,
                    $this->payload,
                    $this->spool->stream(),
                    $this->options
                );
            } else {
                $ok = \king_mcp_upload_from_stream(
                    $this->connection,
                    $this->service,
                    $this->method,
                    $this->payload,
                    $this->spool->stream(),
                    $this->options
                );
                if ($ok !== true) {
                    throw new RuntimeException('mcp sink upload failed.');
                }
            }

            $this->spool->delete();
            $this->completed = true;

            return new SinkWriteResult(
                $this->cursor(),
                true,
                true,
                $this->bytesAccepted,
                $this->writesAccepted,
                null,
                $this->details()
            );
        } catch (Throwable $error) {
            return $this->fail(
                'complete',
                $error,
                $this->cursor(),
                $this->bytesAccepted > 0,
                $this->writesAccepted,
                $this->details()
            );
        }
    }

    public function abort(): bool
    {
        $this->spool->delete();

        return true;
    }

    public function cursor(): SinkCursor
    {
        return new SinkCursor(
            'mcp_transfer',
            $this->service . ':' . $this->method . ':' . $this->payload,
            $this->bytesAccepted,
            'replay_local_spool',
            $this->details()
        );
    }

    private function restoreFromCursor(SinkCursor $cursor): void
    {
        $identity = $this->service . ':' . $this->method . ':' . $this->payload;
        if ($cursor->transport() !== 'mcp_transfer' || $cursor->identity() !== $identity) {
            throw new InvalidArgumentException('sink cursor does not match this MCP sink.');
        }

        $state = $cursor->state();
        $path = (string) ($state['spool_path'] ?? '');
        if ($path === '') {
            throw new InvalidArgumentException('mcp sink cursor is missing spool_path.');
        }

        $this->spool = LocalReplaySpool::resume($path, 'king-flow-mcp-sink-');
        $this->bytesAccepted = $this->spool->bytesWritten();
        $this->writesAccepted = (int) ($state['writes_accepted'] ?? 0);

        if ($this->bytesAccepted !== $cursor->bytesAccepted()) {
            throw new InvalidArgumentException('mcp sink cursor bytes do not match the local spool state.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function details(): array
    {
        return [
            'service' => $this->service,
            'method' => $this->method,
            'payload' => $this->payload,
            'spool_path' => $this->spool->path(),
            'writes_accepted' => $this->writesAccepted,
            'complete' => $this->completed,
        ];
    }

    private function successResult(bool $complete): SinkWriteResult
    {
        return new SinkWriteResult(
            $this->cursor(),
            $complete,
            $complete,
            $this->bytesAccepted,
            $this->writesAccepted,
            null,
            $this->details()
        );
    }

    private function assertWritable(): void
    {
        if ($this->completed) {
            throw new InvalidArgumentException('sink is already complete.');
        }
    }
}

final class LocalReplaySpool
{
    private const SPOOL_DIRECTORY = 'king-flow-replay-spool';

    private mixed $stream;

    private int $bytesWritten;

    private function __construct(private string $path, mixed $stream)
    {
        $this->stream = $stream;
        $this->bytesWritten = (int) filesize($this->path);
        fseek($this->stream, 0, SEEK_END);
    }

    public static function create(string $prefix): self
    {
        $path = tempnam(self::spoolRootPath(), $prefix);
        if ($path === false) {
            throw new RuntimeException('failed to allocate a local replay spool.');
        }

        @chmod($path, 0600);
        $stream = fopen($path, 'c+b');
        if (!is_resource($stream)) {
            @unlink($path);
            throw new RuntimeException('failed to open a local replay spool.');
        }

        return new self($path, $stream);
    }

    public static function resume(string $path, string $expectedPrefix): self
    {
        self::assertResumablePath($path, $expectedPrefix);

        $stream = fopen($path, 'r+b');
        if (!is_resource($stream)) {
            throw new RuntimeException('local replay spool could not be reopened.');
        }

        return new self($path, $stream);
    }

    private static function spoolRootPath(): string
    {
        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . self::SPOOL_DIRECTORY;

        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('failed to initialize local replay spool root.');
        }

        @chmod($root, 0700);
        $resolved = realpath($root);
        if ($resolved === false || $resolved === '') {
            throw new RuntimeException('failed to resolve local replay spool root.');
        }

        return $resolved;
    }

    private static function assertResumablePath(string $path, string $expectedPrefix): void
    {
        if ($path === '') {
            throw new RuntimeException('local replay spool could not be resumed because spool_path is missing.');
        }

        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('local replay spool could not be resumed because spool_path is invalid.');
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false || $resolvedPath === '') {
            throw new RuntimeException('local replay spool could not be resumed because spool_path is invalid.');
        }

        $spoolRoot = self::spoolRootPath();
        $spoolRootPrefix = $spoolRoot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolvedPath, $spoolRootPrefix)) {
            throw new RuntimeException('local replay spool could not be resumed because spool_path is outside the local replay spool root.');
        }

        if ($expectedPrefix === '' || !str_starts_with(basename($resolvedPath), $expectedPrefix)) {
            throw new RuntimeException('local replay spool could not be resumed because spool_path does not match the expected spool prefix.');
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function stream(): mixed
    {
        return $this->stream;
    }

    public function write(string $data): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = fwrite($this->stream, $remaining);
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('local replay spool write made no progress.');
            }

            $this->bytesWritten += $written;
            $remaining = (string) substr($remaining, $written);
        }
    }

    public function rewind(): void
    {
        fflush($this->stream);
        rewind($this->stream);
    }

    public function delete(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        @unlink($this->path);
    }
}
