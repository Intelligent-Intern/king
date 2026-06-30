/*
 * CUDA attention softmax kernel for native King GPU inference.
 */

typedef int king_inference_attention_softmax_nvrtc_result;
typedef void *king_inference_attention_softmax_nvrtc_program;

typedef king_inference_attention_softmax_nvrtc_result (*king_inference_attention_softmax_nvrtc_create_program_fn)(
    king_inference_attention_softmax_nvrtc_program *program,
    const char *source,
    const char *name,
    int header_count,
    const char * const *headers,
    const char * const *include_names
);
typedef king_inference_attention_softmax_nvrtc_result (*king_inference_attention_softmax_nvrtc_compile_program_fn)(
    king_inference_attention_softmax_nvrtc_program program,
    int option_count,
    const char * const *options
);
typedef king_inference_attention_softmax_nvrtc_result (*king_inference_attention_softmax_nvrtc_get_ptx_size_fn)(
    king_inference_attention_softmax_nvrtc_program program,
    size_t *ptx_size
);
typedef king_inference_attention_softmax_nvrtc_result (*king_inference_attention_softmax_nvrtc_get_ptx_fn)(
    king_inference_attention_softmax_nvrtc_program program,
    char *ptx
);
typedef king_inference_attention_softmax_nvrtc_result (*king_inference_attention_softmax_nvrtc_destroy_program_fn)(
    king_inference_attention_softmax_nvrtc_program *program
);

