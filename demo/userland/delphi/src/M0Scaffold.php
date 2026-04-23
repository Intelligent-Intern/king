<?php
declare(strict_types=1);

namespace King\Delphi;

use InvalidArgumentException;
use RuntimeException;

final class M0Scaffold
{
    /**
     * Registers the Delphi M0 IIBIN schema set used by the recurrent expert-fanout scaffold.
     */
    public static function registerIibinSchemas(): void
    {
        self::assertExtensionFunction('king_proto_define_enum');
        self::assertExtensionFunction('king_proto_define_schema');

        if (!self::isEnumDefined('DelphiArtifactKind')) {
            king_proto_define_enum('DelphiArtifactKind', [
                'tensor' => 0,
                'expert_batch' => 1,
                'expert_result' => 2,
                'merge_output' => 3,
            ]);
        }

        self::defineSchemaIfMissing('DelphiTensorMeta', [
            'shape' => ['tag' => 1, 'type' => 'repeated_int32'],
            'dtype' => ['tag' => 2, 'type' => 'string', 'required' => true],
            'quantization' => ['tag' => 3, 'type' => 'string'],
            'checksum' => ['tag' => 4, 'type' => 'string'],
            'sequence_id' => ['tag' => 5, 'type' => 'string'],
            'window_id' => ['tag' => 6, 'type' => 'string'],
        ]);

        self::defineSchemaIfMissing('DelphiArtifactRef', [
            'artifact_uri' => ['tag' => 1, 'type' => 'string', 'required' => true],
            'object_id' => ['tag' => 2, 'type' => 'string', 'required' => true],
            'kind' => ['tag' => 3, 'type' => 'DelphiArtifactKind', 'required' => true],
            'size_bytes' => ['tag' => 4, 'type' => 'int64'],
            'version' => ['tag' => 5, 'type' => 'int64'],
            'tensor_meta' => ['tag' => 6, 'type' => 'DelphiTensorMeta'],
            'created_at_ms' => ['tag' => 7, 'type' => 'int64'],
        ]);

        self::defineSchemaIfMissing('DelphiRoutePlan', [
            'run_id' => ['tag' => 1, 'type' => 'string', 'required' => true],
            'swarm_id' => ['tag' => 2, 'type' => 'string'],
            'loop_index' => ['tag' => 3, 'type' => 'int32'],
            'top_k' => ['tag' => 4, 'type' => 'int32'],
            'expert_ids' => ['tag' => 5, 'type' => 'repeated_string'],
            'input_artifact' => ['tag' => 6, 'type' => 'DelphiArtifactRef'],
        ]);

        self::defineSchemaIfMissing('DelphiExpertBatch', [
            'run_id' => ['tag' => 1, 'type' => 'string', 'required' => true],
            'step_id' => ['tag' => 2, 'type' => 'string', 'required' => true],
            'expert_id' => ['tag' => 3, 'type' => 'string', 'required' => true],
            'owner_node_id' => ['tag' => 4, 'type' => 'string', 'required' => true],
            'activation_artifact' => ['tag' => 5, 'type' => 'DelphiArtifactRef', 'required' => true],
            'idempotency_key' => ['tag' => 6, 'type' => 'string'],
        ]);

        self::defineSchemaIfMissing('DelphiExpertResult', [
            'run_id' => ['tag' => 1, 'type' => 'string', 'required' => true],
            'step_id' => ['tag' => 2, 'type' => 'string', 'required' => true],
            'expert_id' => ['tag' => 3, 'type' => 'string', 'required' => true],
            'status' => ['tag' => 4, 'type' => 'string', 'required' => true],
            'output_artifact' => ['tag' => 5, 'type' => 'DelphiArtifactRef'],
            'runtime_ms' => ['tag' => 6, 'type' => 'int64'],
            'error_detail' => ['tag' => 7, 'type' => 'string'],
        ]);

        self::defineSchemaIfMissing('DelphiLayerMerge', [
            'run_id' => ['tag' => 1, 'type' => 'string', 'required' => true],
            'loop_index' => ['tag' => 2, 'type' => 'int32', 'required' => true],
            'merged_output_artifact' => ['tag' => 3, 'type' => 'DelphiArtifactRef'],
            'next_action' => ['tag' => 4, 'type' => 'string'],
        ]);
    }

