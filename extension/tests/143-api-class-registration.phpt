--TEST--
King base OO classes are runtime-registered in the current runtime
--FILE--
<?php
$classes = [
    'King\\CancelToken' => [true, null],
    'King\\Awaitable' => [true, null],
    'King\\Config' => [false, null],
    'King\\Session' => [true, null],
    'King\\Stream' => [true, null],
    'King\\Response' => [true, null],
    'King\\MCP' => [true, null],
    'King\\IIBIN' => [true, null],
    'King\\ObjectStore' => [true, null],
    'King\\Autoscaling' => [true, null],
    'King\\Client\\HttpClient' => [false, null],
    'King\\Client\\Http1Client' => [true, 'King\\Client\\HttpClient'],
    'King\\Client\\Http2Client' => [true, 'King\\Client\\HttpClient'],
    'King\\Client\\Http3Client' => [true, 'King\\Client\\HttpClient'],
    'King\\WebSocket\\Server' => [true, null],
    'King\\WebSocket\\Connection' => [true, null],
];

$failures = [];
foreach ($classes as $class => [$expectedFinal, $expectedParent]) {
    $ref = new ReflectionClass($class);
    $parent = $ref->getParentClass();
    $actual = [
        'internal' => $ref->isInternal(),
        'final' => $ref->isFinal(),
        'parent' => $parent ? $parent->getName() : null,
    ];

    if ($actual !== [
        'internal' => true,
        'final' => $expectedFinal,
        'parent' => $expectedParent,
    ]) {
        $failures[$class] = $actual;
    }
}

var_dump($failures);
?>
--EXPECT--
array(0) {
}
