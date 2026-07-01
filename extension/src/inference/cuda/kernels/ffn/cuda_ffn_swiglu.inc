/*
 * CUDA FFN/SwiGLU kernel for native King GPU inference.
 */

typedef int king_inference_ffn_swiglu_nvrtc_result;
typedef void *king_inference_ffn_swiglu_nvrtc_program;

typedef king_inference_ffn_swiglu_nvrtc_result (*king_inference_ffn_swiglu_nvrtc_create_program_fn)(
    king_inference_ffn_swiglu_nvrtc_program *program,
    const char *source,
    const char *name,
    int header_count,
    const char * const *headers,
    const char * const *include_names
);
typedef king_inference_ffn_swiglu_nvrtc_result (*king_inference_ffn_swiglu_nvrtc_compile_program_fn)(
    king_inference_ffn_swiglu_nvrtc_program program,
    int option_count,
    const char * const *options
);
typedef king_inference_ffn_swiglu_nvrtc_result (*king_inference_ffn_swiglu_nvrtc_get_ptx_size_fn)(
    king_inference_ffn_swiglu_nvrtc_program program,
    size_t *ptx_size
);
typedef king_inference_ffn_swiglu_nvrtc_result (*king_inference_ffn_swiglu_nvrtc_get_ptx_fn)(
    king_inference_ffn_swiglu_nvrtc_program program,
    char *ptx
);
typedef king_inference_ffn_swiglu_nvrtc_result (*king_inference_ffn_swiglu_nvrtc_destroy_program_fn)(
    king_inference_ffn_swiglu_nvrtc_program *program
);

static const char king_inference_cuda_ffn_swiglu_source[] =
"extern \"C\" __device__ float king_silu(float x) {\n"
"    if (x >= 0.0f) {\n"
"        float e = expf(-x);\n"
"        return x / (1.0f + e);\n"
"    }\n"
"    float e = expf(x);\n"
"    return (x * e) / (1.0f + e);\n"
"}\n"
"extern \"C\" __device__ float king_gelu_tanh(float x) {\n"
"    float inner = 0.7978845608028654f * (x + 0.044715f * x * x * x);\n"
"    return 0.5f * x * (1.0f + tanhf(inner));\n"
"}\n"
"extern \"C\" __global__ void king_ffn_swiglu(\n"
"    const float *gate,\n"
"    const float *up,\n"
"    float *output,\n"
"    unsigned int length,\n"
"    unsigned int activation\n"
") {\n"
"    unsigned int index = (blockIdx.x * blockDim.x) + threadIdx.x;\n"
"    if (index >= length) {\n"
"        return;\n"
"    }\n"
"    float gate_value = gate[index];\n"
"    float activated = activation == 1u ? king_gelu_tanh(gate_value) : king_silu(gate_value);\n"
"    output[index] = activated * up[index];\n"
"}\n";

static void king_inference_cuda_ffn_swiglu_reset_fields(king_inference_model_object *model)
{
    model->cuda_ffn_swiglu_nvrtc_handle = NULL;
    model->cuda_ffn_swiglu_module = NULL;
    model->cuda_ffn_swiglu_function = NULL;
    model->cuda_ffn_swiglu_result = 0;
    model->cuda_ffn_swiglu_error[0] = '\0';
    model->cuda_ffn_swiglu_launch_count = 0;
    model->cuda_ffn_swiglu_attempted = false;
    model->cuda_ffn_swiglu_available = false;
    model->cuda_ffn_swiglu_nvrtc_available = false;
    model->cuda_ffn_swiglu_module_loaded = false;
    model->cuda_ffn_swiglu_f32_available = false;
    model->cuda_ffn_swiglu_path_available = false;
}