    /**
     * @param array<int,array{expert_id:string,owner_node_id:string}> $expertOwners
     * @param array<string,mixed> $inputArtifactRef
     * @return array{steps:array<int,array<string,mixed>>}
     */
    public static function buildOneLoopExpertFanoutPipeline(
        string $runId,
        array $expertOwners,
        array $inputArtifactRef,
        int $loopIndex = 0,
        int $topK = 2
    ): array {
        if ($runId === '') {
            throw new InvalidArgumentException('runId must be non-empty.');
        }
        if ($loopIndex < 0) {
            throw new InvalidArgumentException('loopIndex must be >= 0.');
        }
        if ($topK <= 0) {
            throw new InvalidArgumentException('topK must be > 0.');
        }
        if ($expertOwners === []) {
            throw new InvalidArgumentException('expertOwners must contain at least one expert owner.');
        }
        self::assertArtifactRef($inputArtifactRef);

        $prepareId = sprintf('prepare_inputs.loop_%d', $loopIndex);
        $routeId = sprintf('route_tokens_topk.loop_%d', $loopIndex);
        $collectId = sprintf('collect_expert_results.loop_%d', $loopIndex);
        $mergeId = sprintf('merge_weighted_outputs.loop_%d', $loopIndex);
        $nextId = sprintf('next_layer_or_decode.loop_%d', $loopIndex);
        $emitId = sprintf('emit_final.loop_%d', $loopIndex);

        $dispatchIds = [];
        $steps = [
            [
                'id' => $prepareId,
                'tool' => 'delphi.prepare_inputs',
                'params' => [
                    'run_id' => $runId,
                    'loop_index' => $loopIndex,
                    'input_artifact' => $inputArtifactRef,
                ],
            ],
            [
                'id' => $routeId,
                'tool' => 'delphi.route_tokens_topk',
                'deps' => [$prepareId],
                'params' => [
                    'run_id' => $runId,
                    'loop_index' => $loopIndex,
                    'top_k' => $topK,
                    'expert_count' => count($expertOwners),
                ],
            ],
        ];

        foreach ($expertOwners as $owner) {
            $expertId = $owner['expert_id'] ?? '';
            $ownerNodeId = $owner['owner_node_id'] ?? '';
            if (!is_string($expertId) || $expertId === '' || !is_string($ownerNodeId) || $ownerNodeId === '') {
                throw new InvalidArgumentException('Each expert owner must include non-empty expert_id and owner_node_id.');
            }

            $dispatchId = sprintf('dispatch_expert_batch.%s.loop_%d', $expertId, $loopIndex);
            $dispatchIds[] = $dispatchId;
            $steps[] = [
                'id' => $dispatchId,
                'tool' => 'delphi.dispatch_expert_batch',
                'deps' => [$routeId],
                'params' => [
                    'run_id' => $runId,
                    'loop_index' => $loopIndex,
                    'expert_id' => $expertId,
                    'owner_node_id' => $ownerNodeId,
                    'input_artifact' => $inputArtifactRef,
                    'idempotency_key' => sprintf('%s:%s:%d', $runId, $expertId, $loopIndex),
                ],
            ];
        }

        $steps[] = [
            'id' => $collectId,
            'tool' => 'delphi.collect_expert_results',
            'deps' => $dispatchIds,
            'params' => [
                'run_id' => $runId,
                'loop_index' => $loopIndex,
                'expected_expert_results' => count($dispatchIds),
            ],
        ];
        $steps[] = [
            'id' => $mergeId,
            'tool' => 'delphi.merge_weighted_outputs',
            'deps' => [$collectId],
            'params' => [
                'run_id' => $runId,
                'loop_index' => $loopIndex,
            ],
        ];
        $steps[] = [
            'id' => $nextId,
            'tool' => 'delphi.next_layer_or_decode',
            'deps' => [$mergeId],
            'params' => [
                'run_id' => $runId,
                'loop_index' => $loopIndex,
            ],
        ];
        $steps[] = [
            'id' => $emitId,
            'tool' => 'delphi.emit_final',
            'deps' => [$nextId],
            'params' => [
                'run_id' => $runId,
                'loop_index' => $loopIndex,
            ],
        ];

        return ['steps' => $steps];
    }

    /**
     * @param array<string,mixed> $tensorMeta
     * @param array<string,mixed> $writeOptions
     * @return array<string,mixed>
     */
    public static function storeTensorArtifact(
        string $objectId,
        string $payload,
        array $tensorMeta,
        array $writeOptions = []
    ): array {
        self::assertExtensionFunction('king_object_store_put');
        self::assertExtensionFunction('king_object_store_get_metadata');

        if ($objectId === '') {
            throw new InvalidArgumentException('objectId must be non-empty.');
        }
        if ($payload === '') {
            throw new InvalidArgumentException('payload must be non-empty.');
        }

        $normalizedMeta = self::normalizeTensorMeta($tensorMeta, $payload);
        $options = array_merge(
            [
                'content_type' => 'application/octet-stream',
                'object_type' => 'binary_data',
                'cache_policy' => 'etag',
                'integrity_sha256' => $normalizedMeta['checksum'],
            ],
            $writeOptions
        );

        $stored = king_object_store_put($objectId, $payload, $options);
        if ($stored !== true) {
            $error = function_exists('king_get_last_error') ? (string) king_get_last_error() : 'unknown object-store error';
            throw new RuntimeException('Failed to persist tensor artifact: ' . $error);
        }

        $metadata = king_object_store_get_metadata($objectId);
        $version = 0;
        if (is_array($metadata) && isset($metadata['version']) && is_numeric($metadata['version'])) {
            $version = (int) $metadata['version'];
        }

        return [
            'artifact_uri' => 'object://' . $objectId,
            'object_id' => $objectId,
            'kind' => 'tensor',
            'size_bytes' => strlen($payload),
            'version' => $version,
            'tensor_meta' => $normalizedMeta,
            'created_at_ms' => (int) floor(microtime(true) * 1000),
        ];
    }

