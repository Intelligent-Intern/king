/*
 * CUDA bounded logits readback for native King GPU inference.
 */

#define KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES 64u

typedef int king_inference_logits_readback_nvrtc_result;
typedef void *king_inference_logits_readback_nvrtc_program;

typedef king_inference_logits_readback_nvrtc_result (*king_inference_logits_readback_nvrtc_create_program_fn)(
    king_inference_logits_readback_nvrtc_program *program,
    const char *source,
    const char *name,
    int header_count,
    const char * const *headers,
    const char * const *include_names
);
typedef king_inference_logits_readback_nvrtc_result (*king_inference_logits_readback_nvrtc_compile_program_fn)(
    king_inference_logits_readback_nvrtc_program program,
    int option_count,
    const char * const *options
);
typedef king_inference_logits_readback_nvrtc_result (*king_inference_logits_readback_nvrtc_get_ptx_size_fn)(
    king_inference_logits_readback_nvrtc_program program,
    size_t *ptx_size
);
typedef king_inference_logits_readback_nvrtc_result (*king_inference_logits_readback_nvrtc_get_ptx_fn)(
    king_inference_logits_readback_nvrtc_program program,
    char *ptx
);
typedef king_inference_logits_readback_nvrtc_result (*king_inference_logits_readback_nvrtc_destroy_program_fn)(
    king_inference_logits_readback_nvrtc_program *program
);
typedef int (*king_inference_cuda_logits_memcpy_dtoh_fn)(
    void *dst,
    king_inference_cuda_device_ptr src,
    size_t bytes
);

static const char king_inference_cuda_logits_readback_source[] =
"extern \"C\" __global__ void king_logits_top_k(\n"
"    const float *logits,\n"
"    unsigned int vocab_size,\n"
"    unsigned int candidate_limit,\n"
"    unsigned int *candidate_indices,\n"
"    float *candidate_logits\n"
") {\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    unsigned int limit = candidate_limit > 64u ? 64u : candidate_limit;\n"
"    __shared__ float thread_scores[256];\n"
"    __shared__ unsigned int thread_indices[256];\n"
"    __shared__ unsigned int selected_indices[64];\n"
"    if (vocab_size == 0u || limit == 0u) {\n"
"        return;\n"
"    }\n"
"    for (unsigned int rank = 0u; rank < limit; rank++) {\n"
"        float best_score = -3.402823466e+38F;\n"
"        unsigned int best_index = 0xffffffffu;\n"
"        for (unsigned int index = lane_id; index < vocab_size; index += blockDim.x) {\n"
"            bool already_selected = false;\n"
"            for (unsigned int selected = 0u; selected < rank; selected++) {\n"
"                if (selected_indices[selected] == index) {\n"
"                    already_selected = true;\n"
"                    break;\n"
"                }\n"
"            }\n"
"            if (!already_selected) {\n"
"                float score = logits[index];\n"
"                if (score > best_score || (score == best_score && index < best_index)) {\n"
"                    best_score = score;\n"
"                    best_index = index;\n"
"                }\n"
"            }\n"
"        }\n"
"        thread_scores[lane_id] = best_score;\n"
"        thread_indices[lane_id] = best_index;\n"
"        __syncthreads();\n"
"        for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"            if (lane_id < stride) {\n"
"                float right_score = thread_scores[lane_id + stride];\n"
"                unsigned int right_index = thread_indices[lane_id + stride];\n"
"                if (right_score > thread_scores[lane_id]\n"
"                    || (right_score == thread_scores[lane_id] && right_index < thread_indices[lane_id])) {\n"
"                    thread_scores[lane_id] = right_score;\n"
"                    thread_indices[lane_id] = right_index;\n"
"                }\n"
"            }\n"
"            __syncthreads();\n"
"        }\n"
"        if (lane_id == 0u) {\n"
"            selected_indices[rank] = thread_indices[0];\n"
"            candidate_indices[rank] = thread_indices[0];\n"
"            candidate_logits[rank] = thread_scores[0];\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"}\n";

