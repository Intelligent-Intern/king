<?php
declare(strict_types=1);

namespace {
    final class Training
    {
        public static function distributed(string $runId): \Training\DistributedTrainingBuilder
        {
            return new \Training\DistributedTrainingBuilder($runId);
        }
    }

    final class Optimizer
    {
        public static function adamw(): \Training\AdamWOptimizer
        {
            return new \Training\AdamWOptimizer();
        }
    }

    final class Loss
    {
        public static function crossEntropy(): \Training\CrossEntropyLoss
        {
            return new \Training\CrossEntropyLoss();
        }
    }

    final class Placement
    {
        public static function distributed(): \Training\DistributedPlacement
        {
            return new \Training\DistributedPlacement();
        }
    }

    final class FailurePolicy
    {
        public static function training(): \Training\TrainingFailurePolicy
        {
            return new \Training\TrainingFailurePolicy();
        }
    }
}

namespace Training {
    use InvalidArgumentException;
    use JsonException;

    interface PlanSection
    {
        /**
         * @return array<string,mixed>
         */
        public function toArray(): array;
    }

    final class Data
    {
        public static function objectStore(string $uri): ObjectStoreData
        {
            return new ObjectStoreData($uri);
        }
    }

    final class Parallelism
    {
        public static function hybrid(): HybridParallelism
        {
            return new HybridParallelism();
        }
    }

    final class Schedule
    {
        public static function steps(int $steps): StepSchedule
        {
            return new StepSchedule($steps);
        }
    }

    final class Checkpointing
    {
        public static function objectStore(): ObjectStoreCheckpointing
        {
            return new ObjectStoreCheckpointing();
        }
    }

    final class ObjectStoreData implements PlanSection
    {
        private ?string $format = null;
        private ?int $shuffleSeed = null;
        private ?int $prefetchWindow = null;

        public function __construct(private string $uri)
        {
            Validation::kingUri($uri, 'dataset object-store URI');
        }

        public function format(string $format): self
        {
            $format = trim($format);
            if ($format === '') {
                throw new InvalidArgumentException('dataset format must not be empty.');
            }

            $this->format = $format;
            return $this;
        }

        public function shuffle(int $seed): self
        {
            $this->shuffleSeed = $seed;
            return $this;
        }

        public function prefetch(int $window): self
        {
            Validation::positiveInt($window, 'prefetch window');
            $this->prefetchWindow = $window;
            return $this;
        }

        public function toArray(): array
        {
            if ($this->format === null) {
                throw new InvalidArgumentException('dataset format is required.');
            }

            return [
                'kind' => 'object_store',
                'uri' => $this->uri,
                'object_id' => ObjectStoreUriMapper::objectId($this->uri, 'dataset'),
                'format' => $this->format,
                'storage' => [
                    'runtime' => 'king_object_store',
                    'read_api' => 'king_object_store_get_to_stream',
                    'metadata_api' => 'king_object_store_get_metadata',
                ],
                'codec' => [
                    'runtime' => 'iibin',
                    'schema' => $this->format,
                    'decode_api' => 'king_proto_decode_batch',
                    'batch_limit' => WireContracts::IIBIN_BATCH_LIMIT,
                ],
                'shuffle' => [
                    'enabled' => $this->shuffleSeed !== null,
                    'seed' => $this->shuffleSeed,
                ],
                'prefetch' => [
                    'window' => $this->prefetchWindow ?? 0,
                ],
            ];
        }
    }

    final class AdamWOptimizer implements PlanSection
    {
        private ?float $lr = null;
        private ?float $beta1 = null;
        private ?float $beta2 = null;
        private ?float $weightDecay = null;
        private ?float $gradientClipping = null;

        public function lr(float $lr): self
        {
            Validation::positiveFloat($lr, 'AdamW learning rate');
            $this->lr = $lr;
            return $this;
        }

        public function betas(float $beta1, float $beta2): self
        {
            Validation::beta($beta1, 'AdamW beta1');
            Validation::beta($beta2, 'AdamW beta2');
            $this->beta1 = $beta1;
            $this->beta2 = $beta2;
            return $this;
        }

