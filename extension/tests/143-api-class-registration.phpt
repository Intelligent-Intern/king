--TEST--
King base OO classes are runtime-registered in the current runtime
--FILE--
<?php
$classes = [
    'King\\CancelToken',
    'King\\Config',
    'King\\Session',
    'King\\Stream',
    'King\\Response',
    'King\\MCP',
    'King\\IIBIN',
    'King\\ObjectStore',
    'King\\Autoscaling',
    'King\\Client\\HttpClient',
    'King\\Client\\Http1Client',
    'King\\Client\\Http2Client',
    'King\\Client\\Http3Client',
    'King\\WebSocket\\Server',
    'King\\WebSocket\\Connection',
];

$result = [];
foreach ($classes as $class) {
    $ref = new ReflectionClass($class);
    $parent = $ref->getParentClass();
    $result[$class] = [
        'internal' => $ref->isInternal(),
        'final' => $ref->isFinal(),
        'parent' => $parent ? $parent->getName() : null,
    ];
}

var_export($result);
?>
--EXPECT--
array (
  'King\\CancelToken' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\Config' => 
  array (
    'internal' => true,
    'final' => false,
    'parent' => NULL,
  ),
  'King\\Session' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\Stream' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\Response' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\MCP' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\IIBIN' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\ObjectStore' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\Autoscaling' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\Client\\HttpClient' => 
  array (
    'internal' => true,
    'final' => false,
    'parent' => NULL,
  ),
  'King\\Client\\Http1Client' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => 'King\\Client\\HttpClient',
  ),
  'King\\Client\\Http2Client' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => 'King\\Client\\HttpClient',
  ),
  'King\\Client\\Http3Client' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => 'King\\Client\\HttpClient',
  ),
  'King\\WebSocket\\Server' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
  'King\\WebSocket\\Connection' => 
  array (
    'internal' => true,
    'final' => true,
    'parent' => NULL,
  ),
)