static void king_inference_cuda_logits_readback_reset_fields(king_inference_model_object *model)
{
    model->cuda_logits_readback_nvrtc_handle = NULL;
    model->cuda_logits_readback_module = NULL;
    model->cuda_logits_top_k_function = NULL;
    model->cuda_logits_readback_result = 0;
    model->cuda_logits_readback_error[0] = '\0';
    model->cuda_logits_readback_launch_count = 0;
    model->cuda_logits_readback_sync_count = 0;
    model->cuda_logits_readback_dtoh_count = 0;
    model->cuda_logits_readback_candidate_limit = KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES;
    model->cuda_logits_readback_last_candidate_count = 0;
    model->cuda_logits_readback_last_bytes = 0;
    model->cuda_logits_readback_full_bytes = 0;
    model->cuda_logits_readback_saved_bytes = 0;
    model->cuda_logits_readback_last_ns = 0;
    model->cuda_logits_readback_synthetic_vocab_size = 0;
    model->cuda_logits_readback_synthetic_candidate_count = 0;
    model->cuda_logits_readback_synthetic_matched_count = 0;
    model->cuda_logits_readback_synthetic_rank_matched_count = 0;
    model->cuda_logits_readback_synthetic_token_id_matched_count = 0;
    model->cuda_logits_readback_synthetic_logit_matched_count = 0;
    model->cuda_logits_readback_attempted = false;
    model->cuda_logits_readback_available = false;
    model->cuda_logits_readback_nvrtc_available = false;
    model->cuda_logits_readback_module_loaded = false;
    model->cuda_logits_readback_top_k_available = false;
    model->cuda_logits_readback_bounded_cpu_available = false;
    model->cuda_logits_readback_synthetic_ordering_checked = false;
    model->cuda_logits_readback_synthetic_ordering_matched = false;
}

static uint64_t king_inference_cuda_logits_readback_profile_ns(void)
{
    struct timespec ts;

    if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0) {
        return (uint64_t) time(NULL) * 1000000000ULL;
    }
    return ((uint64_t) ts.tv_sec * 1000000000ULL) + (uint64_t) ts.tv_nsec;
}

static zend_long king_inference_cuda_logits_readback_profile_elapsed(uint64_t end, uint64_t start)
{
    return end >= start && end - start <= (uint64_t) ZEND_LONG_MAX
        ? (zend_long) (end - start)
        : 0;
}

static void king_inference_cuda_logits_readback_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_logits_readback_error";
    }

    model->cuda_logits_readback_result = result;
    snprintf(
        model->cuda_logits_readback_error,
        sizeof(model->cuda_logits_readback_error),
        "%s",
        message
    );
}

static zend_result king_inference_cuda_logits_readback_synthetic_ordering_check(
    king_inference_model_object *model
);

static bool king_inference_cuda_logits_readback_driver_symbol(
    king_inference_model_object *model,
    const char *primary_name,
    const char *fallback_name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_logits_readback_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    if (primary_name != NULL) {
        *symbol = dlsym(model->cuda_driver_handle, primary_name);
    }
    if (*symbol == NULL && fallback_name != NULL) {
        *symbol = dlsym(model->cuda_driver_handle, fallback_name);
    }
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_logits_readback_set_error(
        model,
        -1,
        primary_name != NULL ? primary_name : fallback_name
    );
    return false;
}