        public function weightDecay(float $weightDecay): self
        {
            if ($weightDecay < 0.0) {
                throw new InvalidArgumentException('AdamW weight decay must be nonnegative.');
            }

            $this->weightDecay = $weightDecay;
            return $this;
        }

        public function gradientClipping(float $gradientClipping): self
        {
            Validation::positiveFloat($gradientClipping, 'AdamW gradient clipping');
            $this->gradientClipping = $gradientClipping;
            return $this;
        }

        public function toArray(): array
        {
            return [
                'type' => 'adamw',
                'lr' => Validation::required($this->lr, 'AdamW learning rate'),
                'betas' => [
                    Validation::required($this->beta1, 'AdamW beta1'),
                    Validation::required($this->beta2, 'AdamW beta2'),
                ],
                'weight_decay' => Validation::required($this->weightDecay, 'AdamW weight decay'),
                'gradient_clipping' => Validation::required($this->gradientClipping, 'AdamW gradient clipping'),
            ];
        }
    }

    final class CrossEntropyLoss implements PlanSection
    {
        private ?int $ignoreIndex = null;

        public function ignoreIndex(int $ignoreIndex): self
        {
            $this->ignoreIndex = $ignoreIndex;
            return $this;
        }

        public function toArray(): array
        {
            return [
                'type' => 'cross_entropy',
                'ignore_index' => $this->ignoreIndex,
            ];
        }
    }

    final class HybridParallelism implements PlanSection
    {
        private ?int $dataParallel = null;
        private ?int $tensorParallel = null;
        private ?int $expertParallel = null;
        private ?int $pipelineParallel = null;
        private ?string $collectives = null;
        private ?string $rendezvous = null;

        public function dataParallel(int $ranks): self
        {
            Validation::positiveInt($ranks, 'data parallel ranks');
            $this->dataParallel = $ranks;
            return $this;
        }

        public function tensorParallel(int $ranks): self
        {
            Validation::positiveInt($ranks, 'tensor parallel ranks');
            $this->tensorParallel = $ranks;
            return $this;
        }

        public function expertParallel(int $ranks): self
        {
            Validation::positiveInt($ranks, 'expert parallel ranks');
            $this->expertParallel = $ranks;
            return $this;
        }

        public function pipelineParallel(int $stages): self
        {
            Validation::positiveInt($stages, 'pipeline parallel stages');
            $this->pipelineParallel = $stages;
            return $this;
        }

        public function collectives(string $backend): self
        {
            $backend = trim($backend);
            if ($backend === '') {
                throw new InvalidArgumentException('collectives backend must not be empty.');
            }

            $this->collectives = $backend;
            return $this;
        }

        public function rendezvous(string $uri): self
        {
            Validation::kingUri($uri, 'rendezvous URI');
            $this->rendezvous = $uri;
            return $this;
        }

        public function toArray(): array
        {
            return [
                'type' => 'hybrid',
                'data_parallel' => Validation::required($this->dataParallel, 'data parallel ranks'),
                'tensor_parallel' => Validation::required($this->tensorParallel, 'tensor parallel ranks'),
                'expert_parallel' => Validation::required($this->expertParallel, 'expert parallel ranks'),
                'pipeline_parallel' => Validation::required($this->pipelineParallel, 'pipeline parallel stages'),
                'collectives' => Validation::required($this->collectives, 'collectives backend'),
                'rendezvous' => Validation::required($this->rendezvous, 'rendezvous URI'),
                'coordination' => [
                    'transport' => 'websocket',
                    'frame_codec' => 'iibin',
                    'connect_api' => 'king_client_websocket_connect',
                    'send_api' => 'king_client_websocket_send',
                    'receive_api' => 'king_client_websocket_receive',
                    'ping_api' => 'king_client_websocket_ping',
                    'event_batch_limit' => WireContracts::IIBIN_BATCH_LIMIT,
                ],
            ];
        }
    }

