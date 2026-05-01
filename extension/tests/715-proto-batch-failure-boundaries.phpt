--TEST--
King proto batch encode/decode failures are indexed bounded and fail whole-batch
--FILE--
<?php
function capture_failure(callable $callback): array
{
    try {
        $callback();
    } catch (Throwable $e) {
        return [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'previous_class' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
            'previous_message' => $e->getPrevious()?->getMessage(),
        ];
    }

    return [
        'class' => null,
        'message' => 'no failure',
        'previous_class' => null,
        'previous_message' => null,
    ];
}

function summarize_failure(array $failure, array $needles): array
{
    $summary = [
        'class' => $failure['class'],
        'previous_class' => $failure['previous_class'],
    ];

    foreach ($needles as $label => $needle) {
        $summary[$label] = is_string($failure['message'])
            && str_contains($failure['message'], $needle);
    }

    if (is_string($failure['previous_message'])) {
        $summary['previous_has_detail'] = str_contains($failure['previous_message'], 'Decoding error:')
            || str_contains($failure['previous_message'], 'Required field');
    }

    return $summary;
}

var_dump(king_proto_define_schema('ProtoBatchFailure715', [
    'id' => ['tag' => 1, 'type' => 'uint32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema('ProtoBatchMismatch715', [
    'label' => ['tag' => 2, 'type' => 'string', 'required' => true],
]));

$valid = king_proto_encode('ProtoBatchFailure715', ['id' => 7, 'name' => 'ok']);
$mismatchPayload = king_proto_encode('ProtoBatchMismatch715', ['label' => 'wrong-schema']);
$oversized = array_fill(0, 65537, '');

$failures = [
    'malformed_boundary' => summarize_failure(
        capture_failure(static fn () => king_proto_decode_batch('ProtoBatchFailure715', [$valid, 123])),
        [
            'has_index' => 'invalid record index 1',
            'has_strings_rule' => 'must contain only strings',
        ]
    ),
    'truncated_record' => summarize_failure(
        capture_failure(static fn () => king_proto_decode_batch('ProtoBatchFailure715', ["\x08"])),
        [
            'has_batch_index' => 'record index 0',
            'has_schema' => "schema 'ProtoBatchFailure715'",
        ]
    ),
    'schema_mismatch' => summarize_failure(
        capture_failure(static fn () => king_proto_decode_batch('ProtoBatchFailure715', [$mismatchPayload])),
        [
            'has_batch_index' => 'record index 0',
            'has_schema' => "schema 'ProtoBatchFailure715'",
        ]
    ),
    'oversized_decode' => summarize_failure(
        capture_failure(static fn () => king_proto_decode_batch('ProtoBatchFailure715', $oversized)),
        [
            'has_record_count' => 'record count 65537',
            'has_limit' => 'maximum of 65536',
        ]
    ),
    'oversized_encode' => summarize_failure(
        capture_failure(static fn () => king_proto_encode_batch('ProtoBatchFailure715', array_fill(0, 65537, ['id' => 1]))),
        [
            'has_record_count' => 'record count 65537',
            'has_limit' => 'maximum of 65536',
        ]
    ),
];

echo json_encode($failures, JSON_PRETTY_PRINT), "\n";
?>
--EXPECT--
bool(true)
bool(true)
{
    "malformed_boundary": {
        "class": "ValueError",
        "previous_class": null,
        "has_index": true,
        "has_strings_rule": true
    },
    "truncated_record": {
        "class": "King\\ValidationException",
        "previous_class": "King\\ValidationException",
        "has_batch_index": true,
        "has_schema": true,
        "previous_has_detail": true
    },
    "schema_mismatch": {
        "class": "King\\ValidationException",
        "previous_class": "King\\ValidationException",
        "has_batch_index": true,
        "has_schema": true,
        "previous_has_detail": true
    },
    "oversized_decode": {
        "class": "King\\ValidationException",
        "previous_class": null,
        "has_record_count": true,
        "has_limit": true
    },
    "oversized_encode": {
        "class": "King\\ValidationException",
        "previous_class": null,
        "has_record_count": true,
        "has_limit": true
    }
}
