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
#include <fcntl.h>
#include <math.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <strings.h>
#include <sys/mman.h>
#include <sys/select.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#include "helpers.inc"
#include "openai_messages.inc"
#include "openai_http_helpers.inc"
#include "openai_http_request.inc"
#include "openai_http_body.inc"
#include "backend_contract.inc"
#include "thermal_policy.inc"
#include "resource_policy.inc"
#include "gguf_loader.inc"
#include "native_memory.inc"
#include "tensor_view.inc"
#include "tensor_math.inc"
#include "tensor_graph.inc"
#include "paged_kv_cache.inc"
#include "tokenizer.inc"
#include "openai_options.inc"
#include "backend_king_local.inc"
#include "backend_king_native.inc"
#include "backend_registry.inc"
#include "model_config.inc"
#include "runtime_profile.inc"
#include "openai_usage.inc"
#include "stream_events.inc"
#include "object_handlers.inc"
#include "openai_compat.inc"
#include "openai_responses.inc"
#include "openai_completions.inc"
#include "openai_embeddings.inc"
#include "openai_models.inc"
#include "openai_http_router.inc"
#include "api.inc"
#include "inference/class_method_entries.h"
