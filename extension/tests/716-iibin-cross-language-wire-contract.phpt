--TEST--
King IIBIN matches Python and JavaScript unsigned 64-bit, UTF-8 and finite-number wire contracts
--FILE--
<?php
King\IIBIN::defineSchema('Interop716', [
    'unsigned' => ['tag' => 1, 'type' => 'uint64'],
    'fixed' => ['tag' => 2, 'type' => 'fixed64'],
    'signed' => ['tag' => 3, 'type' => 'sint64'],
    'text' => ['tag' => 4, 'type' => 'string'],
    'float' => ['tag' => 5, 'type' => 'float'],
    'double' => ['tag' => 6, 'type' => 'double'],
    'bytes' => ['tag' => 7, 'type' => 'bytes'],
]);
$message = ['unsigned' => '18446744073709551615', 'fixed' => '18446744073709551615', 'signed' => PHP_INT_MIN];
$wire = hex2bin('08ffffffffffffffffff0111ffffffffffffffff18ffffffffffffffffff01');
var_dump(King\IIBIN::encode('Interop716', $message) === $wire);
var_dump(King\IIBIN::decode('Interop716', $wire) === $message);
var_dump(king_proto_decode('Interop716', $wire) === $message);
var_dump(King\IIBIN::decode('Interop716', hex2bin('0801'))['unsigned'] === 1);

$bad = ['18446744073709551616', '-1', '01', '', '1.5', '1e3', '+1', ' 1', "1\0"];
foreach (['unsigned', 'fixed'] as $field) {
    $rejected = 0;
    foreach ($bad as $value) {
        try { King\IIBIN::encode('Interop716', [$field => $value]); }
        catch (King\ValidationException $error) { $rejected++; }
    }
    var_dump($rejected === count($bad));
}
$invalidMessages = [
    ['text' => "\xff"], ['text' => "\xed\xa0\x80"], ['text' => "\xc0\x80"],
    ['float' => INF], ['float' => 1e100], ['double' => NAN],
];
$rejected = 0;
foreach ($invalidMessages as $message) {
    try { King\IIBIN::encode('Interop716', $message); }
    catch (King\ValidationException $error) { $rejected++; }
}
var_dump($rejected === count($invalidMessages));
$invalidWire = ['0000', '010000000000000000', '808080801000', '2201ff', '2203eda080', '2d0000807f', '31000000000000f87f'];
$rejected = 0;
foreach ($invalidWire as $hex) {
    try { King\IIBIN::decode('Interop716', hex2bin($hex)); }
    catch (King\ValidationException $error) { $rejected++; }
}
var_dump($rejected === count($invalidWire));
$opaque = ['bytes' => "\xff\x00\xc0\x80"];
var_dump(King\IIBIN::decode('Interop716', King\IIBIN::encode('Interop716', $opaque)) === $opaque);
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