static const char king_inference_cuda_attention_softmax_source[] =
"extern \"C\" __global__ void king_attention_softmax(\n"
"    const float *scores,\n"
"    const float *mask,\n"
"    float *probabilities,\n"
"    float *sum_out,\n"
"    unsigned int length,\n"
"    float scale,\n"
"    float temperature,\n"
"    unsigned int mask_enabled\n"
") {\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    __shared__ float partials[256];\n"
"    float local_max = -3.4028234663852886e38f;\n"
"    for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"        if (mask_enabled != 0u && mask[i] <= 0.0f) {\n"
"            continue;\n"
"        }\n"
"        float value = scores[i] * scale / temperature;\n"
"        local_max = fmaxf(local_max, value);\n"
"    }\n"
"    partials[lane_id] = local_max;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] = fmaxf(partials[lane_id], partials[lane_id + stride]);\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    float max_score = partials[0];\n"
"    float local_sum = 0.0f;\n"
"    for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"        if (mask_enabled != 0u && mask[i] <= 0.0f) {\n"
"            probabilities[i] = 0.0f;\n"
"            continue;\n"
"        }\n"
"        if (max_score <= -3.4028234663852886e38f) {\n"
"            probabilities[i] = 0.0f;\n"
"            continue;\n"
"        }\n"
"        float value = expf((scores[i] * scale / temperature) - max_score);\n"
"        probabilities[i] = value;\n"
"        local_sum += value;\n"
"    }\n"
"    partials[lane_id] = local_sum;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] += partials[lane_id + stride];\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    float sum = partials[0];\n"
"    if (lane_id == 0u && sum_out != 0) {\n"
"        sum_out[0] = sum;\n"
"    }\n"
"    if (sum <= 0.0f) {\n"
"        for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"            probabilities[i] = 0.0f;\n"
"        }\n"
"        return;\n"
"    }\n"
"    for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"        probabilities[i] /= sum;\n"
"    }\n"
"}\n"
"extern \"C\" __global__ void king_attention_softmax_batch(\n"
"    const float *scores,\n"
"    float *probabilities,\n"
"    float *sum_out,\n"
"    unsigned int position_start,\n"
"    unsigned int max_slots,\n"
"    unsigned int batch_count,\n"
"    unsigned int sliding_window,\n"
"    float scale,\n"
"    float temperature\n"
") {\n"
"    unsigned int query_index = blockIdx.x;\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    __shared__ float partials[256];\n"
"    if (query_index >= batch_count) {\n"
"        return;\n"
"    }\n"
"    unsigned int absolute_position = position_start + query_index;\n"
"    unsigned int slot_start = 0u;\n"
"    if (sliding_window > 0u && absolute_position >= sliding_window) {\n"
"        slot_start = absolute_position - sliding_window + 1u;\n"
"    }\n"
"    unsigned int slot_count = absolute_position - slot_start + 1u;\n"
"    const float *row_scores = scores + ((unsigned long long) query_index * max_slots);\n"
"    float *row_probabilities = probabilities + ((unsigned long long) query_index * max_slots);\n"
"    float local_max = -3.4028234663852886e38f;\n"
"    for (unsigned int i = lane_id; i < slot_count; i += blockDim.x) {\n"
"        float value = row_scores[i] * scale / temperature;\n"
"        local_max = fmaxf(local_max, value);\n"
"    }\n"
"    partials[lane_id] = local_max;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] = fmaxf(partials[lane_id], partials[lane_id + stride]);\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    float max_score = partials[0];\n"
"    float local_sum = 0.0f;\n"
"    for (unsigned int i = lane_id; i < slot_count; i += blockDim.x) {\n"
"        if (max_score <= -3.4028234663852886e38f) {\n"
"            row_probabilities[i] = 0.0f;\n"
"            continue;\n"
"        }\n"
"        float value = expf((row_scores[i] * scale / temperature) - max_score);\n"
"        row_probabilities[i] = value;\n"
"        local_sum += value;\n"
"    }\n"
"    partials[lane_id] = local_sum;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] += partials[lane_id + stride];\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    float sum = partials[0];\n"
"    if (lane_id == 0u && sum_out != 0) {\n"
"        sum_out[query_index] = sum;\n"
"    }\n"
"    if (sum <= 0.0f) {\n"
"        for (unsigned int i = lane_id; i < max_slots; i += blockDim.x) {\n"
"            row_probabilities[i] = 0.0f;\n"
"        }\n"
"        return;\n"
"    }\n"
"    for (unsigned int i = lane_id; i < slot_count; i += blockDim.x) {\n"
"        row_probabilities[i] /= sum;\n"
"    }\n"
"    for (unsigned int i = slot_count + lane_id; i < max_slots; i += blockDim.x) {\n"
"        row_probabilities[i] = 0.0f;\n"
"    }\n"
"}\n";

static void king_inference_cuda_attention_softmax_reset_fields(king_inference_model_object *model)
{
    model->cuda_attention_softmax_nvrtc_handle = NULL;
    model->cuda_attention_softmax_module = NULL;
    model->cuda_attention_softmax_function = NULL;
    model->cuda_attention_softmax_batch_function = NULL;
    model->cuda_attention_softmax_result = 0;
    model->cuda_attention_softmax_error[0] = '\0';
    model->cuda_attention_softmax_launch_count = 0;
    model->cuda_attention_softmax_batch_launch_count = 0;
    model->cuda_attention_softmax_attempted = false;
    model->cuda_attention_softmax_available = false;
    model->cuda_attention_softmax_nvrtc_available = false;
    model->cuda_attention_softmax_module_loaded = false;
    model->cuda_attention_softmax_f32_available = false;
    model->cuda_attention_softmax_batch_available = false;
}

static void king_inference_cuda_attention_softmax_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_attention_softmax_error";
    }

    model->cuda_attention_softmax_result = result;
    snprintf(
        model->cuda_attention_softmax_error,
        sizeof(model->cuda_attention_softmax_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_attention_softmax_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_attention_softmax_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_attention_softmax_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_attention_softmax_nvrtc_handle == NULL) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_attention_softmax_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_attention_softmax_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_attention_softmax_open_nvrtc(king_inference_model_object *model)
{
    static const char *libraries[] = {
        "libnvrtc.so.12",
        "libnvrtc.so.11.2",
        "libnvrtc.so.11.0",
        "libnvrtc.so"
    };
    size_t index;

    for (index = 0; index < sizeof(libraries) / sizeof(libraries[0]); index++) {
        model->cuda_attention_softmax_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_attention_softmax_nvrtc_handle != NULL) {
            model->cuda_attention_softmax_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_attention_softmax_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_attention_softmax_compile_ptx(
    king_inference_model_object *model,
    char **ptx,
    size_t *ptx_size
) {
    king_inference_attention_softmax_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_attention_softmax_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_attention_softmax_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_attention_softmax_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_attention_softmax_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_attention_softmax_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_89", "--use_fast_math"};
    king_inference_attention_softmax_nvrtc_result result;

    *ptx = NULL;
    *ptx_size = 0;

    if (!king_inference_cuda_attention_softmax_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_attention_softmax_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_attention_softmax_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_attention_softmax_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_attention_softmax_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_attention_softmax_source,
        "king_attention_softmax.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_attention_softmax_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtcProgram_unavailable"
        );
        return false;
    }

    result = nvrtc_compile_program(program, 3, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_attention_softmax_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }

    result = nvrtc_get_ptx_size(program, ptx_size);
    if (result != 0 || *ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_attention_softmax_set_error(
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
        king_inference_cuda_attention_softmax_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_attention_softmax_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_attention_softmax_module != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_attention_softmax_driver_symbol(
            model,
            "cuModuleUnload",
            (void **) &cu_module_unload
        )) {
        (void) cu_module_unload(model->cuda_attention_softmax_module);
    }

    if (model->cuda_attention_softmax_nvrtc_handle != NULL) {
        dlclose(model->cuda_attention_softmax_nvrtc_handle);
    }

    king_inference_cuda_attention_softmax_reset_fields(model);
}

static void king_inference_cuda_attention_softmax_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_module_load_data_fn cu_module_load_data;
    king_inference_cuda_module_get_function_fn cu_module_get_function;
    char *ptx = NULL;
    size_t ptx_size = 0;
    void *module = NULL;
    void *function = NULL;
    void *batch_function = NULL;
    int result;

    king_inference_cuda_attention_softmax_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_attention_softmax_attempted = true;
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_attention_softmax_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_attention_softmax_open_nvrtc(model)
        || !king_inference_cuda_attention_softmax_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_attention_softmax_driver_symbol(
            model,
            "cuModuleLoadData",
            (void **) &cu_module_load_data
        )
        || !king_inference_cuda_attention_softmax_driver_symbol(
            model,
            "cuModuleGetFunction",
            (void **) &cu_module_get_function
        )) {
        efree(ptx);
        return;
    }

    result = cu_module_load_data(&module, ptx);
    efree(ptx);
    if (result != 0 || module == NULL) {
        king_inference_cuda_attention_softmax_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_attention_softmax");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_attention_softmax_driver_symbol(
                model,
                "cuModuleUnload",
                (void **) &cu_module_unload
            )
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_attention_softmax_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_attention_softmax_kernel_unavailable"
        );
        return;
    }
    result = cu_module_get_function(&batch_function, module, "king_attention_softmax_batch");
    if (result != 0 || batch_function == NULL) {
        king_inference_cuda_attention_softmax_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_batch_failed" : "cuda_attention_softmax_batch_kernel_unavailable"
        );
        batch_function = NULL;
    }

    model->cuda_attention_softmax_module = module;
    model->cuda_attention_softmax_function = function;
    model->cuda_attention_softmax_batch_function = batch_function;
    model->cuda_attention_softmax_module_loaded = true;
    model->cuda_attention_softmax_f32_available = true;
    model->cuda_attention_softmax_batch_available = batch_function != NULL;
    model->cuda_attention_softmax_available = true;
    if (batch_function != NULL) {
        model->cuda_attention_softmax_result = 0;
        model->cuda_attention_softmax_error[0] = '\0';
    }
}

