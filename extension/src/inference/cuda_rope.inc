/*
 * CUDA RoPE kernel for native King GPU inference.
 */

typedef int king_inference_rope_nvrtc_result;
typedef void *king_inference_rope_nvrtc_program;

typedef king_inference_rope_nvrtc_result (*king_inference_rope_nvrtc_create_program_fn)(
    king_inference_rope_nvrtc_program *program,
    const char *source,
    const char *name,
    int header_count,
    const char * const *headers,
    const char * const *include_names
);
typedef king_inference_rope_nvrtc_result (*king_inference_rope_nvrtc_compile_program_fn)(
    king_inference_rope_nvrtc_program program,
    int option_count,
    const char * const *options
);
typedef king_inference_rope_nvrtc_result (*king_inference_rope_nvrtc_get_ptx_size_fn)(
    king_inference_rope_nvrtc_program program,
    size_t *ptx_size
);
typedef king_inference_rope_nvrtc_result (*king_inference_rope_nvrtc_get_ptx_fn)(
    king_inference_rope_nvrtc_program program,
    char *ptx
);
typedef king_inference_rope_nvrtc_result (*king_inference_rope_nvrtc_destroy_program_fn)(
    king_inference_rope_nvrtc_program *program
);

static const char king_inference_cuda_rope_source[] =
"extern \"C\" __global__ void king_rope(\n"
"    const float *input,\n"
"    float *output,\n"
"    unsigned int length,\n"
"    unsigned int offset,\n"
"    unsigned int head_dim,\n"
"    unsigned long long position,\n"
"    float position_scale,\n"
"    float rope_base\n"
") {\n"
"    unsigned int index = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    unsigned int pair_count = head_dim >> 1;\n"
"    if (index < length && (index < offset || index >= offset + head_dim)) {\n"
"        output[index] = input[index];\n"
"    }\n"
"    if (index >= pair_count) {\n"
"        return;\n"
"    }\n"
"    unsigned int even = offset + index * 2u;\n"
"    unsigned int odd = even + 1u;\n"
"    float exponent = ((float) (index * 2u)) / (float) head_dim;\n"
"    float inv_freq = expf(-logf(rope_base) * exponent);\n"
"    float angle = (float) position * position_scale * inv_freq;\n"
"    float sin_value = sinf(angle);\n"
"    float cos_value = cosf(angle);\n"
"    float x0 = input[even];\n"
"    float x1 = input[odd];\n"
"    output[even] = x0 * cos_value - x1 * sin_value;\n"
"    output[odd] = x0 * sin_value + x1 * cos_value;\n"
"}\n";

static void king_inference_cuda_rope_reset_fields(king_inference_model_object *model)
{
    model->cuda_rope_nvrtc_handle = NULL;
    model->cuda_rope_module = NULL;
    model->cuda_rope_function = NULL;
    model->cuda_rope_result = 0;
    model->cuda_rope_error[0] = '\0';
    model->cuda_rope_launch_count = 0;
    model->cuda_rope_attempted = false;
    model->cuda_rope_available = false;
    model->cuda_rope_nvrtc_available = false;
    model->cuda_rope_module_loaded = false;
    model->cuda_rope_f32_available = false;
}

static void king_inference_cuda_rope_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_rope_error";
    }

    model->cuda_rope_result = result;
    snprintf(model->cuda_rope_error, sizeof(model->cuda_rope_error), "%s", message);
}