static bool king_inference_cuda_logits_readback_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_logits_readback_nvrtc_handle == NULL) {
        king_inference_cuda_logits_readback_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_logits_readback_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_logits_readback_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_logits_readback_open_nvrtc(king_inference_model_object *model)
{
    static const char *libraries[] = {
        "libnvrtc.so.12",
        "libnvrtc.so.11.2",
        "libnvrtc.so.11.0",
        "libnvrtc.so"
    };
    size_t index;

    for (index = 0; index < sizeof(libraries) / sizeof(libraries[0]); index++) {
        model->cuda_logits_readback_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_logits_readback_nvrtc_handle != NULL) {
            model->cuda_logits_readback_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_logits_readback_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_logits_readback_compile_ptx(
    king_inference_model_object *model,
    char **ptx,
    size_t *ptx_size
) {
    king_inference_logits_readback_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_logits_readback_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_logits_readback_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_logits_readback_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_logits_readback_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_logits_readback_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_89", "--use_fast_math"};
    king_inference_logits_readback_nvrtc_result result;

    *ptx = NULL;
    *ptx_size = 0;
    if (!king_inference_cuda_logits_readback_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_logits_readback_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_logits_readback_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_logits_readback_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_logits_readback_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(&program, king_inference_cuda_logits_readback_source, "king_logits_top_k.cu", 0, NULL, NULL);
    if (result != 0 || program == NULL) {
        king_inference_cuda_logits_readback_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtcProgram_unavailable"
        );
        return false;
    }

    result = nvrtc_compile_program(program, 3, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_logits_readback_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }

    result = nvrtc_get_ptx_size(program, ptx_size);
    if (result != 0 || *ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_logits_readback_set_error(
            model,
            result,
            result != 0 ? "nvrtcGetPTXSize_failed" : "nvrtc_ptx_empty"
        );
        return false;
    }

    *ptx = emalloc(*ptx_size);
    result = nvrtc_get_ptx(program, *ptx);
    (void) nvrtc_destroy_program(&program);
    if (result != 0) {
        efree(*ptx);
        *ptx = NULL;
        *ptx_size = 0;
        king_inference_cuda_logits_readback_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_logits_readback_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_logits_readback_module != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_logits_readback_driver_symbol(
            model,
            "cuModuleUnload",
            NULL,
            (void **) &cu_module_unload
        )) {
        (void) cu_module_unload(model->cuda_logits_readback_module);
    }

    if (model->cuda_logits_readback_nvrtc_handle != NULL) {
        dlclose(model->cuda_logits_readback_nvrtc_handle);
    }

    king_inference_cuda_logits_readback_reset_fields(model);
}

static void king_inference_cuda_logits_readback_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_module_load_data_fn cu_module_load_data;
    king_inference_cuda_module_get_function_fn cu_module_get_function;
    char *ptx = NULL;
    size_t ptx_size = 0;
    void *module = NULL;
    void *function = NULL;
    int result;

    king_inference_cuda_logits_readback_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_logits_readback_attempted = true;
    if (!model->cuda_output_projection_available) {
        king_inference_cuda_logits_readback_set_error(model, -1, "cuda_output_projection_dependency_unavailable");
        return;
    }
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_logits_readback_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_logits_readback_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_logits_readback_open_nvrtc(model)
        || !king_inference_cuda_logits_readback_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_logits_readback_driver_symbol(model, "cuModuleLoadData", NULL, (void **) &cu_module_load_data)
        || !king_inference_cuda_logits_readback_driver_symbol(model, "cuModuleGetFunction", NULL, (void **) &cu_module_get_function)) {
        efree(ptx);
        return;
    }

    result = cu_module_load_data(&module, ptx);
    efree(ptx);
    if (result != 0 || module == NULL) {
        king_inference_cuda_logits_readback_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_logits_top_k");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_logits_readback_driver_symbol(model, "cuModuleUnload", NULL, (void **) &cu_module_unload)
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_logits_readback_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_logits_top_k_kernel_unavailable"
        );
        return;
    }

    model->cuda_logits_readback_module = module;
    model->cuda_logits_top_k_function = function;
    model->cuda_logits_readback_module_loaded = true;
    model->cuda_logits_readback_top_k_available = true;
    model->cuda_logits_readback_bounded_cpu_available = true;
    model->cuda_logits_readback_available = true;
    model->cuda_logits_readback_result = 0;
    model->cuda_logits_readback_error[0] = '\0';
    if (king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)
        && king_inference_cuda_logits_readback_synthetic_ordering_check(model) != SUCCESS) {
        model->cuda_logits_readback_available = false;
        model->cuda_logits_readback_top_k_available = false;
        return;
    }
}

