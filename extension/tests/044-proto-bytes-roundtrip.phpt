--TEST--
King proto bytes fields preserve binary payloads in the runtime primitive subset
--FILE--
<?php
var_dump(king_proto_define_schema('BlobMessage', [
    'blob' => ['tag' => 1, 'type' => 'bytes', 'required' => true],
]));

$encoded = king_proto_encode('BlobMessage', [
    'blob' => "A\0B",
]);
var_dump(bin2hex($encoded));

$decoded = king_proto_decode('BlobMessage', $encoded);
var_dump(bin2hex($decoded['blob']));
var_dump(strlen($decoded['blob']));
?>
--EXPECT--
bool(true)
string(10) "0a03410042"
string(6) "410042"
int(3)