    /**
     * @param array<string,mixed> $artifactRef
     */
    public static function encodeArtifactRef(array $artifactRef): string
    {
        self::assertArtifactRef($artifactRef);
        self::assertExtensionFunction('king_proto_encode');

        $encoded = king_proto_encode('DelphiArtifactRef', $artifactRef);
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Failed to encode DelphiArtifactRef payload.');
        }

        return $encoded;
    }

    private static function defineSchemaIfMissing(string $schemaName, array $schemaDefinition): void
    {
        if (!self::isSchemaDefined($schemaName)) {
            king_proto_define_schema($schemaName, $schemaDefinition);
        }
    }

    private static function isEnumDefined(string $enumName): bool
    {
        if (function_exists('king_proto_is_enum_defined')) {
            return king_proto_is_enum_defined($enumName) === true;
        }
        if (function_exists('king_proto_is_defined')) {
            return king_proto_is_defined($enumName) === true;
        }

        return false;
    }

    private static function isSchemaDefined(string $schemaName): bool
    {
        if (function_exists('king_proto_is_schema_defined')) {
            return king_proto_is_schema_defined($schemaName) === true;
        }
        if (function_exists('king_proto_is_defined')) {
            return king_proto_is_defined($schemaName) === true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $artifactRef
     */
    private static function assertArtifactRef(array $artifactRef): void
    {
        $uri = $artifactRef['artifact_uri'] ?? null;
        $objectId = $artifactRef['object_id'] ?? null;
        $kind = $artifactRef['kind'] ?? null;
        $tensorMeta = $artifactRef['tensor_meta'] ?? null;

        if (!is_string($uri) || $uri === '') {
            throw new InvalidArgumentException('artifact_ref.artifact_uri must be a non-empty string.');
        }
        if (!is_string($objectId) || $objectId === '') {
            throw new InvalidArgumentException('artifact_ref.object_id must be a non-empty string.');
        }
        if (!is_string($kind) || $kind === '') {
            throw new InvalidArgumentException('artifact_ref.kind must be a non-empty string.');
        }
        if (!is_array($tensorMeta)) {
            throw new InvalidArgumentException('artifact_ref.tensor_meta must be an array.');
        }
        self::normalizeTensorMeta($tensorMeta);
    }

    /**
     * @param array<string,mixed> $tensorMeta
     * @return array{shape:array<int,int>,dtype:string,quantization:string,checksum:string,sequence_id:string,window_id:string}
     */
    private static function normalizeTensorMeta(array $tensorMeta, string $payload = ''): array
    {
        $shape = $tensorMeta['shape'] ?? null;
        $dtype = $tensorMeta['dtype'] ?? null;

        if (!is_array($shape) || $shape === []) {
            throw new InvalidArgumentException('tensor_meta.shape must be a non-empty array of integers.');
        }
        if (!is_string($dtype) || $dtype === '') {
            throw new InvalidArgumentException('tensor_meta.dtype must be a non-empty string.');
        }

        $normalizedShape = [];
        foreach ($shape as $value) {
            if (!is_int($value) || $value <= 0) {
                throw new InvalidArgumentException('tensor_meta.shape entries must be positive integers.');
            }
            $normalizedShape[] = $value;
        }

        $checksum = $tensorMeta['checksum'] ?? '';
        if (!is_string($checksum) || $checksum === '') {
            if ($payload === '') {
                throw new InvalidArgumentException('tensor_meta.checksum must be provided when payload is not available.');
            }
            $checksum = hash('sha256', $payload);
        }

        $quantization = $tensorMeta['quantization'] ?? 'none';
        $sequenceId = $tensorMeta['sequence_id'] ?? '';
        $windowId = $tensorMeta['window_id'] ?? '';

        if (!is_string($quantization)) {
            throw new InvalidArgumentException('tensor_meta.quantization must be a string when provided.');
        }
        if (!is_string($sequenceId) || !is_string($windowId)) {
            throw new InvalidArgumentException('tensor_meta.sequence_id and tensor_meta.window_id must be strings when provided.');
        }

        return [
            'shape' => $normalizedShape,
            'dtype' => $dtype,
            'quantization' => $quantization,
            'checksum' => $checksum,
            'sequence_id' => $sequenceId,
            'window_id' => $windowId,
        ];
    }

    private static function assertExtensionFunction(string $functionName): void
    {
        if (!function_exists($functionName)) {
            throw new RuntimeException('Required King extension function is unavailable: ' . $functionName);
        }
    }
}