static zend_result king_inference_cuda_logits_readback_top_k(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr logits,
    zend_ulong vocab_size,
    zend_ulong requested_candidates,
    unsigned int *host_indices,
    float *host_logits,
    zend_ulong *candidate_count_out
) {
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    king_inference_cuda_ctx_synchronize_fn cu_ctx_synchronize;
    king_inference_cuda_logits_memcpy_dtoh_fn cu_memcpy_dtoh;
    king_inference_cuda_device_ptr device_indices = 0;
    king_inference_cuda_device_ptr device_logits = 0;
    unsigned int vocab32;
    unsigned int count32;
    size_t index_bytes;
    size_t logit_bytes;
    void *params[5];
    uint64_t start_ns;
    uint64_t end_ns;
    int result;

    start_ns = king_inference_cuda_logits_readback_profile_ns();
    if (candidate_count_out == NULL) {
        king_inference_cuda_logits_readback_set_error(model, -1, "cuda_logits_readback_argument_invalid");
        return FAILURE;
    }
    *candidate_count_out = 0;
    if (!model->cuda_logits_readback_available || model->cuda_logits_top_k_function == NULL) {
        king_inference_cuda_logits_readback_set_error(model, -1, "cuda_logits_readback_unavailable");
        return FAILURE;
    }
    if (logits == 0 || host_indices == NULL || host_logits == NULL) {
        king_inference_cuda_logits_readback_set_error(model, -1, "cuda_logits_readback_argument_invalid");
        return FAILURE;
    }
    if (vocab_size == 0 || vocab_size > UINT_MAX || requested_candidates == 0) {
        king_inference_cuda_logits_readback_set_error(model, -1, "cuda_logits_readback_shape_invalid");
        return FAILURE;
    }

    count32 = (unsigned int) requested_candidates;
    if (count32 > KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES) {
        count32 = KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES;
    }
    if ((zend_ulong) count32 > vocab_size) {
        count32 = (unsigned int) vocab_size;
    }
    vocab32 = (unsigned int) vocab_size;
    index_bytes = (size_t) count32 * sizeof(unsigned int);
    logit_bytes = (size_t) count32 * sizeof(float);

    if (king_inference_cuda_device_allocator_alloc(model, index_bytes, &device_indices) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, logit_bytes, &device_logits) != SUCCESS) {
        king_inference_cuda_logits_readback_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_logits_readback_allocation_failed"
        );
        goto failure;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_logits_readback_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        goto failure;
    }
    if (!king_inference_cuda_logits_readback_driver_symbol(model, "cuLaunchKernel", NULL, (void **) &cu_launch_kernel)
        || !king_inference_cuda_logits_readback_driver_symbol(model, "cuCtxSynchronize", NULL, (void **) &cu_ctx_synchronize)
        || !king_inference_cuda_logits_readback_driver_symbol(
            model,
            "cuMemcpyDtoH_v2",
            "cuMemcpyDtoH",
            (void **) &cu_memcpy_dtoh
        )) {
        goto failure;
    }

    params[0] = &logits;
    params[1] = &vocab32;
    params[2] = &count32;
    params[3] = &device_indices;
    params[4] = &device_logits;
    result = cu_launch_kernel(
        model->cuda_logits_top_k_function,
        1,
        1,
        1,
        256,
        1,
        1,
        0,
        NULL,
        params,
        NULL
    );
    if (result != 0) {
        king_inference_cuda_logits_readback_set_error(model, result, "cuLaunchKernel_failed");
        goto failure;
    }

    result = cu_ctx_synchronize();
    if (result != 0) {
        king_inference_cuda_logits_readback_set_error(model, result, "cuCtxSynchronize_failed");
        goto failure;
    }
    model->cuda_logits_readback_sync_count++;

    result = cu_memcpy_dtoh(host_indices, device_indices, index_bytes);
    if (result != 0) {
        king_inference_cuda_logits_readback_set_error(model, result, "cuMemcpyDtoH_indices_failed");
        goto failure;
    }
    model->cuda_logits_readback_dtoh_count++;
    result = cu_memcpy_dtoh(host_logits, device_logits, logit_bytes);
    if (result != 0) {
        king_inference_cuda_logits_readback_set_error(model, result, "cuMemcpyDtoH_logits_failed");
        goto failure;
    }
    model->cuda_logits_readback_dtoh_count++;

    (void) king_inference_cuda_device_allocator_free(model, device_logits);
    (void) king_inference_cuda_device_allocator_free(model, device_indices);
    model->cuda_logits_readback_launch_count++;
    model->cuda_logits_readback_last_candidate_count = count32;
    model->cuda_logits_readback_last_bytes = index_bytes + logit_bytes;
    model->cuda_logits_readback_full_bytes = (size_t) vocab32 * sizeof(float);
    model->cuda_logits_readback_saved_bytes = model->cuda_logits_readback_full_bytes > model->cuda_logits_readback_last_bytes
        ? model->cuda_logits_readback_full_bytes - model->cuda_logits_readback_last_bytes
        : 0;
    end_ns = king_inference_cuda_logits_readback_profile_ns();
    model->cuda_logits_readback_last_ns =
        king_inference_cuda_logits_readback_profile_elapsed(end_ns, start_ns);
    model->cuda_logits_readback_result = 0;
    model->cuda_logits_readback_error[0] = '\0';
    *candidate_count_out = count32;
    return SUCCESS;