    final class StepSchedule implements PlanSection
    {
        private ?int $microBatchSize = null;
        private ?int $globalBatchTokens = null;
        private ?int $warmupSteps = null;
        private string $decay = 'constant';

        public function __construct(private int $steps)
        {
            Validation::positiveInt($steps, 'training steps');
        }

        public function microBatchSize(int $size): self
        {
            Validation::positiveInt($size, 'micro-batch size');
            $this->microBatchSize = $size;
            return $this;
        }

        public function globalBatchTokens(int $tokens): self
        {
            Validation::positiveInt($tokens, 'global batch tokens');
            $this->globalBatchTokens = $tokens;
            return $this;
        }

        public function warmupSteps(int $steps): self
        {
            if ($steps < 0) {
                throw new InvalidArgumentException('warmup steps must be nonnegative.');
            }

            $this->warmupSteps = $steps;
            return $this;
        }

        public function cosineDecay(): self
        {
            $this->decay = 'cosine';
            return $this;
        }

        public function toArray(): array
        {
            return [
                'type' => 'steps',
                'steps' => $this->steps,
                'micro_batch_size' => Validation::required($this->microBatchSize, 'micro-batch size'),
                'global_batch_tokens' => Validation::required($this->globalBatchTokens, 'global batch tokens'),
                'warmup_steps' => Validation::required($this->warmupSteps, 'warmup steps'),
                'decay' => $this->decay,
            ];
        }
    }

    final class ObjectStoreCheckpointing implements PlanSection
    {
        private ?string $target = null;
        private ?int $everySteps = null;
        private bool $async = false;
        private bool $resumeAutomatically = false;

        public function target(string $uri): self
        {
            Validation::kingUri($uri, 'checkpoint target URI');
            $this->target = $uri;
            return $this;
        }

        public function everySteps(int $steps): self
        {
            Validation::positiveInt($steps, 'checkpoint interval');
            $this->everySteps = $steps;
            return $this;
        }

        public function async(): self
        {
            $this->async = true;
            return $this;
        }

        public function resumeAutomatically(): self
        {
            $this->resumeAutomatically = true;
            return $this;
        }

        public function toArray(): array
        {
            return [
                'kind' => 'object_store',
                'target' => Validation::required($this->target, 'checkpoint target URI'),
                'target_object_prefix' => ObjectStoreUriMapper::objectId((string) $this->target, 'checkpoint'),
                'every_steps' => Validation::required($this->everySteps, 'checkpoint interval'),
                'async' => $this->async,
                'resume_automatically' => $this->resumeAutomatically,
                'storage' => [
                    'runtime' => 'king_object_store',
                    'write_api' => 'king_object_store_begin_resumable_upload',
                    'append_api' => 'king_object_store_append_resumable_upload_chunk',
                    'complete_api' => 'king_object_store_complete_resumable_upload',
                    'metadata_api' => 'king_object_store_get_metadata',
                ],
            ];
        }
    }

    final class DistributedPlacement implements PlanSection
    {
        private ?string $nodePool = null;
        private ?string $gpuMemory = null;
        private bool $spreadAcrossRacks = false;
        private bool $coLocateHotExperts = false;

        public function nodePool(string $nodePool): self
        {
            $nodePool = trim($nodePool);
            if ($nodePool === '') {
                throw new InvalidArgumentException('node pool must not be empty.');
            }

            $this->nodePool = $nodePool;
            return $this;
        }

        public function requireGpuMemory(string $requirement): self
        {
            $requirement = trim($requirement);
            if ($requirement === '') {
                throw new InvalidArgumentException('GPU memory requirement must not be empty.');
            }

            $this->gpuMemory = $requirement;
            return $this;
        }

        public function spreadAcrossRacks(): self
        {
            $this->spreadAcrossRacks = true;
            return $this;
        }

        public function coLocateHotExperts(): self
        {
            $this->coLocateHotExperts = true;
            return $this;
        }

