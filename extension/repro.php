<?php
require 'tests/orchestrator_failover_harness.inc';
$h = king_orchestrator_failover_harness_create();
$c = king_orchestrator_failover_harness_write_script($h, 'ctrl', '<?php
king_telemetry_init(["otel_exporter_endpoint"=>"http://127.0.0.1:40000", "exporter_timeout_ms" => 500]);
king_pipeline_orchestrator_register_tool("summarizer", ["model"=>"s"]);
king_pipeline_orchestrator_register_handler("summarizer", function(){sleep(5); return ["output"=>[]];});
king_telemetry_start_span("orchestrator-process-parent", ["boundary" => "process_resume", "role" => "controller_parent"]);
file_put_contents("' . $h['root'] . '/span.json", json_encode(king_telemetry_get_trace_context(), JSON_UNESCAPED_SLASHES));
king_pipeline_orchestrator_run(["text"=>"test"],[["tool"=>"summarizer","delay_ms"=>5000]],[]);
');
$p = king_orchestrator_failover_harness_spawn($h, 'local', $c, [], ['king.orchestrator_enable_distributed_tracing' => '1']);
// Wait for state to be written (if any)
for ($i=0; $i<40; $i++) { if(is_file($h['root'].'/span.json')) break; usleep(100000); }
king_orchestrator_failover_harness_crash_process($p);
$r = king_orchestrator_failover_harness_write_script($h, 'resume', '<?php
king_telemetry_init(["otel_exporter_endpoint"=>"http://127.0.0.1:40000", "exporter_timeout_ms" => 500]);
king_pipeline_orchestrator_register_tool("summarizer", ["model"=>"s"]);
king_pipeline_orchestrator_register_handler("summarizer", function(){return ["output"=>[]];});
$beforeContext = king_telemetry_get_trace_context();
$result = king_pipeline_orchestrator_resume_run("run-1");
$afterRunContext = king_telemetry_get_trace_context();
$flushResult = king_telemetry_flush();
');
echo "State path is {$h['root']}/state.json\n";
$cmd = "gdb --batch -ex r -ex bt --args php8.4 -n -d extension=modules/king.so -d king.security_allow_config_override=1 -d king.orchestrator_execution_backend=local -d king.orchestrator_state_path={$h['root']}/state.json -d king.orchestrator_enable_distributed_tracing=1 $r";
passthru($cmd);
king_orchestrator_failover_harness_destroy($h);
