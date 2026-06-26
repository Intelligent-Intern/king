/*
 * Inference PHP binding aggregation.
 *
 * Keep leaf implementation fragments in this module boundary. The owning
 * inference.c entrypoint stays focused on module state, binding, and
 * registration ownership.
 */

#include "Zend/zend_smart_str.h"
#include "ext/standard/base64.h"
#include "ext/json/php_json.h"
#include "config/config.h"
#include "config/high_perf_compute_and_ai/base_layer.h"
#include "config/resource_helpers.h"

#include <errno.h>
#include <dlfcn.h>
#include <fcntl.h>
#include <limits.h>
#include <math.h>
#include <signal.h>
#include <stdio.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/mman.h>
#include <sys/select.h>
#include <sys/stat.h>
#include <sys/statvfs.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

static zend_result king_inference_llm_cache_prepare_stream(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph_options,
    bool with_memory,
    const char *function_name);

#include "helpers.inc"
#include "openai_messages.inc"
#include "openai_http_helpers.inc"
#include "openai_tools.inc"
#include "openai_http_request.inc"
#include "openai_http_body.inc"
#include "backend_contract.inc"
#include "thermal_policy.inc"
#include "resource_policy.inc"
#include "gpu_vram_policy.inc"
#include "gpu_runtime_reason.inc"
#include "cuda_context.inc"
#include "cuda_device_memory.inc"
#include "gpu_runtime_status.inc"
#include "gguf_loader.inc"
#include "native_memory.inc"
#include "tensor_view.inc"
#include "tensor_math.inc"
#include "tensor_resolver.inc"
#include "tensor_attention_resolver.inc"
#include "tensor_rms_norm_resolver.inc"
#include "tensor_ffn_resolver.inc"
#include "cuda_weight_upload.inc"
#include "cuda_quantized_matvec.inc"
#include "token_decode_graph_builder.inc"
#include "tensor_graph.inc"
#include "paged_kv_cache.inc"
#include "tokenizer.inc"
#include "openai_options.inc"
#include "backend_king_local.inc"
#include "backend_king_native.inc"
#include "backend_registry.inc"
#include "model_config.inc"
#include "runtime_profile.inc"
#include "llm_cache_policy.inc"
#include "openai_usage.inc"
#include "stream_events.inc"
#include "openai_completion_payload.inc"
#include "object_handlers.inc"
#include "openai_compat.inc"
#include "openai_responses.inc"
#include "openai_completions.inc"
#include "openai_embeddings.inc"
#include "openai_models.inc"
#include "openai_http_router.inc"
#include "api.inc"
#include "inference/class_method_entries.h"
