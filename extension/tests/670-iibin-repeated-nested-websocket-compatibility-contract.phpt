--TEST--
King IIBIN repeated and nested payloads survive websocket transport and preserve cross-schema compatibility
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$memberV1 = 'IIBINMember670V1';
$memberV2 = 'IIBINMember670V2';
$profileV2 = 'IIBINProfile670V2';
$envelopeV1 = 'IIBINEnvelope670V1';
$envelopeV2 = 'IIBINEnvelope670V2';

var_dump(king_proto_define_schema($memberV1, [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'role' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema($envelopeV1, [
    'topic' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'members' => ['tag' => 2, 'type' => 'repeated_' . $memberV1, 'required' => true],
    'request_id' => ['tag' => 3, 'type' => 'string'],
]));

var_dump(King\IIBIN::defineSchema($profileV2, [
    'zone' => ['tag' => 1, 'type' => 'string'],
    'capabilities' => ['tag' => 2, 'type' => 'repeated_string'],
]));
var_dump(King\IIBIN::defineSchema($memberV2, [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'role' => ['tag' => 2, 'type' => 'string'],
    'profile' => ['tag' => 3, 'type' => $profileV2],
    'labels' => ['tag' => 4, 'type' => 'repeated_string'],
]));
var_dump(King\IIBIN::defineSchema($envelopeV2, [
    'topic' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'members' => ['tag' => 2, 'type' => 'repeated_' . $memberV2, 'required' => true],
    'request_id' => ['tag' => 3, 'type' => 'string'],
    'ack_ids' => ['tag' => 4, 'type' => 'repeated_string'],
    'envelope_version' => ['tag' => 5, 'type' => 'int32'],
]));

$payloadV2 = King\IIBIN::encode($envelopeV2, [
    'topic' => 'control.room.sync',
    'request_id' => 'req-670-v2',
    'members' => [
        [
            'id' => 7,
            'role' => 'speaker',
            'labels' => ['moderator', 'eu'],
            'profile' => [
                'zone' => 'fra1',
                'capabilities' => ['video', 'screen-share'],
            ],
        ],
        [
            'id' => 9,
            'role' => 'viewer',
            'labels' => ['mobile'],
            'profile' => [
                'zone' => 'iad1',
                'capabilities' => ['video'],
            ],
        ],
    ],
    'ack_ids' => ['req-668', 'req-669'],
    'envelope_version' => 2,
]);

$payloadV1 = king_proto_encode($envelopeV1, [
    'topic' => 'control.room.sync',
    'request_id' => 'req-670-v1',
    'members' => [
        [
            'id' => 11,
            'role' => 'speaker',
        ],
    ],
]);

$server = king_websocket_test_start_server();
$capture = [];

try {
    $websocket = king_client_websocket_connect(
        'ws://127.0.0.1:' . $server['port'] . '/iibin',
        null,
        ['max_payload_size' => 1024 * 1024]
    );

    var_dump(is_resource($websocket));
    var_dump(king_websocket_send($websocket, $payloadV2, true));

    $echo = king_client_websocket_receive($websocket, 500);
    var_dump(is_string($echo));
    var_dump($echo === $payloadV2);

    $decodedV2FromV2 = King\IIBIN::decode($envelopeV2, (string) $echo);
    $decodedV1FromV2 = king_proto_decode($envelopeV1, (string) $echo);

    var_dump(($decodedV2FromV2['members'][0]['labels'] ?? null) === ['moderator', 'eu']);
    var_dump(($decodedV2FromV2['members'][0]['profile']['zone'] ?? null) === 'fra1');
    var_dump(($decodedV2FromV2['members'][0]['profile']['capabilities'] ?? null) === ['video', 'screen-share']);
    var_dump(($decodedV2FromV2['ack_ids'] ?? null) === ['req-668', 'req-669']);

    var_dump(($decodedV1FromV2['topic'] ?? null) === 'control.room.sync');
    var_dump(($decodedV1FromV2['members'][0]['id'] ?? null) === 7);
    var_dump(($decodedV1FromV2['members'][0]['role'] ?? null) === 'speaker');
    var_dump(array_key_exists('labels', $decodedV1FromV2['members'][0]) === false);
    var_dump(array_key_exists('profile', $decodedV1FromV2['members'][0]) === false);
    var_dump(array_key_exists('ack_ids', $decodedV1FromV2) === false);
    var_dump(array_key_exists('envelope_version', $decodedV1FromV2) === false);

    $decodedV2FromV1 = King\IIBIN::decode($envelopeV2, $payloadV1);
    var_dump(($decodedV2FromV1['request_id'] ?? null) === 'req-670-v1');
    var_dump(($decodedV2FromV1['members'][0]['id'] ?? null) === 11);
    var_dump(($decodedV2FromV1['members'][0]['role'] ?? null) === 'speaker');
    var_dump(array_key_exists('labels', $decodedV2FromV1['members'][0]) === false);
    var_dump(array_key_exists('profile', $decodedV2FromV1['members'][0]) === false);
    var_dump(array_key_exists('ack_ids', $decodedV2FromV1) === false);

    $decodedObject = King\IIBIN::decode($envelopeV2, $payloadV1, true);
    var_dump($decodedObject instanceof stdClass);
    var_dump(is_array($decodedObject->members));
    var_dump($decodedObject->members[0] instanceof stdClass);
    var_dump($decodedObject->members[0]->id === 11);

    var_dump(king_client_websocket_close($websocket, 1000, 'done'));
} finally {
    $capture = king_websocket_test_stop_server($server);
}

var_dump(($capture[0]['frames'][0]['opcode'] ?? null) === 2);
var_dump(($capture[0]['frames'][0]['payload'] ?? null) === $payloadV2);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
