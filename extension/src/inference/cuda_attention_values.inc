/*
 * CUDA attention value aggregation kernel for native King GPU inference.
 */

typedef int king_inference_attention_values_nvrtc_result;
typedef void *king_inference_attention_values_nvrtc_program;

typedef king_inference_attention_values_nvrtc_result (*king_inference_attention_values_nvrtc_create_program_fn)(
    king_inference_attention_values_nvrtc_program *program,
    const char *source,
    const char *name,
    int header_count,
    const char * const *headers,
    const char * const *include_names
);
typedef king_inference_attention_values_nvrtc_result (*king_inference_attention_values_nvrtc_compile_program_fn)(
    king_inference_attention_values_nvrtc_program program,
    int option_count,
    const char * const *options
);
typedef king_inference_attention_values_nvrtc_result (*king_inference_attention_values_nvrtc_get_ptx_size_fn)(
    king_inference_attention_values_nvrtc_program program,
    size_t *ptx_size
);
typedef king_inference_attention_values_nvrtc_result (*king_inference_attention_values_nvrtc_get_ptx_fn)(
    king_inference_attention_values_nvrtc_program program,
    char *ptx
);
typedef king_inference_attention_values_nvrtc_result (*king_inference_attention_values_nvrtc_destroy_program_fn)(
    king_inference_attention_values_nvrtc_program *program
);

static const char king_inference_cuda_attention_values_source[] =
"extern \"C\" __global__ void king_attention_values(\n"
"    const float *probabilities,\n"
"    const float *values,\n"
"    float *context,\n"
"    unsigned int value_length,\n"
"    unsigned int value_stride,\n"
"    unsigned int slot_start,\n"
"    unsigned int slot_count\n"
") {\n"
"    unsigned int dim = blockIdx.x;\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    __shared__ float partials[256];\n"
"    if (dim >= value_length) {\n"
"        return;\n"
"    }\n"
"    float sum = 0.0f;\n"
"    for (unsigned int slot = lane_id; slot < slot_count; slot += blockDim.x) {\n"
"        const float *value = values + ((unsigned long long) (slot_start + slot) * value_stride);\n"
"        sum += probabilities[slot] * value[dim];\n"
"    }\n"
"    partials[lane_id] = sum;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] += partials[lane_id + stride];\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    if (lane_id == 0u) {\n"
"        context[dim] = partials[0];\n"
"    }\n"
"}\n";

static void king_inference_cuda_attention_values_reset_fields(king_inference_model_object *model)
{
    model->cuda_attention_values_nvrtc_handle = NULL;
    model->cuda_attention_values_module = NULL;
    model->cuda_attention_values_function = NULL;
    model->cuda_attention_values_result = 0;
    model->cuda_attention_values_error[0] = '\0';
    model->cuda_attention_values_launch_count = 0;
    model->cuda_attention_values_attempted = false;
    model->cuda_attention_values_available = false;
    model->cuda_attention_values_nvrtc_available = false;
    model->cuda_attention_values_module_loaded = false;
    model->cuda_attention_values_f32_available = false;
}

