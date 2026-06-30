/*
 * CUDA decoder graph executor include stack.
 * Order matters:
 * 1. contract helpers define errors, supported ops, tensor descriptors, and field validators.
 * 2. op validation defines every per-op graph contract used by validation and planning.
 * 3. graph validation walks inputs, ops, terminal references, and stores graph counters.
 * 4. device ops require the validated graph helpers before result preparation can run.
 * 5. result/lifecycle/status helpers expose the validated execution envelope.
 */

#include "cuda_decoder_graph_executor/contract_helpers.inc"
#include "cuda_decoder_graph_executor/op_validation.inc"
#include "cuda_decoder_graph_executor/graph_validation.inc"
#include "../device/core/cuda_decoder_graph_executor_device_ops.inc"
#include "cuda_decoder_graph_executor/result_lifecycle.inc"
