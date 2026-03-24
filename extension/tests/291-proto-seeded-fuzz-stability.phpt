--TEST--
King proto seeded fuzz keeps malformed decode handling and roundtrip stability intact
--FILE--
<?php
mt_srand(291);

var_dump(king_proto_define_schema('ProtoFuzz291', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
    'label' => ['tag' => 3, 'type' => 'string'],
]));

$roundtripOk = true;
for ($i = 0; $i < 64; $i++) {
    $expected = [
        'id' => $i + 1,
        'enabled' => (($i % 2) === 0),
        'label' => 'seed-' . $i,
    ];

    $decoded = king_proto_decode('ProtoFuzz291', king_proto_encode('ProtoFuzz291', $expected));
    if (
        ($decoded['id'] ?? null) !== $expected['id']
        || ($decoded['enabled'] ?? null) !== $expected['enabled']
        || ($decoded['label'] ?? null) !== $expected['label']
    ) {
        $roundtripOk = false;
        break;
    }
}

$decodedCount = 0;
$handledCount = 0;
for ($i = 0; $i < 128; $i++) {
    switch ($i % 4) {
        case 0:
            $payload = king_proto_encode('ProtoFuzz291', [
                'id' => 1000 + $i,
                'enabled' => true,
                'label' => 'valid-' . $i,
            ]);
            break;
        case 1:
            $payload = "\x80";
            break;
        case 2:
            $payload = '';
            $length = 1 + ($i % 13);
            for ($j = 0; $j < $length; $j++) {
                $payload .= chr(mt_rand(0, 255));
            }
            break;
        default:
            $payload = king_proto_encode('ProtoFuzz291', [
                'id' => 2000 + $i,
                'enabled' => false,
                'label' => 'truncated-' . $i,
            ]);
            $payload = substr($payload, 0, max(0, strlen($payload) - 1));
            break;
    }

    try {
        $decoded = king_proto_decode('ProtoFuzz291', $payload);
        if (is_array($decoded) || is_object($decoded)) {
            $decodedCount++;
        }
    } catch (Throwable $e) {
        $handledCount++;
    }
}

$final = king_proto_decode('ProtoFuzz291', king_proto_encode('ProtoFuzz291', [
    'id' => 7,
    'enabled' => true,
    'label' => 'stable',
]));

var_dump($roundtripOk);
var_dump($decodedCount > 0);
var_dump($handledCount > 0);
var_dump(($final['id'] ?? null) === 7);
var_dump(($final['label'] ?? null) === 'stable');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
