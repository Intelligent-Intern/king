--TEST--
King pipeline orchestrator keeps cancel option ownership stable across persisted option sanitizing
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function summarizer_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    return $input;
}

var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));
var_dump(king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler'));

$initial = ['text' => 'stable'];
$pipeline = [['tool' => 'summarizer']];
$token = new King\CancelToken();
$options = [
    'cancel' => $token,
    'timeout_ms' => 50,
];

$result = king_pipeline_orchestrator_run($initial, $pipeline, $options);
var_dump($result === $initial);
var_dump(array_key_exists('cancel', $options));
var_dump($options['cancel'] === $token);
var_dump($options['cancel']->isCancelled());
var_dump(array_key_exists('timeout_ms', $options));
var_dump($options['timeout_ms']);

$token->cancel();
var_dump($options['cancel']->isCancelled());

try {
    king_pipeline_orchestrator_run($initial, $pipeline, $options);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
int(50)
bool(true)
string(21) "King\RuntimeException"
string(%d) "king_pipeline_orchestrator_run() cancelled the active orchestrator run via CancelToken."