static bool king_inference_cuda_rope_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_rope_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_rope_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_rope_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_rope_nvrtc_handle == NULL) {
        king_inference_cuda_rope_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_rope_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_rope_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_rope_open_nvrtc(king_inference_model_object *model)
{
    static const char *libraries[] = {
        "libnvrtc.so.12",
        "libnvrtc.so.11.2",
        "libnvrtc.so.11.0",
        "libnvrtc.so"
    };
    size_t index;

    for (index = 0; index < sizeof(libraries) / sizeof(libraries[0]); index++) {
        model->cuda_rope_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_rope_nvrtc_handle != NULL) {
            model->cuda_rope_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_rope_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_rope_compile_ptx(
    king_inference_model_object *model,
    char **ptx,
    size_t *ptx_size
) {
    king_inference_rope_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_rope_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_rope_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_rope_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_rope_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_rope_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_52"};
    king_inference_rope_nvrtc_result result;

    *ptx = NULL;
    *ptx_size = 0;

    if (!king_inference_cuda_rope_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_rope_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_rope_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_rope_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_rope_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_rope_source,
        "king_rope.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_rope_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtcProgram_unavailable"
        );
        return false;
    }

    result = nvrtc_compile_program(program, 2, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_rope_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }

    result = nvrtc_get_ptx_size(program, ptx_size);
    if (result != 0 || *ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_rope_set_error(
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
        king_inference_cuda_rope_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_rope_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_rope_module != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_rope_driver_symbol(model, "cuModuleUnload", (void **) &cu_module_unload)) {
        (void) cu_module_unload(model->cuda_rope_module);
    }

    if (model->cuda_rope_nvrtc_handle != NULL) {
        dlclose(model->cuda_rope_nvrtc_handle);
    }

    king_inference_cuda_rope_reset_fields(model);
}

static void king_inference_cuda_rope_init(
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

    king_inference_cuda_rope_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_rope_attempted = true;
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_rope_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_rope_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_rope_open_nvrtc(model)
        || !king_inference_cuda_rope_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_rope_driver_symbol(model, "cuModuleLoadData", (void **) &cu_module_load_data)
        || !king_inference_cuda_rope_driver_symbol(model, "cuModuleGetFunction", (void **) &cu_module_get_function)) {
        efree(ptx);
        return;
    }

    result = cu_module_load_data(&module, ptx);
    efree(ptx);
    if (result != 0 || module == NULL) {
        king_inference_cuda_rope_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_rope");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_rope_driver_symbol(model, "cuModuleUnload", (void **) &cu_module_unload)
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_rope_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_rope_kernel_unavailable"
        );
        return;
    }

    model->cuda_rope_module = module;
    model->cuda_rope_function = function;
    model->cuda_rope_module_loaded = true;
    model->cuda_rope_f32_available = true;
    model->cuda_rope_available = true;
    model->cuda_rope_result = 0;
    model->cuda_rope_error[0] = '\0';
}

static zend_result king_inference_cuda_rope_f32(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input_vector,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong length,
    zend_ulong offset,
    zend_ulong head_dim,
    zend_ulong position,
    double position_scale,
    double rope_base
) {
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    king_inference_cuda_ctx_synchronize_fn cu_ctx_synchronize;
    unsigned int length32;
    unsigned int offset32;
    unsigned int head_dim32;
    unsigned long long position64;
    float position_scale32 = (float) position_scale;
    float rope_base32 = (float) rope_base;
    unsigned int block_x = 256;
    unsigned int work_items;
    unsigned int grid_x;
    void *params[8];
    int result;

    if (!model->cuda_rope_available || model->cuda_rope_function == NULL) {
        king_inference_cuda_rope_set_error(model, -1, "cuda_rope_unavailable");
        return FAILURE;
    }
    if (input_vector == 0 || output_vector == 0) {
        king_inference_cuda_rope_set_error(model, -1, "cuda_rope_argument_invalid");
        return FAILURE;
    }
    if (length == 0
        || length > UINT_MAX
        || offset > length
        || head_dim == 0
        || (head_dim % 2) != 0
        || head_dim > length - offset
        || head_dim > UINT_MAX
        || !isfinite(position_scale)
        || !isfinite(rope_base)
        || rope_base <= 0.0) {
        king_inference_cuda_rope_set_error(model, -1, "cuda_rope_shape_invalid");
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)
        || !king_inference_cuda_rope_driver_symbol(model, "cuLaunchKernel", (void **) &cu_launch_kernel)
        || !king_inference_cuda_rope_driver_symbol(model, "cuCtxSynchronize", (void **) &cu_ctx_synchronize)) {
        return FAILURE;
    }

    length32 = (unsigned int) length;
    offset32 = (unsigned int) offset;
    head_dim32 = (unsigned int) head_dim;
    position64 = (unsigned long long) position;
    work_items = length32 > (head_dim32 >> 1) ? length32 : (head_dim32 >> 1);
    grid_x = (work_items + block_x - 1) / block_x;
    params[0] = &input_vector;
    params[1] = &output_vector;
    params[2] = &length32;
    params[3] = &offset32;
    params[4] = &head_dim32;
    params[5] = &position64;
    params[6] = &position_scale32;
    params[7] = &rope_base32;

    result = cu_launch_kernel(
        model->cuda_rope_function,
        grid_x,
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
        king_inference_cuda_rope_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    result = cu_ctx_synchronize();
    if (result != 0) {
        king_inference_cuda_rope_set_error(model, result, "cuCtxSynchronize_failed");
        return FAILURE;
    }

    model->cuda_rope_launch_count++;
    model->cuda_rope_result = 0;
    model->cuda_rope_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_rope_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval rope;

    array_init(&rope);
    add_assoc_bool(&rope, "attempted", model->cuda_rope_attempted);
    add_assoc_bool(&rope, "available", model->cuda_rope_available);
    add_assoc_bool(&rope, "nvrtc_available", model->cuda_rope_nvrtc_available);
    add_assoc_bool(&rope, "module_loaded", model->cuda_rope_module_loaded);
    add_assoc_bool(&rope, "f32_available", model->cuda_rope_f32_available);
    add_assoc_long(&rope, "launches", (zend_long) model->cuda_rope_launch_count);
    add_assoc_long(&rope, "result", model->cuda_rope_result);
    add_assoc_string(&rope, "error", model->cuda_rope_error);
    add_assoc_zval(return_value, "rope_kernel", &rope);
}

static void king_inference_cuda_rope_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_rope;

    king_inference_cuda_rope_add_status(model, return_value);
    if (!model->cuda_rope_attempted
        || model->cuda_rope_available
        || !model->cuda_context_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_rope = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_rope) {
        king_inference_cuda_context_prepend_return_reason(return_value, "gpu_rope_kernel_unavailable");
        add_assoc_string(return_value, "reason", "gpu_rope_kernel_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_rope_kernel_unavailable");
    }
}
