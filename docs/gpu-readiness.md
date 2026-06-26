# GPU Readiness Runbook

This page is the operator-facing preflight for a local King GPU inference
deployment. It is intentionally about runtime admission, not model quality.
The goal is to answer four questions before traffic is sent to the router:

- Is the intended GPU model profile selected?
- Can King see the CUDA driver, artifact, VRAM budget, and thermal monitor?
- Is plain-text generation currently admitted?
- If it is not admitted, what exact refusal reasons must be fixed?

For production-like local operation, pin the runtime profile to `gpu`. `auto`
is useful on developer machines where the CPU profile may be acceptable when no
GPU artifact is configured. A pinned `gpu` profile fails closed instead of
falling back to CPU.

## php.ini Profile

Use a dedicated PHP ini fragment for the local inference process. The router
and any operator preflight script should start with the same fragment, so the
status probe and the serving process see the same process-level settings.

```ini
extension=/opt/king/extension/modules/king.so

king.gpu_bindings_enable=1
king.gpu_default_backend=cuda

king.inference_preferred_model_profile=gpu
king.inference_cpu_model_name=gemma3:1b
king.inference_cpu_model_artifact=/var/lib/king/models/gemma3-1b.gguf
king.inference_gpu_model_name=gemma4:12b
king.inference_gpu_model_artifact=/var/lib/king/models/gemma4-12b.gguf

king.inference_gpu_max_gpu_layers=99
king.inference_gpu_vram_reserve_mb=2048
king.inference_gpu_min_free_vram_mb=4096

king.inference_gpu_thermal_sensor_path=
king.inference_gpu_thermal_sensor_command=nvidia-smi --query-gpu=temperature.gpu --format=csv,noheader,nounits
king.inference_gpu_thermal_max_temperature_c=78
king.inference_gpu_allow_unmonitored=0

king.inference_with_memory=0
king.inference_llm_cache_enable=0
```

Keep the CPU model configured even when the process is pinned to `gpu`. It
keeps the same config usable for explicit CPU preflights and for environments
that intentionally switch to `auto`. It must not be used as an implicit
fallback for a request that selected the GPU profile.

## Sensor Check

Before starting King, verify that the configured thermal source returns one
numeric Celsius value. This command is the common NVIDIA path:

```bash
nvidia-smi --query-gpu=name,memory.free,temperature.gpu --format=csv,noheader,nounits
```

For a sysfs sensor path, the file normally returns millidegrees Celsius:

```bash
cat /sys/class/hwmon/hwmon0/temp1_input
```

If no thermal source is configured and `king.inference_gpu_allow_unmonitored=0`,
King refuses GPU inference. Setting `king.inference_gpu_allow_unmonitored=1` is
an explicit operator decision and should not be the default on a workstation.

## Preflight Command

`bin/king-inference-status` is safe to run before starting the local router. It
does not send a generation request first. It checks the selected runtime
profile, the raw GPU readiness payload, the loaded model metadata, and the
router-facing model listing flags.

The command loads the same PHP ini fragment as `bin/king-openai-router` by
default:

```bash
bin/king-inference-status --require-gpu
```

Use `--json` when a supervisor, deployment script, or health gate needs the
machine-readable report:

```bash
bin/king-inference-status --require-gpu --json
```

Override the PHP binary, extension path, or ini fragment through the same
environment variables as the router:

```bash
PHP_BIN=/usr/bin/php8.4 \
KING_EXTENSION=/opt/king/extension/modules/king.so \
KING_INFERENCE_PHP_INI=/opt/king/infra/inference/local-gpu.php.ini \
bin/king-inference-status --require-gpu
```

Exit code `0` means the selected model can serve local OpenAI-compatible chat.
Exit code `2` means `--require-gpu` was set but the active config did not select
`king_native_gpu`. Exit code `3` means the selected profile loaded but King
refused generation for concrete runtime reasons. Exit code `1` means the status
command itself hit a config or runtime error before readiness could be
evaluated.

## Interpreting Readiness

`king_inference_gpu_runtime_status()` and `King\Inference::gpuRuntimeStatus()`
return the pre-load process and config view. The loaded model's `gpu_runtime`
payload adds artifact and KV-cache based admission details.

Important fields:

- `config_ready`: King can see the GPU-facing configuration, artifact, driver
  signal, VRAM policy, and thermal policy.
- `model_vram_admitted`: the configured model artifact passes the pre-load
  VRAM admission check.
- `runtime_vram_fits_free`: the loaded model plus estimated KV-cache fits after
  the configured reserve.
- `decoder_kernel_ready`: the native GPU decoder graph and prompt loop are
  ready.
- `generation_ready`: runtime policy and decoder readiness both admit
  plain-text generation.
- `reason`: the first operator-facing blocker.
- `refusal_reasons`: every currently active blocker in ordered form.
- `silent_cpu_fallback`: must remain `false` for GPU profiles.

The OpenAI router repeats the executable contract under
`x_king.client_capabilities`. UI and editor integrations should use
`gpu_generation_ready`, `requires_gpu`, `gpu_runtime_required`, and
`openai_chat_completions` from that object instead of guessing from the model
name.

## Common Refusal Reasons

`gpu_thermal_monitor_missing` means no sensor path or command is configured and
unmonitored GPU use is not allowed. Configure a sensor or explicitly accept
unmonitored operation.

`gpu_free_vram_below_configured_floor` means the driver reports less free VRAM
than `king.inference_gpu_min_free_vram_mb`. Close other GPU consumers or lower
the floor intentionally.

`gpu_required_vram_exceeds_free_vram_after_reserve` means the model plus
estimated KV cache does not fit after `king.inference_gpu_vram_reserve_mb` is
subtracted. Use a smaller model, lower context requirements, reduce GPU layers
only if the selected backend supports that mode, or adjust the reserve.

`gpu_cuda_context_unavailable`, `gpu_device_memory_allocator_unavailable`, and
`gpu_required_weight_upload_incomplete` are runtime blockers after King sees the
driver. They normally indicate driver/library access, CUDA symbol resolution,
or artifact tensor resolution problems.

## Startup Gate

The local router can start before `generation_ready=true` if operators want
`GET /v1/models` to expose the current refusal details. Do not mark the route
healthy for user traffic until the preflight script exits `0`.

For a hard serving gate, run the preflight first and start the router only when
it succeeds:

```bash
bin/king-inference-status --require-gpu
exec bin/king-openai-router
```

This preserves the King contract: a selected GPU profile either runs on the GPU
or fails with explicit runtime reasons. It does not silently burn CPU for a
large GPU model.

## Router Log States

`bin/king-openai-router` writes structured readiness lines to STDERR:

- `state=configured` is emitted once after environment variables and PHP ini
  values have been resolved. It reports host, port, selected profile, backend,
  CPU/GPU artifact paths, GPU layers, VRAM floors, and the thermal source.
- `state=admitted` is emitted for every registered model after model load and
  metadata inspection. CPU lines report generation readiness. GPU lines include
  `config_ready`, `generation_ready`, `reason`, and ordered refusal reasons.
  If a readable GPU artifact exists but GPU bindings or layers are disabled,
  the router emits `admitted=no` with a concrete reason.
- `state=executing` is emitted for every incoming request immediately before
  it is routed through `king_inference_openai_http_response()`. The line reports
  method, path, requested model, and registered model count. Prompt, message,
  and response content are intentionally not logged.
