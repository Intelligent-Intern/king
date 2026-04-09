--TEST--
King hardening sweep rejects unsafe inputs across public entry points
--INI--
king.security_allow_config_override=1
king.otel_exporter_endpoint=http://collector.internal:4318/v1/traces
king.otel_exporter_headers="Authorization: Bearer baseline"
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_hardening_sweep_649_' . getmypid();
@mkdir($root, 0700, true);

$expectValidation = static function (callable $operation, string $needle): bool {
    try {
        $operation();
        return false;
    } catch (Throwable $e) {
        return $e instanceof King\ValidationException
            && str_contains($e->getMessage(), $needle);
    }
};

$expectInvalidArgument = static function (callable $operation, string $needle): bool {
    try {
        $operation();
        return false;
    } catch (Throwable $e) {
        return $e instanceof InvalidArgumentException
            && str_contains($e->getMessage(), $needle);
    }
};

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]));

$unsafeObjectIds = [
    '../escape',
    'segment/../escape',
    "bad\nid",
    "bad\0id",
    "bad\tid",
];

foreach ($unsafeObjectIds as $unsafeObjectId) {
    var_dump($expectValidation(
        static fn() => king_object_store_put($unsafeObjectId, 'payload'),
        'Object ID is invalid for object-store paths.'
    ));
    var_dump($expectValidation(
        static fn() => king_object_store_get($unsafeObjectId),
        'Object ID is invalid for object-store paths.'
    ));
    var_dump($expectValidation(
        static fn() => king_object_store_delete($unsafeObjectId),
        'Object ID is invalid for object-store paths.'
    ));
    var_dump($expectValidation(
        static fn() => king_cdn_cache_object($unsafeObjectId),
        'Object ID is invalid for object-store paths.'
    ));
}

var_dump(
    king_client_websocket_connect('wss://user:pass@example.test/socket') === false
    && str_contains(
        king_client_websocket_get_last_error(),
        'does not support embedding credentials in the connection URL.'
    )
);

var_dump(
    king_client_websocket_connect('http://example.test/socket') === false
    && str_contains(
        king_client_websocket_get_last_error(),
        'currently supports only absolute ws:// and wss:// URLs.'
    )
);

var_dump($expectInvalidArgument(
    static fn() => king_telemetry_init([
        'otel_exporter_endpoint' => 'http://user:secret@collector.internal:4318/v1/traces',
    ]),
    'must not embed credentials in the URL.'
));

var_dump($expectInvalidArgument(
    static fn() => king_telemetry_init([
        'otel_exporter_headers' => "Authorization: Bearer baseline\r\nX-Evil: 1",
    ]),
    'must stay on one line without CRLF.'
));

var_dump($expectInvalidArgument(
    static fn() => new King\Config([
        'otel.exporter_endpoint' => 'http://collector.internal:4318/v1/traces?token=secret',
    ]),
    'must not include query strings or fragments.'
));

var_dump($expectInvalidArgument(
    static fn() => new King\Config([
        'otel.exporter_headers' => "Authorization: Bearer baseline\r\nX-Evil: 1",
    ]),
    'must stay on one line without CRLF.'
));

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink($root . '/' . $file);
    }
}
@rmdir($root);
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