static zend_result king_inference_cuda_attention_softmax_f32(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input_scores,
    king_inference_cuda_device_ptr input_mask,
    king_inference_cuda_device_ptr output_probabilities,
    king_inference_cuda_device_ptr output_sum,
    zend_ulong length,
    double scale,
    double temperature
) {
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    unsigned int length32;
    unsigned int mask_enabled32;
    unsigned int block_x = 256;
    float scale32 = (float) scale;
    float temperature32 = (float) temperature;
    void *params[8];
    int result;

    if (!model->cuda_attention_softmax_available || model->cuda_attention_softmax_function == NULL) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_attention_softmax_unavailable");
        return FAILURE;
    }
    if (input_scores == 0 || output_probabilities == 0) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_attention_softmax_argument_invalid");
        return FAILURE;
    }
    if (length == 0
        || length > UINT_MAX
        || !isfinite(scale)
        || !isfinite(temperature)
        || temperature <= 0.0) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_attention_softmax_shape_invalid");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_attention_softmax_driver_symbol(
            model,
            "cuLaunchKernel",
            (void **) &cached_cu_launch_kernel
        )) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    length32 = (unsigned int) length;
    mask_enabled32 = input_mask != 0 ? 1u : 0u;
    params[0] = &input_scores;
    params[1] = &input_mask;
    params[2] = &output_probabilities;
    params[3] = &output_sum;
    params[4] = &length32;
    params[5] = &scale32;
    params[6] = &temperature32;
    params[7] = &mask_enabled32;

    result = cu_launch_kernel(
        model->cuda_attention_softmax_function,
        1,
        1,
        1,
        block_x,
        1,
        1,
        0,
        NULL,
        params,
        NULL
    );
    if (result != 0) {
        king_inference_cuda_attention_softmax_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    model->cuda_attention_softmax_launch_count++;
    model->cuda_attention_softmax_result = 0;
    model->cuda_attention_softmax_error[0] = '\0';
    return SUCCESS;
}