static void king_inference_cuda_ffn_swiglu_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_ffn_swiglu_error";
    }

    model->cuda_ffn_swiglu_result = result;
    snprintf(
        model->cuda_ffn_swiglu_error,
        sizeof(model->cuda_ffn_swiglu_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_ffn_swiglu_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_ffn_swiglu_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_ffn_swiglu_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_ffn_swiglu_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_ffn_swiglu_nvrtc_handle == NULL) {
        king_inference_cuda_ffn_swiglu_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_ffn_swiglu_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_ffn_swiglu_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_ffn_swiglu_open_nvrtc(king_inference_model_object *model)
{
    static const char *libraries[] = {
        "libnvrtc.so.12",
        "libnvrtc.so.11.2",
        "libnvrtc.so.11.0",
        "libnvrtc.so"
    };
    size_t index;

    for (index = 0; index < sizeof(libraries) / sizeof(libraries[0]); index++) {
        model->cuda_ffn_swiglu_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_ffn_swiglu_nvrtc_handle != NULL) {
            model->cuda_ffn_swiglu_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_ffn_swiglu_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_ffn_swiglu_compile_ptx(
    king_inference_model_object *model,
    char **ptx,
    size_t *ptx_size
) {
    king_inference_ffn_swiglu_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_ffn_swiglu_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_ffn_swiglu_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_ffn_swiglu_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_ffn_swiglu_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_ffn_swiglu_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_89", "--use_fast_math"};
    king_inference_ffn_swiglu_nvrtc_result result;

    *ptx = NULL;
    *ptx_size = 0;

    if (!king_inference_cuda_ffn_swiglu_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_ffn_swiglu_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_ffn_swiglu_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_ffn_swiglu_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_ffn_swiglu_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_ffn_swiglu_source,
        "king_ffn_swiglu.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_ffn_swiglu_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtcProgram_unavailable"
        );
        return false;
    }

    result = nvrtc_compile_program(program, 3, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_ffn_swiglu_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }

    result = nvrtc_get_ptx_size(program, ptx_size);
    if (result != 0 || *ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_ffn_swiglu_set_error(
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
        king_inference_cuda_ffn_swiglu_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_ffn_swiglu_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_ffn_swiglu_module != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_ffn_swiglu_driver_symbol(
            model,
            "cuModuleUnload",
            (void **) &cu_module_unload
        )) {
        (void) cu_module_unload(model->cuda_ffn_swiglu_module);
    }

    if (model->cuda_ffn_swiglu_nvrtc_handle != NULL) {
        dlclose(model->cuda_ffn_swiglu_nvrtc_handle);
    }

    king_inference_cuda_ffn_swiglu_reset_fields(model);
}

static void king_inference_cuda_ffn_swiglu_finish_available(king_inference_model_object *model)
{
    model->cuda_ffn_swiglu_f32_available = true;
    model->cuda_ffn_swiglu_path_available = model->cuda_quantized_matvec_available;
    model->cuda_ffn_swiglu_available = model->cuda_ffn_swiglu_path_available;
    if (!model->cuda_ffn_swiglu_path_available) {
        king_inference_cuda_ffn_swiglu_set_error(model, -1, "cuda_quantized_matvec_dependency_unavailable");
        return;
    }

    model->cuda_ffn_swiglu_result = 0;
    model->cuda_ffn_swiglu_error[0] = '\0';
}

static void king_inference_cuda_ffn_swiglu_init(
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

    king_inference_cuda_ffn_swiglu_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_ffn_swiglu_attempted = true;
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_ffn_swiglu_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_ffn_swiglu_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_ffn_swiglu_open_nvrtc(model)
        || !king_inference_cuda_ffn_swiglu_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_ffn_swiglu_driver_symbol(
            model,
            "cuModuleLoadData",
            (void **) &cu_module_load_data
        )
        || !king_inference_cuda_ffn_swiglu_driver_symbol(
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
        king_inference_cuda_ffn_swiglu_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_ffn_swiglu");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_ffn_swiglu_driver_symbol(
                model,
                "cuModuleUnload",
                (void **) &cu_module_unload
            )
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_ffn_swiglu_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_ffn_swiglu_kernel_unavailable"
        );
        return;
    }

    model->cuda_ffn_swiglu_module = module;
    model->cuda_ffn_swiglu_function = function;
    model->cuda_ffn_swiglu_module_loaded = true;
    king_inference_cuda_ffn_swiglu_finish_available(model);
}

static zend_result king_inference_cuda_ffn_swiglu_f32(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input_gate,
    king_inference_cuda_device_ptr input_up,
    king_inference_cuda_device_ptr output,
    zend_ulong length,
    zend_ulong activation
) {
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    unsigned int length32;
    unsigned int block_x = 256;
    unsigned int grid_x;
    unsigned int activation32;
    void *params[5];
    int result;

    if (!model->cuda_ffn_swiglu_f32_available || model->cuda_ffn_swiglu_function == NULL) {
        king_inference_cuda_ffn_swiglu_set_error(model, -1, "cuda_ffn_swiglu_unavailable");
        return FAILURE;
    }
    if (input_gate == 0 || input_up == 0 || output == 0) {
        king_inference_cuda_ffn_swiglu_set_error(model, -1, "cuda_ffn_swiglu_argument_invalid");
        return FAILURE;
    }
    if (length == 0 || length > UINT_MAX || activation > 1) {
        king_inference_cuda_ffn_swiglu_set_error(model, -1, "cuda_ffn_swiglu_shape_invalid");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_ffn_swiglu_driver_symbol(
            model,
            "cuLaunchKernel",
            (void **) &cached_cu_launch_kernel
        )) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    length32 = (unsigned int) length;
    activation32 = (unsigned int) activation;
    grid_x = (length32 / block_x) + ((length32 % block_x) != 0u ? 1u : 0u);
    params[0] = &input_gate;
    params[1] = &input_up;
    params[2] = &output;
    params[3] = &length32;
    params[4] = &activation32;

    result = cu_launch_kernel(
        model->cuda_ffn_swiglu_function,
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
        king_inference_cuda_ffn_swiglu_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    model->cuda_ffn_swiglu_launch_count++;
    king_inference_cuda_ffn_swiglu_finish_available(model);
    return SUCCESS;
}

static void king_inference_cuda_ffn_swiglu_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval ffn_swiglu;

    array_init(&ffn_swiglu);
    add_assoc_bool(&ffn_swiglu, "attempted", model->cuda_ffn_swiglu_attempted);
    add_assoc_bool(&ffn_swiglu, "available", model->cuda_ffn_swiglu_available);
    add_assoc_bool(&ffn_swiglu, "path_available", model->cuda_ffn_swiglu_path_available);
    add_assoc_bool(&ffn_swiglu, "quantized_matvec_available", model->cuda_quantized_matvec_available);
    add_assoc_bool(&ffn_swiglu, "nvrtc_available", model->cuda_ffn_swiglu_nvrtc_available);
    add_assoc_bool(&ffn_swiglu, "module_loaded", model->cuda_ffn_swiglu_module_loaded);
    add_assoc_bool(&ffn_swiglu, "f32_available", model->cuda_ffn_swiglu_f32_available);
    add_assoc_long(&ffn_swiglu, "launches", (zend_long) model->cuda_ffn_swiglu_launch_count);
    add_assoc_long(&ffn_swiglu, "result", model->cuda_ffn_swiglu_result);
    add_assoc_string(&ffn_swiglu, "error", model->cuda_ffn_swiglu_error);
    add_assoc_zval(return_value, "ffn_swiglu_path", &ffn_swiglu);
}

static void king_inference_cuda_ffn_swiglu_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_ffn;

    king_inference_cuda_ffn_swiglu_add_status(model, return_value);
    if (!model->cuda_ffn_swiglu_attempted
        || model->cuda_ffn_swiglu_available
        || !model->cuda_context_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_ffn = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_ffn) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_ffn_swiglu_path_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_ffn_swiglu_path_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(
            return_value,
            "gpu_ffn_swiglu_path_unavailable"
        );
    }
}
