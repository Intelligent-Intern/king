/*
 * Inference PHP binding aggregation.
 *
 * Keep leaf implementation fragments in this module boundary. The owning
 * inference.c entrypoint stays focused on module state, binding, and
 * registration ownership.
 */

#include "bootstrap/platform_headers.inc"
#include "bootstrap/forward_declarations.inc"
#include "stacks/request/request_parsing_stack.inc"
#include "stacks/runtime/runtime_foundation_stack.inc"
#include "stacks/model/model_tensor_stack.inc"
#include "stacks/cuda/cuda_decoder_stack.inc"
#include "stacks/stream/stream_backend_stack.inc"
#include "stacks/openai/openai_router_stack.inc"
#include "stacks/api/api_surface_stack.inc"