        public function toArray(): array
        {
            return [
                'type' => 'distributed',
                'node_pool' => Validation::required($this->nodePool, 'node pool'),
                'gpu_memory' => Validation::required($this->gpuMemory, 'GPU memory requirement'),
                'spread_across_racks' => $this->spreadAcrossRacks,
                'co_locate_hot_experts' => $this->coLocateHotExperts,
            ];
        }
    }

    final class TrainingFailurePolicy implements PlanSection
    {
        /** @var array{min:int,max:int}|null */
        private ?array $elasticRanks = null;
        private bool $reconstructMissingExpertFromCheckpoint = false;
        private bool $quarantineBadWorkers = false;
        private bool $resumeFromLastConsistentCheckpoint = false;

        public function elasticRanks(int $min, int $max): self
        {
            Validation::positiveInt($min, 'minimum elastic ranks');
            Validation::positiveInt($max, 'maximum elastic ranks');
            if ($min > $max) {
                throw new InvalidArgumentException('minimum elastic ranks must be less than or equal to maximum elastic ranks.');
            }

            $this->elasticRanks = ['min' => $min, 'max' => $max];
            return $this;
        }

        public function reconstructMissingExpertFromCheckpoint(): self
        {
            $this->reconstructMissingExpertFromCheckpoint = true;
            return $this;
        }

        public function quarantineBadWorkers(): self
        {
            $this->quarantineBadWorkers = true;
            return $this;
        }

        public function resumeFromLastConsistentCheckpoint(): self
        {
            $this->resumeFromLastConsistentCheckpoint = true;
            return $this;
        }

        public function toArray(): array
        {
            return [
                'type' => 'training',
                'elastic_ranks' => Validation::required($this->elasticRanks, 'elastic rank bounds'),
                'reconstruct_missing_expert_from_checkpoint' => $this->reconstructMissingExpertFromCheckpoint,
                'quarantine_bad_workers' => $this->quarantineBadWorkers,
                'resume_from_last_consistent_checkpoint' => $this->resumeFromLastConsistentCheckpoint,
            ];
        }
    }

    final class DistributedTrainingBuilder
    {
        private mixed $model = null;
        private ?PlanSection $data = null;
        private ?PlanSection $optimizer = null;
        private ?PlanSection $loss = null;
        private ?PlanSection $parallelism = null;
        private ?PlanSection $schedule = null;
        private ?PlanSection $checkpointing = null;
        private ?PlanSection $placement = null;
        private ?PlanSection $failurePolicy = null;

        public function __construct(private string $runId)
        {
            $this->runId = trim($runId);
            if ($this->runId === '') {
                throw new InvalidArgumentException('training run ID must not be empty.');
            }
        }

        public function model(mixed $model): self
        {
            $this->model = $model;
            return $this;
        }

        public function data(PlanSection $data): self
        {
            $this->data = $data;
            return $this;
        }

        public function optimizer(PlanSection $optimizer): self
        {
            $this->optimizer = $optimizer;
            return $this;
        }

        public function loss(PlanSection $loss): self
        {
            $this->loss = $loss;
            return $this;
        }

        public function parallelism(PlanSection $parallelism): self
        {
            $this->parallelism = $parallelism;
            return $this;
        }

        public function schedule(PlanSection $schedule): self
        {
            $this->schedule = $schedule;
            return $this;
        }

        public function checkpointing(PlanSection $checkpointing): self
        {
            $this->checkpointing = $checkpointing;
            return $this;
        }

        public function placement(PlanSection $placement): self
        {
            $this->placement = $placement;
            return $this;
        }

        public function failurePolicy(PlanSection $failurePolicy): self
        {
            $this->failurePolicy = $failurePolicy;
            return $this;
        }

