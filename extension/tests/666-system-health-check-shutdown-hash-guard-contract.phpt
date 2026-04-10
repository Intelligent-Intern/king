--TEST--
King system health check revalidates initialization after lifecycle transitions before iterating components
--FILE--
<?php
$source = file_get_contents(__DIR__ . '/../src/integration/system_integration.c');

preg_match(
    '/int king_system_check_all_components_health\\(void\\)\\s*\\{(?P<body>.*?)^\\}/ms',
    (string) $source,
    $matches
);

$body = $matches['body'] ?? '';
$applyPos = strpos($body, 'king_system_apply_all_transitions();');
$guardPos = strpos($body, "if (!king_system_initialized) {\n        return FAILURE;\n    }");
$foreachPos = strpos($body, 'ZEND_HASH_FOREACH_NUM_KEY_PTR(&king_system_components, idx, info)');

var_dump(isset($matches['body']));
var_dump($applyPos !== false);
var_dump($guardPos !== false);
var_dump($foreachPos !== false);
var_dump($applyPos !== false && $guardPos !== false && $applyPos < $guardPos);
var_dump($guardPos !== false && $foreachPos !== false && $guardPos < $foreachPos);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