static void king_inference_cuda_attention_values_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_attention_values_error";
    }

    model->cuda_attention_values_result = result;
    snprintf(
        model->cuda_attention_values_error,
        sizeof(model->cuda_attention_values_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_attention_values_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_attention_values_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_attention_values_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_attention_values_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_attention_values_nvrtc_handle == NULL) {
        king_inference_cuda_attention_values_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_attention_values_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_attention_values_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_attention_values_open_nvrtc(king_inference_model_object *model)
{
    static const char *libraries[] = {
        "libnvrtc.so.12",
        "libnvrtc.so.11.2",
        "libnvrtc.so.11.0",
        "libnvrtc.so"
    };
    size_t index;

    for (index = 0; index < sizeof(libraries) / sizeof(libraries[0]); index++) {
        model->cuda_attention_values_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_attention_values_nvrtc_handle != NULL) {
            model->cuda_attention_values_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_attention_values_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_attention_values_compile_ptx(
    king_inference_model_object *model,
    char **ptx,
    size_t *ptx_size
) {
    king_inference_attention_values_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_attention_values_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_attention_values_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_attention_values_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_attention_values_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_attention_values_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_52"};
    king_inference_attention_values_nvrtc_result result;

    *ptx = NULL;
    *ptx_size = 0;

    if (!king_inference_cuda_attention_values_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_attention_values_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_attention_values_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_attention_values_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_attention_values_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_attention_values_source,
        "king_attention_values.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_attention_values_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtcProgram_unavailable"
        );
        return false;
    }

    result = nvrtc_compile_program(program, 2, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_attention_values_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }

    result = nvrtc_get_ptx_size(program, ptx_size);
    if (result != 0 || *ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_attention_values_set_error(
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
        king_inference_cuda_attention_values_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_attention_values_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_attention_values_module != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_attention_values_driver_symbol(
            model,
            "cuModuleUnload",
            (void **) &cu_module_unload
        )) {
        (void) cu_module_unload(model->cuda_attention_values_module);
    }

    if (model->cuda_attention_values_nvrtc_handle != NULL) {
        dlclose(model->cuda_attention_values_nvrtc_handle);
    }

    king_inference_cuda_attention_values_reset_fields(model);
}

static void king_inference_cuda_attention_values_init(
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

    king_inference_cuda_attention_values_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_attention_values_attempted = true;
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_attention_values_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_attention_values_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_attention_values_open_nvrtc(model)
        || !king_inference_cuda_attention_values_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_attention_values_driver_symbol(
            model,
            "cuModuleLoadData",
            (void **) &cu_module_load_data
        )
        || !king_inference_cuda_attention_values_driver_symbol(
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
        king_inference_cuda_attention_values_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_attention_values");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_attention_values_driver_symbol(
                model,
                "cuModuleUnload",
                (void **) &cu_module_unload
            )
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_attention_values_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_attention_values_kernel_unavailable"
        );
        return;
    }

    model->cuda_attention_values_module = module;
    model->cuda_attention_values_function = function;
    model->cuda_attention_values_module_loaded = true;
    model->cuda_attention_values_f32_available = true;
    model->cuda_attention_values_available = true;
    model->cuda_attention_values_result = 0;
    model->cuda_attention_values_error[0] = '\0';
}

static zend_result king_inference_cuda_attention_values_f32(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input_probabilities,
    king_inference_cuda_device_ptr value_cache,
    king_inference_cuda_device_ptr output_context,
    zend_ulong value_length,
    zend_ulong value_stride,
    zend_ulong slot_start,
    zend_ulong slot_count
) {
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    king_inference_cuda_ctx_synchronize_fn cu_ctx_synchronize;
    unsigned int value_length32;
    unsigned int value_stride32;
    unsigned int slot_start32;
    unsigned int slot_count32;
    unsigned int block_x = 256;
    void *params[7];
    int result;

    if (!model->cuda_attention_values_available || model->cuda_attention_values_function == NULL) {
        king_inference_cuda_attention_values_set_error(model, -1, "cuda_attention_values_unavailable");
        return FAILURE;
    }
    if (input_probabilities == 0 || value_cache == 0 || output_context == 0) {
        king_inference_cuda_attention_values_set_error(model, -1, "cuda_attention_values_argument_invalid");
        return FAILURE;
    }
    if (value_length == 0
        || value_length > UINT_MAX
        || value_stride < value_length
        || value_stride > UINT_MAX
        || slot_count == 0
        || slot_count > UINT_MAX
        || slot_start > UINT_MAX
        || slot_start > UINT_MAX - slot_count) {
        king_inference_cuda_attention_values_set_error(model, -1, "cuda_attention_values_shape_invalid");
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)
        || !king_inference_cuda_attention_values_driver_symbol(
            model,
            "cuLaunchKernel",
            (void **) &cu_launch_kernel
        )
        || !king_inference_cuda_attention_values_driver_symbol(
            model,
            "cuCtxSynchronize",
            (void **) &cu_ctx_synchronize
        )) {
        return FAILURE;
    }

    value_length32 = (unsigned int) value_length;
    value_stride32 = (unsigned int) value_stride;
    slot_start32 = (unsigned int) slot_start;
    slot_count32 = (unsigned int) slot_count;
    params[0] = &input_probabilities;
    params[1] = &value_cache;
    params[2] = &output_context;
    params[3] = &value_length32;
    params[4] = &value_stride32;
    params[5] = &slot_start32;
    params[6] = &slot_count32;

    result = cu_launch_kernel(
        model->cuda_attention_values_function,
        value_length32,
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
        king_inference_cuda_attention_values_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    result = cu_ctx_synchronize();
    if (result != 0) {
        king_inference_cuda_attention_values_set_error(model, result, "cuCtxSynchronize_failed");
        return FAILURE;
    }

    model->cuda_attention_values_launch_count++;
    model->cuda_attention_values_result = 0;
    model->cuda_attention_values_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_attention_values_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval attention_values;

    array_init(&attention_values);
    add_assoc_bool(&attention_values, "attempted", model->cuda_attention_values_attempted);
    add_assoc_bool(&attention_values, "available", model->cuda_attention_values_available);
    add_assoc_bool(&attention_values, "nvrtc_available", model->cuda_attention_values_nvrtc_available);
    add_assoc_bool(&attention_values, "module_loaded", model->cuda_attention_values_module_loaded);
    add_assoc_bool(&attention_values, "f32_available", model->cuda_attention_values_f32_available);
    add_assoc_long(&attention_values, "launches", (zend_long) model->cuda_attention_values_launch_count);
    add_assoc_long(&attention_values, "result", model->cuda_attention_values_result);
    add_assoc_string(&attention_values, "error", model->cuda_attention_values_error);
    add_assoc_zval(return_value, "attention_value_aggregation_kernel", &attention_values);
}

static void king_inference_cuda_attention_values_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_values;

    king_inference_cuda_attention_values_add_status(model, return_value);
    if (!model->cuda_attention_values_attempted
        || model->cuda_attention_values_available
        || !model->cuda_context_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_values = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_values) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_attention_value_aggregation_kernel_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_attention_value_aggregation_kernel_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(
            return_value,
            "gpu_attention_value_aggregation_kernel_unavailable"
        );
    }
}
