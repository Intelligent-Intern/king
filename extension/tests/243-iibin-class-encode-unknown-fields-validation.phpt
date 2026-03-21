--TEST--
King IIBIN class encode rejects unexpected top-level fields and stays usable afterwards
--FILE--
<?php
var_dump(King\IIBIN::defineSchema('UserValidateClass', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));

try {
    King\IIBIN::encode('UserValidateClass', ['id' => 7, 'ghost' => 9]);
    echo "NO-ERROR\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

var_dump(bin2hex(King\IIBIN::encode('UserValidateClass', ['id' => 7])));
?>
--EXPECT--
bool(true)
King\ValidationException
Encoding failed: Schema 'UserValidateClass' does not define a field named 'ghost'.
string(4) "0807"
