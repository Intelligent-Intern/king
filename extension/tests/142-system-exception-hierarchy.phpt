--TEST--
King system exception class is registered and extends the base King exception
--FILE--
<?php
$runtime = [
    'exists' => class_exists(King\SystemException::class),
    'parent' => get_parent_class(King\SystemException::class),
    'is_base' => is_subclass_of(King\SystemException::class, King\Exception::class),
];

try {
    throw new King\SystemException('system failure');
} catch (Throwable $e) {
    $runtime['throw_class'] = get_class($e);
    $runtime['throw_parent'] = get_parent_class($e);
}

var_export($runtime);
?>
--EXPECT--
array (
  'exists' => true,
  'parent' => 'King\\Exception',
  'is_base' => true,
  'throw_class' => 'King\\SystemException',
  'throw_parent' => 'King\\Exception',
)
