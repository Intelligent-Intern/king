--TEST--
King procedural API exposes stable arginfo and type validation contracts
--FILE--
<?php
function describe_arginfo(string $function): array
{
    $reflection = new ReflectionFunction($function);
    $parameters = [];

    foreach ($reflection->getParameters() as $parameter) {
        $type = $parameter->getType();

        $parameters[] = [
            'name' => $parameter->getName(),
            'type' => $type ? $type->getName() : null,
            'allowsNull' => $type ? $type->allowsNull() : null,
            'optional' => $parameter->isOptional(),
            'default' => $parameter->isDefaultValueAvailable()
                ? var_export($parameter->getDefaultValue(), true)
                : null,
        ];
    }

    return [
        'required' => $reflection->getNumberOfRequiredParameters(),
        'total' => $reflection->getNumberOfParameters(),
        'parameters' => $parameters,
    ];
}

var_export([
    'king_receive_response' => describe_arginfo('king_receive_response'),
    'king_http3_request_send' => describe_arginfo('king_http3_request_send'),
    'king_client_websocket_connect' => describe_arginfo('king_client_websocket_connect'),
    'king_http1_server_listen' => describe_arginfo('king_http1_server_listen'),
    'king_server_on_cancel' => describe_arginfo('king_server_on_cancel'),
    'king_mcp_request' => describe_arginfo('king_mcp_request'),
    'king_pipeline_orchestrator_run' => describe_arginfo('king_pipeline_orchestrator_run'),
    'king_telemetry_record_metric' => describe_arginfo('king_telemetry_record_metric'),
    'king_autoscaling_scale_up' => describe_arginfo('king_autoscaling_scale_up'),
    'king_system_shutdown' => describe_arginfo('king_system_shutdown'),
]);
echo "\n";

$typeErrors = [];

foreach ([
    'king_http3_request_send' => static fn() => king_http3_request_send(),
    'king_client_early_hints_process' => static fn() => king_client_early_hints_process(fopen('php://memory', 'r'), 'not-an-array'),
    'king_telemetry_init' => static fn() => king_telemetry_init('not-an-array'),
    'king_autoscaling_scale_up' => static fn() => king_autoscaling_scale_up('two'),
    'king_system_restart_component' => static fn() => king_system_restart_component(['client']),
] as $function => $callable) {
    try {
        $callable();
        $typeErrors[$function] = 'no-exception';
    } catch (Throwable $e) {
        $typeErrors[$function] = get_class($e);
    }
}

var_export($typeErrors);
echo "\n";
?>
--EXPECT--
array (
  'king_receive_response' => 
  array (
    'required' => 1,
    'total' => 1,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'request_context',
        'type' => 'mixed',
        'allowsNull' => true,
        'optional' => false,
        'default' => NULL,
      ),
    ),
  ),
  'king_http3_request_send' => 
  array (
    'required' => 1,
    'total' => 5,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'url',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      1 => 
      array (
        'name' => 'method',
        'type' => 'string',
        'allowsNull' => true,
        'optional' => true,
        'default' => '\'GET\'',
      ),
      2 => 
      array (
        'name' => 'headers',
        'type' => 'array',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
      3 => 
      array (
        'name' => 'body',
        'type' => 'mixed',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
      4 => 
      array (
        'name' => 'options',
        'type' => 'array',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
    ),
  ),
  'king_client_websocket_connect' => 
  array (
    'required' => 1,
    'total' => 3,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'url',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      1 => 
      array (
        'name' => 'headers',
        'type' => 'array',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
      2 => 
      array (
        'name' => 'options',
        'type' => 'array',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
    ),
  ),
  'king_http1_server_listen' => 
  array (
    'required' => 4,
    'total' => 4,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'host',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      1 => 
      array (
        'name' => 'port',
        'type' => 'int',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      2 => 
      array (
        'name' => 'config',
        'type' => 'mixed',
        'allowsNull' => true,
        'optional' => false,
        'default' => NULL,
      ),
      3 => 
      array (
        'name' => 'handler',
        'type' => 'callable',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
    ),
  ),
  'king_server_on_cancel' => 
  array (
    'required' => 3,
    'total' => 3,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'session',
        'type' => 'mixed',
        'allowsNull' => true,
        'optional' => false,
        'default' => NULL,
      ),
      1 => 
      array (
        'name' => 'stream_id',
        'type' => 'int',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      2 => 
      array (
        'name' => 'handler',
        'type' => 'callable',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
    ),
  ),
  'king_mcp_request' => 
  array (
    'required' => 4,
    'total' => 5,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'connection',
        'type' => 'mixed',
        'allowsNull' => true,
        'optional' => false,
        'default' => NULL,
      ),
      1 => 
      array (
        'name' => 'service_name',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      2 => 
      array (
        'name' => 'method_name',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      3 => 
      array (
        'name' => 'request_payload',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      4 => 
      array (
        'name' => 'options',
        'type' => 'array',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
    ),
  ),
  'king_pipeline_orchestrator_run' => 
  array (
    'required' => 2,
    'total' => 3,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'initial_data',
        'type' => 'mixed',
        'allowsNull' => true,
        'optional' => false,
        'default' => NULL,
      ),
      1 => 
      array (
        'name' => 'pipeline',
        'type' => 'array',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      2 => 
      array (
        'name' => 'exec_options',
        'type' => 'array',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
    ),
  ),
  'king_telemetry_record_metric' => 
  array (
    'required' => 2,
    'total' => 4,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'metric_name',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      1 => 
      array (
        'name' => 'value',
        'type' => 'float',
        'allowsNull' => false,
        'optional' => false,
        'default' => NULL,
      ),
      2 => 
      array (
        'name' => 'labels',
        'type' => 'array',
        'allowsNull' => true,
        'optional' => true,
        'default' => 'NULL',
      ),
      3 => 
      array (
        'name' => 'metric_type',
        'type' => 'string',
        'allowsNull' => false,
        'optional' => true,
        'default' => '\'gauge\'',
      ),
    ),
  ),
  'king_autoscaling_scale_up' => 
  array (
    'required' => 0,
    'total' => 1,
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'instances',
        'type' => 'int',
        'allowsNull' => false,
        'optional' => true,
        'default' => '1',
      ),
    ),
  ),
  'king_system_shutdown' => 
  array (
    'required' => 0,
    'total' => 0,
    'parameters' => 
    array (
    ),
  ),
)
array (
  'king_http3_request_send' => 'ArgumentCountError',
  'king_client_early_hints_process' => 'TypeError',
  'king_telemetry_init' => 'TypeError',
  'king_autoscaling_scale_up' => 'TypeError',
  'king_system_restart_component' => 'TypeError',
)