failure:
    if (device_logits != 0) {
        (void) king_inference_cuda_device_allocator_free(model, device_logits);
    }
    if (device_indices != 0) {
        (void) king_inference_cuda_device_allocator_free(model, device_indices);
    }
    return FAILURE;
}

#include "cuda_logits_readback_self_check.inc"

static void king_inference_cuda_logits_readback_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval readback;
    zval synthetic_ordering;

    array_init(&readback);
    add_assoc_bool(&readback, "attempted", model->cuda_logits_readback_attempted);
    add_assoc_bool(&readback, "available", model->cuda_logits_readback_available);
    add_assoc_bool(&readback, "nvrtc_available", model->cuda_logits_readback_nvrtc_available);
    add_assoc_bool(&readback, "module_loaded", model->cuda_logits_readback_module_loaded);
    add_assoc_bool(&readback, "top_k_available", model->cuda_logits_readback_top_k_available);
    add_assoc_bool(&readback, "bounded_cpu_available", model->cuda_logits_readback_bounded_cpu_available);
    add_assoc_long(&readback, "max_candidates", (zend_long) model->cuda_logits_readback_candidate_limit);
    add_assoc_long(&readback, "last_candidate_count", (zend_long) model->cuda_logits_readback_last_candidate_count);
    add_assoc_long(&readback, "last_readback_bytes", (zend_long) model->cuda_logits_readback_last_bytes);
    add_assoc_long(&readback, "full_logits_bytes", (zend_long) model->cuda_logits_readback_full_bytes);
    add_assoc_long(&readback, "saved_readback_bytes", (zend_long) model->cuda_logits_readback_saved_bytes);
    add_assoc_long(&readback, "last_readback_ns", model->cuda_logits_readback_last_ns);
    add_assoc_bool(&readback, "full_logits_cpu_readback_avoided", model->cuda_logits_readback_available);
    array_init(&synthetic_ordering);
    add_assoc_bool(
        &synthetic_ordering,
        "enabled",
        king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)
    );
    add_assoc_bool(&synthetic_ordering, "checked", model->cuda_logits_readback_synthetic_ordering_checked);
    add_assoc_bool(&synthetic_ordering, "matched", model->cuda_logits_readback_synthetic_ordering_matched);
    add_assoc_long(&synthetic_ordering, "vocab_size", (zend_long) model->cuda_logits_readback_synthetic_vocab_size);
    add_assoc_long(&synthetic_ordering, "candidate_count", (zend_long) model->cuda_logits_readback_synthetic_candidate_count);
    add_assoc_long(&synthetic_ordering, "matched_count", (zend_long) model->cuda_logits_readback_synthetic_matched_count);
    add_assoc_long(&synthetic_ordering, "rank_matched_count", (zend_long) model->cuda_logits_readback_synthetic_rank_matched_count);
    add_assoc_long(&synthetic_ordering, "token_id_matched_count", (zend_long) model->cuda_logits_readback_synthetic_token_id_matched_count);
    add_assoc_long(&synthetic_ordering, "logit_matched_count", (zend_long) model->cuda_logits_readback_synthetic_logit_matched_count);
    add_assoc_zval(&readback, "synthetic_ordering", &synthetic_ordering);
    add_assoc_long(&readback, "launches", (zend_long) model->cuda_logits_readback_launch_count);
    add_assoc_long(&readback, "result", model->cuda_logits_readback_result);
    add_assoc_string(&readback, "error", model->cuda_logits_readback_error);
    add_assoc_zval(return_value, "logits_readback", &readback);
}

static void king_inference_cuda_logits_readback_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_readback;

    king_inference_cuda_logits_readback_add_status(model, return_value);
    if (!model->cuda_logits_readback_attempted
        || model->cuda_logits_readback_available
        || !model->cuda_context_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_readback = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_readback) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_logits_readback_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_logits_readback_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_logits_readback_unavailable");
    }
}
