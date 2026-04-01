--TEST--
King object-store rejects control characters in object identifiers before snapshot manifest export
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_object_store_id_control_474_' . getmypid();
@mkdir($root, 0700, true);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]));

$badIds = [
    "evil\nformat=bad",
    "evil\rkind=full",
];

foreach ($badIds as $badId) {
    foreach ([
        static fn() => king_object_store_put($badId, 'payload'),
        static fn() => king_object_store_get($badId),
        static fn() => king_object_store_delete($badId),
    ] as $operation) {
        try {
            $operation();
            echo "unexpected\n";
        } catch (Throwable $e) {
            var_dump(get_class($e));
            var_dump($e->getMessage());
        }
    }
}

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink($root . '/' . $file);
    }
}
@rmdir($root);
?>
--EXPECT--
bool(true)
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