        public function start(): TrainingRun
        {
            $parallelism = Validation::required($this->parallelism, 'parallelism')->toArray();
            $runObjectUri = 'king://runs/' . $this->runId . '/plan.iibin';
            $rendezvousUri = (string) ($parallelism['rendezvous'] ?? ('king://runs/' . $this->runId . '/rdzv'));

            $plan = [
                'contract' => 'king.training.distributed.v1',
                'run_id' => $this->runId,
                'model' => $this->normalizeModel(Validation::required($this->model, 'model')),
                'data' => Validation::required($this->data, 'data')->toArray(),
                'optimizer' => Validation::required($this->optimizer, 'optimizer')->toArray(),
                'loss' => Validation::required($this->loss, 'loss')->toArray(),
                'parallelism' => $parallelism,
                'schedule' => Validation::required($this->schedule, 'schedule')->toArray(),
                'checkpointing' => Validation::required($this->checkpointing, 'checkpointing')->toArray(),
                'placement' => Validation::required($this->placement, 'placement')->toArray(),
                'failure_policy' => Validation::required($this->failurePolicy, 'failure policy')->toArray(),
                'execution' => [
                    'mode' => 'control_plane_plan',
                    'state' => 'planned',
                    'backend' => null,
                    'run_object' => $runObjectUri,
                    'run_object_id' => ObjectStoreUriMapper::objectId($runObjectUri, 'run'),
                    'rendezvous_object_prefix' => ObjectStoreUriMapper::objectId($rendezvousUri, 'rendezvous'),
                    'orchestrator' => [
                        'runtime' => 'king_pipeline_orchestrator',
                        'tool' => 'king.training.distributed.agent_worker',
                        'submit_api' => 'king_pipeline_orchestrator_dispatch',
                        'status_api' => 'king_pipeline_orchestrator_get_run',
                        'resume_api' => 'king_pipeline_orchestrator_resume_run',
                        'worker_api' => 'king_pipeline_orchestrator_worker_run_next',
                        'handler_boundary' => 'process_local_rebind_required',
                    ],
                    'watch' => [
                        'transport' => 'websocket',
                        'frame_codec' => 'iibin',
                        'schema' => WireContracts::RUN_EVENT_SCHEMA,
                        'batch_limit' => WireContracts::IIBIN_BATCH_LIMIT,
                    ],
                    'envelope' => [
                        'frame_codec' => 'iibin',
                        'schema' => WireContracts::RUN_ENVELOPE_SCHEMA,
                        'persist_api' => 'king_object_store_put',
                    ],
                    'requires' => [
                        'object_store',
                        'iibin',
                        'websocket',
                        'pipeline_orchestrator',
                        'rendezvous',
                        'gpu_scheduler',
                    ],
                ],
            ];

            return new TrainingRun($plan);
        }

        /**
         * @return array<string,mixed>
         */
        private function normalizeModel(mixed $model): array
        {
            if (is_array($model)) {
                return $model;
            }

            if (is_object($model) && method_exists($model, 'toArray')) {
                $normalized = $model->toArray();
                if (!is_array($normalized)) {
                    throw new InvalidArgumentException('model toArray() must return an array.');
                }

                return $normalized;
            }

            if (is_object($model)) {
                return [
                    'type' => 'php_object',
                    'class' => $model::class,
                ];
            }

            if (is_string($model) && trim($model) !== '') {
                return [
                    'type' => 'model_ref',
                    'ref' => $model,
                ];
            }

            throw new InvalidArgumentException('model must be an array, object, or nonempty model reference string.');
        }
    }

    final class TrainingRun
    {
        /**
         * @param array<string,mixed> $plan
         */
        public function __construct(private array $plan)
        {
        }

        public function id(): string
        {
            return (string) $this->plan['run_id'];
        }

        /**
         * @return array<string,mixed>
         */
        public function plan(): array
        {
            return $this->plan;
        }

