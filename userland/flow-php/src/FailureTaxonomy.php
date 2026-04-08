<?php
declare(strict_types=1);

namespace King\Flow;

use Throwable;

final class FlowFailure
{
    /** @var array<string,mixed> */
    private array $details;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        private string $surface,
        private string $stage,
        private string $category,
        private string $reason,
        private string $retryDisposition,
        private bool $retryable,
        private string $summary,
        private ?string $exceptionClass = null,
        private ?string $message = null,
        private ?string $transport = null,
        private ?string $backend = null,
        array $details = []
    ) {
        $this->details = $details;
    }

    public function surface(): string
    {
        return $this->surface;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function retryDisposition(): string
    {
        return $this->retryDisposition;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function exceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function transport(): ?string
    {
        return $this->transport;
    }

    public function backend(): ?string
    {
        return $this->backend;
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
            'surface' => $this->surface,
            'stage' => $this->stage,
            'category' => $this->category,
            'reason' => $this->reason,
            'retry_disposition' => $this->retryDisposition,
            'retryable' => $this->retryable,
            'summary' => $this->summary,
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'transport' => $this->transport,
            'backend' => $this->backend,
            'details' => $this->details,
        ];
    }
}

final class FlowFailureTaxonomy
{
    /**
     * @param array<string,mixed> $context
     */
    public static function fromThrowable(
        Throwable $error,
        string $surface,
        string $stage,
        array $context = []
    ): FlowFailure {
        return self::classify(
            $surface,
            $stage,
            $error::class,
            $error->getMessage(),
            is_string($context['native_category'] ?? null) ? $context['native_category'] : null,
            $context
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function fromSinkResult(
        SinkWriteResult $result,
        string $stage = 'write',
        array $context = []
    ): ?FlowFailure {
        $failure = $result->failure();
        if (!$failure instanceof SinkFailure) {
            return null;
        }

        $context['transport'] ??= $result->cursor()->transport();
        $context['identity'] ??= $result->cursor()->identity();
        $context['native_retryable'] = $failure->retryable();
        $context['partial_failure'] = $failure->partialFailure();
        $context['bytes_accepted'] = $result->bytesAccepted();
        $context['writes_accepted'] = $result->writesAccepted();
        $context['transport_committed'] = $result->transportCommitted();
        $context['result_details'] = $result->details();

        return self::classify(
            'sink',
            $stage,
            $failure->exceptionClass(),
            $failure->message(),
            $failure->category(),
            $context
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function fromCheckpointCommitResult(
        CheckpointCommitResult $result,
        string $stage = 'replace',
        array $context = []
    ): ?FlowFailure {
        if ($result->committed()) {
            return null;
        }

        if ($result->conflict()) {
            $record = $result->record();
            $details = [];
            if ($record instanceof CheckpointRecord) {
                $details['checkpoint_id'] = $record->checkpointId();
                $details['current_version'] = $record->version();
                $details['current_etag'] = $record->etag();
            }

            return new FlowFailure(
                'checkpoint',
                $stage,
                'resume_conflict',
                'stale_writer',
                'reload_checkpoint_and_resume',
                true,
                'Checkpoint state changed before this writer could commit.',
                null,
                $result->message(),
                null,
                $context['backend'] ?? 'object_store',
                $details
            );
        }

        return new FlowFailure(
            'checkpoint',
            $stage,
            'runtime',
            'checkpoint_write_failed',
            'caller_managed_retry',
            true,
            'Checkpoint commit failed without a version-conflict result.',
            null,
            $result->message(),
            null,
            $context['backend'] ?? 'object_store',
            []
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function fromExecutionSnapshot(
        ExecutionRunSnapshot $snapshot,
        string $stage = 'run',
        array $context = []
    ): ?FlowFailure {
        $raw = $snapshot->toArray();
        $classification = $snapshot->errorClassification();
        $message = $snapshot->error();

        if ($classification === null && $message === null) {
            return null;
        }

        $context['backend'] ??= $snapshot->executionBackend();
        $context['run_id'] ??= $snapshot->runId();
        $context['status'] ??= $snapshot->status();
        $context['topology_scope'] ??= $snapshot->topologyScope();
        $context['completed_step_count'] ??= $snapshot->completedStepCount();
        $context['step_count'] ??= $snapshot->stepCount();

        if ($classification !== null) {
            $context['scope'] = $classification['scope'] ?? null;
            $context['step_index'] = $classification['step_index'] ?? null;
            $context['step_tool'] = $classification['step_tool'] ?? null;
            $context['native_retry_disposition'] = $classification['retry_disposition'] ?? null;
            $context['native_backend'] = $classification['backend'] ?? null;
        }

        if (($snapshot->status() === 'cancelled') && $classification === null) {
            return new FlowFailure(
                'execution',
                $stage,
                'cancelled',
                'control_plane_cancel',
                'do_not_retry',
                false,
                'Execution stopped because cancellation was requested.',
                null,
                $message,
                null,
                $snapshot->executionBackend(),
                [
                    'run_id' => $snapshot->runId(),
                    'scope' => 'run',
                ]
            );
        }

        return self::classify(
            'execution',
            $stage,
            null,
            $message ?? '',
            is_string($classification['category'] ?? null) ? $classification['category'] : null,
            $context
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function classify(
        string $surface,
        string $stage,
        ?string $exceptionClass,
        string $message,
        ?string $nativeCategory,
        array $context
    ): FlowFailure {
        $normalizedMessage = strtolower($message);
        $transport = is_string($context['transport'] ?? null) ? $context['transport'] : null;
        $backend = is_string($context['backend'] ?? null)
            ? $context['backend']
            : (is_string($context['native_backend'] ?? null) ? $context['native_backend'] : null);

        if (self::isQuotaMessage($normalizedMessage)) {
            return self::build(
                $surface,
                $stage,
                'quota',
                'quota_pressure',
                'retry_after_quota_relief',
                true,
                'The runtime reported exhausted quota or capacity pressure.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isThrottleMessage($normalizedMessage)) {
            return self::build(
                $surface,
                $stage,
                'transport',
                'throttled',
                'retry_with_backoff',
                true,
                'The runtime transport was throttled and should be retried with backoff.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isMissingDataMessage($normalizedMessage)) {
            return self::build(
                $surface,
                $stage,
                'missing_data',
                'required_data_missing',
                'wait_for_data',
                true,
                'Required data was not present on the runtime surface.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isValidation($exceptionClass, $nativeCategory, $normalizedMessage)) {
            return self::build(
                $surface,
                $stage,
                'validation',
                'input_contract',
                'non_retryable',
                false,
                'Input or contract validation failed before the runtime could proceed.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isTimeout($exceptionClass, $nativeCategory)) {
            return self::build(
                $surface,
                $stage,
                'timeout',
                'deadline_exhausted',
                'retry_with_backoff',
                true,
                'The runtime operation timed out before completion.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isMissingHandler($nativeCategory, $normalizedMessage)) {
            return self::build(
                $surface,
                $stage,
                'missing_handler',
                'execution_binding_missing',
                'rebind_handler_and_resume',
                true,
                'The execution process is missing a required userland handler binding.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isCancelled($nativeCategory, $normalizedMessage)) {
            return self::build(
                $surface,
                $stage,
                'cancelled',
                'control_plane_cancel',
                'do_not_retry',
                false,
                'The operation was cancelled by an explicit control path.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isTransport($exceptionClass, $nativeCategory)) {
            $reason = self::transportReason($exceptionClass, $nativeCategory);
            $retryDisposition = in_array($reason, ['protocol', 'tls'], true)
                ? 'non_retryable'
                : 'retry_with_backoff';

            return self::build(
                $surface,
                $stage,
                'transport',
                $reason,
                $retryDisposition,
                $retryDisposition !== 'non_retryable',
                'The runtime transport failed before the operation could complete.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if (self::isBackend($nativeCategory, $normalizedMessage)) {
            return self::build(
                $surface,
                $stage,
                'backend',
                'backend_unavailable',
                'retry_after_backend_recovery',
                true,
                'The configured backend could not accept or complete the operation.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        if ($nativeCategory === 'resume_conflict') {
            return self::build(
                $surface,
                $stage,
                'resume_conflict',
                'stale_writer',
                'reload_checkpoint_and_resume',
                true,
                'Persisted resume state changed before this retry could commit.',
                $exceptionClass,
                $message,
                $transport,
                $backend,
                $context
            );
        }

        return self::build(
            $surface,
            $stage,
            'runtime',
            'runtime_fault',
            'caller_managed_retry',
            true,
            'The runtime reached a non-validation execution fault.',
            $exceptionClass,
            $message,
            $transport,
            $backend,
            $context
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function build(
        string $surface,
        string $stage,
        string $category,
        string $reason,
        string $retryDisposition,
        bool $retryable,
        string $summary,
        ?string $exceptionClass,
        string $message,
        ?string $transport,
        ?string $backend,
        array $context
    ): FlowFailure {
        unset(
            $context['transport'],
            $context['backend'],
            $context['native_backend'],
            $context['native_category'],
            $context['native_retryable'],
            $context['native_retry_disposition']
        );

        return new FlowFailure(
            $surface,
            $stage,
            $category,
            $reason,
            $retryDisposition,
            $retryable,
            $summary,
            $exceptionClass,
            $message,
            $transport,
            $backend,
            $context
        );
    }

    private static function isValidation(?string $exceptionClass, ?string $nativeCategory, string $message): bool
    {
        if ($nativeCategory === 'validation') {
            return true;
        }

        $short = self::shortClass($exceptionClass);
        if (in_array($short, ['ValidationException', 'InvalidArgumentException', 'MCPDataException'], true)) {
            return true;
        }

        return str_contains($message, 'resume cursor')
            || str_contains($message, 'must not be empty')
            || str_contains($message, 'unsupported schema version')
            || str_contains($message, 'identity does not match');
    }

    private static function isTimeout(?string $exceptionClass, ?string $nativeCategory): bool
    {
        if ($nativeCategory === 'timeout') {
            return true;
        }

        return in_array(
            self::shortClass($exceptionClass),
            ['TimeoutException', 'MCPTimeoutException', 'WebSocketTimeoutException'],
            true
        );
    }

    private static function isTransport(?string $exceptionClass, ?string $nativeCategory): bool
    {
        if (in_array($nativeCategory, ['transport', 'protocol'], true)) {
            return true;
        }

        return in_array(
            self::shortClass($exceptionClass),
            [
                'NetworkException',
                'MCPConnectionException',
                'WebSocketConnectionException',
                'ProtocolException',
                'MCPProtocolException',
                'WebSocketProtocolException',
                'TlsException',
                'StreamException',
                'StreamBlockedException',
                'StreamLimitException',
                'StreamStoppedException',
            ],
            true
        );
    }

    private static function transportReason(?string $exceptionClass, ?string $nativeCategory): string
    {
        if ($nativeCategory === 'protocol') {
            return 'protocol';
        }

        return match (self::shortClass($exceptionClass)) {
            'ProtocolException', 'MCPProtocolException', 'WebSocketProtocolException' => 'protocol',
            'TlsException' => 'tls',
            'StreamException', 'StreamBlockedException', 'StreamLimitException', 'StreamStoppedException' => 'stream',
            default => 'network',
        };
    }

    private static function isMissingHandler(?string $nativeCategory, string $message): bool
    {
        return $nativeCategory === 'missing_handler'
            || str_contains($message, 'has no registered handler for tool')
            || str_contains($message, 'missing a required handler');
    }

    private static function isCancelled(?string $nativeCategory, string $message): bool
    {
        return $nativeCategory === 'cancelled'
            || str_contains($message, 'cancelled the active orchestrator run')
            || str_contains($message, 'cancelled the queued run before worker claim');
    }

    private static function isBackend(?string $nativeCategory, string $message): bool
    {
        if ($nativeCategory === 'backend') {
            return true;
        }

        return str_contains($message, 'failed to enqueue the run for the file-worker backend')
            || str_contains($message, 'received an invalid serialized result from the remote peer')
            || str_contains($message, 'remote peer received an invalid handler boundary topology')
            || str_contains($message, 'could not connect to the configured endpoint')
            || str_contains($message, 'configured backend')
            || str_contains($message, 'backend rejected the operation');
    }

    private static function isQuotaMessage(string $message): bool
    {
        return str_contains($message, 'reported exhausted quota')
            || str_contains($message, 'quota exhausted')
            || str_contains($message, 'quota has been exceeded')
            || str_contains($message, 'not enough storage quota')
            || str_contains($message, 'would exceed the configured object-store runtime capacity');
    }

    private static function isThrottleMessage(string $message): bool
    {
        return str_contains($message, 'throttled the operation; retry with backoff')
            || str_contains($message, 'reduce your request rate')
            || str_contains($message, 'rate exceeded')
            || str_contains($message, 'server busy')
            || str_contains($message, 'delete throttled');
    }

    private static function isMissingDataMessage(string $message): bool
    {
        return str_contains($message, 'could not resolve metadata')
            || str_contains($message, 'payload disappeared')
            || str_contains($message, 'file is missing')
            || str_contains($message, 'missing upload state file')
            || str_contains($message, 'missing upload state key')
            || str_contains($message, 'not found')
            || str_contains($message, 'does not exist');
    }

    private static function shortClass(?string $exceptionClass): string
    {
        if (!is_string($exceptionClass) || $exceptionClass === '') {
            return '';
        }

        $position = strrpos($exceptionClass, '\\');

        return $position === false ? $exceptionClass : substr($exceptionClass, $position + 1);
    }
}
