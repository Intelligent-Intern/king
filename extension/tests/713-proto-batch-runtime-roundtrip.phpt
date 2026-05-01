--TEST--
King proto batch encode and decode runtime roundtrip covers procedural and IIBIN facade paths
--FILE--
<?php
final class ProtoBatchJob713Object
{
    public int $id;
    public string $name;
    public int $status;
    public array $labels;
}

var_dump(king_proto_define_enum('ProtoBatchStatus713', [
    'OPEN' => 1,
    'DONE' => 2,
]));
var_dump(king_proto_define_schema('ProtoBatchJob713', [
    'id' => ['tag' => 1, 'type' => 'uint32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string', 'required' => true],
    'status' => ['tag' => 3, 'type' => 'ProtoBatchStatus713', 'required' => true],
    'labels' => ['tag' => 4, 'type' => 'repeated_string'],
]));

$records = [
    [
        'id' => 1,
        'name' => 'alpha',
        'status' => 'OPEN',
        'labels' => ['red', 'blue'],
    ],
    [
        'id' => 2,
        'name' => 'beta',
        'status' => 2,
        'labels' => ['green'],
    ],
];

$encoded = king_proto_encode_batch('ProtoBatchJob713', $records);
$facadeEncoded = King\IIBIN::encodeBatch('ProtoBatchJob713', $records);
$decoded = king_proto_decode_batch('ProtoBatchJob713', $encoded);
$facadeDecoded = King\IIBIN::decodeBatch('ProtoBatchJob713', $facadeEncoded);
$decodedObjects = king_proto_decode_batch(
    'ProtoBatchJob713',
    $encoded,
    ProtoBatchJob713Object::class
);

echo json_encode([
    'encoded_hex' => array_map('bin2hex', $encoded),
    'matches_single_record' => [
        $encoded[0] === king_proto_encode('ProtoBatchJob713', $records[0]),
        $encoded[1] === King\IIBIN::encode('ProtoBatchJob713', $records[1]),
    ],
    'facade_matches_procedural' => $facadeEncoded === $encoded,
    'decoded' => $decoded,
    'facade_decoded' => $facadeDecoded,
    'decoded_objects' => array_map(
        static fn (ProtoBatchJob713Object $record): array => [
            'class' => get_class($record),
            'id' => $record->id,
            'name' => $record->name,
            'status' => $record->status,
            'labels' => $record->labels,
        ],
        $decodedObjects
    ),
], JSON_PRETTY_PRINT), "\n";
?>
--EXPECT--
bool(true)
bool(true)
{
    "encoded_hex": [
        "08011205616c706861180122037265642204626c7565",
        "080212046265746118022205677265656e"
    ],
    "matches_single_record": [
        true,
        true
    ],
    "facade_matches_procedural": true,
    "decoded": [
        {
            "id": 1,
            "name": "alpha",
            "status": 1,
            "labels": [
                "red",
                "blue"
            ]
        },
        {
            "id": 2,
            "name": "beta",
            "status": 2,
            "labels": [
                "green"
            ]
        }
    ],
    "facade_decoded": [
        {
            "id": 1,
            "name": "alpha",
            "status": 1,
            "labels": [
                "red",
                "blue"
            ]
        },
        {
            "id": 2,
            "name": "beta",
            "status": 2,
            "labels": [
                "green"
            ]
        }
    ],
    "decoded_objects": [
        {
            "class": "ProtoBatchJob713Object",
            "id": 1,
            "name": "alpha",
            "status": 1,
            "labels": [
                "red",
                "blue"
            ]
        },
        {
            "class": "ProtoBatchJob713Object",
            "id": 2,
            "name": "beta",
            "status": 2,
            "labels": [
                "green"
            ]
        }
    ]
}