static zend_result king_inference_cuda_attention_softmax_f32_batch(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input_scores,
    king_inference_cuda_device_ptr output_probabilities,
    king_inference_cuda_device_ptr output_sums,
    zend_ulong position_start,
    zend_ulong max_slots,
    zend_ulong batch_count,
    zend_ulong sliding_window,
    double scale,
    double temperature
) {
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    zend_ulong last_position;
    zend_ulong required_slot_start = 0;
    zend_ulong max_required_slots;
    unsigned int position_start32;
    unsigned int max_slots32;
    unsigned int batch_count32;
    unsigned int sliding_window32;
    unsigned int block_x = 256;
    float scale32 = (float) scale;
    float temperature32 = (float) temperature;
    void *params[9];
    int result;

    if (!model->cuda_attention_softmax_batch_available
        || model->cuda_attention_softmax_batch_function == NULL) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_attention_softmax_batch_unavailable");
        return FAILURE;
    }
    if (input_scores == 0 || output_probabilities == 0) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_attention_softmax_batch_argument_invalid");
        return FAILURE;
    }
    if (position_start > UINT_MAX
        || max_slots == 0
        || max_slots > UINT_MAX
        || batch_count == 0
        || batch_count > UINT_MAX
        || sliding_window > UINT_MAX
        || position_start > UINT_MAX - (batch_count - 1)
        || !isfinite(scale)
        || !isfinite(temperature)
        || temperature <= 0.0) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_attention_softmax_batch_shape_invalid");
        return FAILURE;
    }

    last_position = position_start + batch_count - 1;
    if (sliding_window > 0 && last_position >= sliding_window) {
        required_slot_start = last_position - sliding_window + 1;
    }
    max_required_slots = last_position - required_slot_start + 1;
    if (max_slots < max_required_slots) {
        king_inference_cuda_attention_softmax_set_error(model, -1, "cuda_attention_softmax_batch_slots_invalid");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_attention_softmax_driver_symbol(
            model,
            "cuLaunchKernel",
            (void **) &cached_cu_launch_kernel
        )) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    position_start32 = (unsigned int) position_start;
    max_slots32 = (unsigned int) max_slots;
    batch_count32 = (unsigned int) batch_count;
    sliding_window32 = (unsigned int) sliding_window;
    params[0] = &input_scores;
    params[1] = &output_probabilities;
    params[2] = &output_sums;
    params[3] = &position_start32;
    params[4] = &max_slots32;
    params[5] = &batch_count32;
    params[6] = &sliding_window32;
    params[7] = &scale32;
    params[8] = &temperature32;

    result = cu_launch_kernel(
        model->cuda_attention_softmax_batch_function,
        batch_count32,
        1,
        1,
        block_x,
        1,
        1,
        0,
        NULL,
        params,
        NULL
    );
    if (result != 0) {
        king_inference_cuda_attention_softmax_set_error(model, result, "cuLaunchKernel_batch_failed");
        return FAILURE;
    }

    model->cuda_attention_softmax_batch_launch_count++;
    model->cuda_attention_softmax_result = 0;
    model->cuda_attention_softmax_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_attention_softmax_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval attention_softmax;

    array_init(&attention_softmax);
    add_assoc_bool(&attention_softmax, "attempted", model->cuda_attention_softmax_attempted);
    add_assoc_bool(&attention_softmax, "available", model->cuda_attention_softmax_available);
    add_assoc_bool(&attention_softmax, "nvrtc_available", model->cuda_attention_softmax_nvrtc_available);
    add_assoc_bool(&attention_softmax, "module_loaded", model->cuda_attention_softmax_module_loaded);
    add_assoc_bool(&attention_softmax, "f32_available", model->cuda_attention_softmax_f32_available);
    add_assoc_bool(&attention_softmax, "batch_available", model->cuda_attention_softmax_batch_available);
    add_assoc_long(&attention_softmax, "launches", (zend_long) model->cuda_attention_softmax_launch_count);
    add_assoc_long(&attention_softmax, "batch_launches", (zend_long) model->cuda_attention_softmax_batch_launch_count);
    add_assoc_long(&attention_softmax, "result", model->cuda_attention_softmax_result);
    add_assoc_string(&attention_softmax, "error", model->cuda_attention_softmax_error);
    add_assoc_zval(return_value, "attention_softmax_kernel", &attention_softmax);
}

static void king_inference_cuda_attention_softmax_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_softmax;

    king_inference_cuda_attention_softmax_add_status(model, return_value);
    if (!model->cuda_attention_softmax_attempted
        || model->cuda_attention_softmax_available
        || !model->cuda_context_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_softmax = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_softmax) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_attention_softmax_kernel_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_attention_softmax_kernel_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(
            return_value,
            "gpu_attention_softmax_kernel_unavailable"
        );
    }
}