        /**
         * @return array<string,mixed>
         */
        public function status(): array
        {
            return [
                'run_id' => $this->id(),
                'contract' => $this->plan['contract'],
                'state' => $this->plan['execution']['state'],
                'mode' => $this->plan['execution']['mode'],
                'backend' => $this->plan['execution']['backend'],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        public function watch(?callable $observer = null): array
        {
            $event = [
                'type' => 'training.run.planned',
                'run_id' => $this->id(),
                'state' => $this->plan['execution']['state'],
                'message' => 'Training run is validated as a King control-plane plan; no scheduler backend is attached.',
            ];

            if ($observer !== null) {
                $observer($event);
            }

            return [
                'status' => $this->status(),
                'events' => [$event],
                'transport' => [
                    'kind' => 'websocket',
                    'frame_codec' => 'iibin',
                    'schema' => WireContracts::RUN_EVENT_SCHEMA,
                    'send_api' => 'king_websocket_send',
                    'send_available' => \function_exists('king_websocket_send'),
                ],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        public function iibinEnvelope(): array
        {
            $payload = [
                'schema_version' => 1,
                'run_id' => $this->id(),
                'contract' => (string) $this->plan['contract'],
                'plan_json' => self::json($this->plan),
            ];

            WireContracts::defineIibinSchemasIfAvailable();

            if (\function_exists('king_proto_encode')) {
                return [
                    'codec' => 'iibin',
                    'schema' => WireContracts::RUN_ENVELOPE_SCHEMA,
                    'encoded' => true,
                    'binary' => \king_proto_encode(WireContracts::RUN_ENVELOPE_SCHEMA, $payload),
                    'payload' => $payload,
                ];
            }

            return [
                'codec' => 'iibin',
                'schema' => WireContracts::RUN_ENVELOPE_SCHEMA,
                'encoded' => false,
                'missing_extension' => true,
                'payload' => $payload,
            ];
        }

        /**
         * @return array<string,mixed>
         */
        public function persistPlan(?string $objectId = null): array
        {
            $objectId ??= ObjectStoreUriMapper::objectId((string) $this->plan['execution']['run_object'], 'run');
            $envelope = $this->iibinEnvelope();

            if (!\function_exists('king_object_store_put')) {
                return [
                    'object_id' => $objectId,
                    'stored' => false,
                    'runtime' => 'king_object_store',
                    'missing_extension' => true,
                    'write_api' => 'king_object_store_put',
                ];
            }

            $body = $envelope['encoded'] === true ? $envelope['binary'] : self::json($envelope['payload']);
            \king_object_store_put($objectId, $body, [
                'content_type' => $envelope['encoded'] === true
                    ? 'application/vnd.king.training.run+iibin'
                    : 'application/vnd.king.training.run+json',
                'object_type' => 'king.training.distributed.plan',
                'if_none_match' => '*',
            ]);

            return [
                'object_id' => $objectId,
                'stored' => true,
                'runtime' => 'king_object_store',
                'write_api' => 'king_object_store_put',
            ];
        }

        public function sendWatchEvent(mixed $websocket): bool
        {
            $event = [
                'schema_version' => 1,
                'run_id' => $this->id(),
                'type' => 'training.run.planned',
                'state' => (string) $this->plan['execution']['state'],
                'message' => 'Training run is validated as a King control-plane plan; no scheduler backend is attached.',
            ];

            WireContracts::defineIibinSchemasIfAvailable();

            if (\function_exists('king_proto_encode') && \function_exists('king_websocket_send')) {
                $binary = \king_proto_encode(WireContracts::RUN_EVENT_SCHEMA, $event);
                return \king_websocket_send($websocket, $binary, true);
            }

            if (\function_exists('king_websocket_send')) {
                return \king_websocket_send($websocket, self::json($event), false);
            }

            throw new InvalidArgumentException('king_websocket_send is not available in this runtime.');
        }

        /**
         * @param array<string,mixed> $value
         */
        private static function json(array $value): string
        {
            try {
                return \json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('training plan could not be encoded as JSON: ' . $exception->getMessage(), 0, $exception);
            }
        }
    }

    final class WireContracts
    {
        public const RUN_ENVELOPE_SCHEMA = 'KingTrainingRunEnvelopeV1';
        public const RUN_EVENT_SCHEMA = 'KingTrainingRunEventV1';
        public const IIBIN_BATCH_LIMIT = 65536;

        public static function defineIibinSchemasIfAvailable(): void
        {
            if (!\function_exists('king_proto_define_schema')) {
                return;
            }

            if (!\function_exists('king_proto_is_schema_defined') || !\king_proto_is_schema_defined(self::RUN_ENVELOPE_SCHEMA)) {
                \king_proto_define_schema(self::RUN_ENVELOPE_SCHEMA, [
                    'schema_version' => ['type' => 'int32', 'tag' => 1],
                    'run_id' => ['type' => 'string', 'tag' => 2],
                    'contract' => ['type' => 'string', 'tag' => 3],
                    'plan_json' => ['type' => 'string', 'tag' => 4],
                ]);
            }

            if (!\function_exists('king_proto_is_schema_defined') || !\king_proto_is_schema_defined(self::RUN_EVENT_SCHEMA)) {
                \king_proto_define_schema(self::RUN_EVENT_SCHEMA, [
                    'schema_version' => ['type' => 'int32', 'tag' => 1],
                    'run_id' => ['type' => 'string', 'tag' => 2],
                    'type' => ['type' => 'string', 'tag' => 3],
                    'state' => ['type' => 'string', 'tag' => 4],
                    'message' => ['type' => 'string', 'tag' => 5],
                ]);
            }
        }
    }

    final class ObjectStoreUriMapper
    {
        private const MAX_OBJECT_ID_BYTES = 127;

        public static function objectId(string $uri, string $kind): string
        {
            Validation::kingUri($uri, $kind . ' URI');

            $parts = parse_url($uri);
            $host = rawurlencode((string) ($parts['host'] ?? ''));
            $path = trim((string) ($parts['path'] ?? ''), '/');
            $pathSegments = $path === '' ? [] : array_map('rawurlencode', explode('/', $path));
            $candidate = 'training-' . self::sanitizeKind($kind) . '!' . $host;
            if ($pathSegments !== []) {
                $candidate .= '!' . implode('!', $pathSegments);
            }

            if (strlen($candidate) <= self::MAX_OBJECT_ID_BYTES && self::isValidNativeObjectId($candidate)) {
                return $candidate;
            }

            return 'training-' . self::sanitizeKind($kind) . '-sha256-' . hash('sha256', $uri);
        }

        private static function sanitizeKind(string $kind): string
        {
            $kind = strtolower(trim($kind));
            $kind = preg_replace('/[^a-z0-9_-]+/', '-', $kind) ?? '';
            $kind = trim($kind, '-_');
            return $kind !== '' ? $kind : 'object';
        }

        private static function isValidNativeObjectId(string $objectId): bool
        {
            if ($objectId === '' || strlen($objectId) > self::MAX_OBJECT_ID_BYTES) {
                return false;
            }

            return !preg_match('/(?:\.\.|[\/\\\\\x00-\x1f\x7f])/', $objectId);
        }
    }

    final class Validation
    {
        public static function kingUri(string $uri, string $label): void
        {
            $uri = trim($uri);
            $parts = parse_url($uri);
            if (
                !is_array($parts)
                || ($parts['scheme'] ?? null) !== 'king'
                || !isset($parts['host'])
                || trim((string) $parts['host']) === ''
            ) {
                throw new InvalidArgumentException($label . ' must be a nonempty king:// URI.');
            }
        }

        public static function positiveInt(int $value, string $label): void
        {
            if ($value <= 0) {
                throw new InvalidArgumentException($label . ' must be greater than zero.');
            }
        }

        public static function positiveFloat(float $value, string $label): void
        {
            if ($value <= 0.0) {
                throw new InvalidArgumentException($label . ' must be greater than zero.');
            }
        }

        public static function beta(float $value, string $label): void
        {
            if ($value < 0.0 || $value >= 1.0) {
                throw new InvalidArgumentException($label . ' must be in [0, 1).');
            }
        }

        public static function required(mixed $value, string $label): mixed
        {
            if ($value === null) {
                throw new InvalidArgumentException($label . ' is required.');
            }

            return $value;
        }
    }
}
