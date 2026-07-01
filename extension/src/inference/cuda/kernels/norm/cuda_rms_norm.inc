/*
 * CUDA RMSNorm kernel for native King GPU inference.
 */

typedef int king_inference_rms_nvrtc_result;
typedef void *king_inference_rms_nvrtc_program;

typedef king_inference_rms_nvrtc_result (*king_inference_rms_nvrtc_create_program_fn)(
    king_inference_rms_nvrtc_program *program,
    const char *source,
    const char *name,
    int header_count,
    const char * const *headers,
    const char * const *include_names
);
typedef king_inference_rms_nvrtc_result (*king_inference_rms_nvrtc_compile_program_fn)(
    king_inference_rms_nvrtc_program program,
    int option_count,
    const char * const *options
);
typedef king_inference_rms_nvrtc_result (*king_inference_rms_nvrtc_get_ptx_size_fn)(
    king_inference_rms_nvrtc_program program,
    size_t *ptx_size
);
typedef king_inference_rms_nvrtc_result (*king_inference_rms_nvrtc_get_ptx_fn)(
    king_inference_rms_nvrtc_program program,
    char *ptx
);
typedef king_inference_rms_nvrtc_result (*king_inference_rms_nvrtc_destroy_program_fn)(
    king_inference_rms_nvrtc_program *program
);

static const char king_inference_cuda_rms_norm_source[] =
"extern \"C\" __device__ float king_rms_half_to_float(unsigned short h) {\n"
"    unsigned int sign = ((unsigned int) (h & 0x8000u)) << 16;\n"
"    unsigned int mantissa = (unsigned int) (h & 0x03ffu);\n"
"    int exponent = (int) ((h >> 10) & 0x1fu);\n"
"    unsigned int bits;\n"
"    if (exponent == 0) {\n"
"        if (mantissa == 0u) {\n"
"            bits = sign;\n"
"        } else {\n"
"            while ((mantissa & 0x0400u) == 0u) {\n"
"                mantissa <<= 1;\n"
"                exponent--;\n"
"            }\n"
"            exponent++;\n"
"            mantissa &= 0x03ffu;\n"
"            bits = sign | ((unsigned int) (exponent + 112) << 23) | (mantissa << 13);\n"
"        }\n"
"        return __uint_as_float(bits);\n"
"    }\n"
"    if (exponent == 31) {\n"
"        bits = sign | 0x7f800000u | (mantissa << 13);\n"
"        return __uint_as_float(bits);\n"
"    }\n"
"    bits = sign | ((unsigned int) (exponent + 112) << 23) | (mantissa << 13);\n"
"    return __uint_as_float(bits);\n"
"}\n"
"extern \"C\" __device__ float king_rms_norm_weight(\n"
"    const unsigned char *weight,\n"
"    unsigned int index,\n"
"    unsigned int weight_type\n"
") {\n"
"    if (weight_type == 0u) {\n"
"        return ((const float *) weight)[index];\n"
"    }\n"
"    const unsigned char *raw = weight + ((unsigned long long) index * 2ull);\n"
"    unsigned short h = (unsigned short) raw[0] | ((unsigned short) raw[1] << 8);\n"
"    return king_rms_half_to_float(h);\n"
"}\n"
"extern \"C\" __global__ void king_rms_norm(\n"
"    const float *input,\n"
"    const unsigned char *weight,\n"
"    float *output,\n"
"    unsigned int length,\n"
"    float epsilon,\n"
"    unsigned int weight_type\n"
") {\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    __shared__ float partials[256];\n"
"    float sum = 0.0f;\n"
"    for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"        float value = input[i];\n"
"        sum += value * value;\n"
"    }\n"
"    partials[lane_id] = sum;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] += partials[lane_id + stride];\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    float scale = rsqrtf((partials[0] / (float) length) + epsilon);\n"
"    for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"        output[i] = input[i] * scale * king_rms_norm_weight(weight, i, weight_type);\n"
"    }\n"
"}\n"
"extern \"C\" __global__ void king_rms_norm_batch(\n"
"    const float *input,\n"
"    const unsigned char *weight,\n"
"    float *output,\n"
"    unsigned int length,\n"
"    unsigned int batch_count,\n"
"    float epsilon,\n"
"    unsigned int weight_type\n"
") {\n"
"    unsigned int batch = blockIdx.x;\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    const float *row_input;\n"
"    float *row_output;\n"
"    __shared__ float partials[256];\n"
"    float sum = 0.0f;\n"
"    if (batch >= batch_count || length == 0u) {\n"
"        return;\n"
"    }\n"
"    row_input = input + ((unsigned long long) batch * (unsigned long long) length);\n"
"    row_output = output + ((unsigned long long) batch * (unsigned long long) length);\n"
"    for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"        float value = row_input[i];\n"
"        sum += value * value;\n"
"    }\n"
"    partials[lane_id] = sum;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] += partials[lane_id + stride];\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    float scale = rsqrtf((partials[0] / (float) length) + epsilon);\n"
"    for (unsigned int i = lane_id; i < length; i += blockDim.x) {\n"
"        row_output[i] = row_input[i] * scale * king_rms_norm_weight(weight, i, weight_type);\n"
"    }\n"
"}\n";

static void king_inference_cuda_rms_norm_reset_fields(king_inference_model_object *model)
{
    model->cuda_rms_norm_nvrtc_handle = NULL;
    model->cuda_rms_norm_module = NULL;
    model->cuda_rms_norm_function = NULL;
    model->cuda_rms_norm_batch_function = NULL;
    model->cuda_rms_norm_result = 0;
    model->cuda_rms_norm_error[0] = '\0';
    model->cuda_rms_norm_launch_count = 0;
    model->cuda_rms_norm_batch_launch_count = 0;
    model->cuda_rms_norm_attempted = false;
    model->cuda_rms_norm_available = false;
    model->cuda_rms_norm_nvrtc_available = false;
    model->cuda_rms_norm_module_loaded = false;
    model->cuda_rms_norm_f32_available = false;
    model->cuda_rms_norm_batch_available = false;
}

static void king_inference_cuda_rms_norm_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_rms_norm_error";
    }

    model->cuda_rms_norm_result = result;
    snprintf(model->cuda_rms_norm_error, sizeof(model->cuda_rms_norm_error), "%s", message);
}

static bool king_inference_cuda_rms_norm_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_rms_norm_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_rms_norm_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_rms_norm_nvrtc_handle == NULL) {
        king_inference_cuda_rms_norm_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_rms_norm_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_rms_norm_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_rms_norm_open_nvrtc(king_inference_model_object *model)
{
    static const char *libraries[] = {
        "libnvrtc.so.12",
        "libnvrtc.so.11.2",
        "libnvrtc.so.11.0",
        "libnvrtc.so"
    };
    size_t index;

    for (index = 0; index < sizeof(libraries) / sizeof(libraries[0]); index++) {
        model->cuda_rms_norm_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_rms_norm_nvrtc_handle != NULL) {
            model->cuda_rms_norm_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_rms_norm_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_rms_norm_compile_ptx(
    king_inference_model_object *model,
    char **ptx,
    size_t *ptx_size
) {
    king_inference_rms_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_rms_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_rms_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_rms_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_rms_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_rms_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_89", "--use_fast_math"};
    king_inference_rms_nvrtc_result result;

    *ptx = NULL;
    *ptx_size = 0;

    if (!king_inference_cuda_rms_norm_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_rms_norm_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_rms_norm_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_rms_norm_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_rms_norm_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_rms_norm_source,
        "king_rms_norm.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_rms_norm_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtcProgram_unavailable"
        );
        return false;
    }

    result = nvrtc_compile_program(program, 3, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_rms_norm_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }

    result = nvrtc_get_ptx_size(program, ptx_size);
    if (result != 0 || *ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_rms_norm_set_error(
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
        king_inference_cuda_rms_norm_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_rms_norm_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_rms_norm_module != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_rms_norm_driver_symbol(model, "cuModuleUnload", (void **) &cu_module_unload)) {
        (void) cu_module_unload(model->cuda_rms_norm_module);
    }

    if (model->cuda_rms_norm_nvrtc_handle != NULL) {
        dlclose(model->cuda_rms_norm_nvrtc_handle);
    }

    king_inference_cuda_rms_norm_reset_fields(model);
}

static void king_inference_cuda_rms_norm_init(
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

    king_inference_cuda_rms_norm_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_rms_norm_attempted = true;
    if (!model->cuda_weight_cache_ready) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_weight_cache_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_rms_norm_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_rms_norm_open_nvrtc(model)
        || !king_inference_cuda_rms_norm_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_rms_norm_driver_symbol(model, "cuModuleLoadData", (void **) &cu_module_load_data)
        || !king_inference_cuda_rms_norm_driver_symbol(model, "cuModuleGetFunction", (void **) &cu_module_get_function)) {
        efree(ptx);
        return;
    }

    result = cu_module_load_data(&module, ptx);
    efree(ptx);
    if (result != 0 || module == NULL) {
        king_inference_cuda_rms_norm_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_rms_norm");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_rms_norm_driver_symbol(model, "cuModuleUnload", (void **) &cu_module_unload)
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_rms_norm_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_rms_norm_kernel_unavailable"
        );
        return;
    }
    result = cu_module_get_function(&batch_function, module, "king_rms_norm_batch");
    if (result != 0 || batch_function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_rms_norm_driver_symbol(model, "cuModuleUnload", (void **) &cu_module_unload)
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_rms_norm_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_batch_failed" : "cuda_rms_norm_batch_kernel_unavailable"
        );
        return;
    }

    model->cuda_rms_norm_module = module;
    model->cuda_rms_norm_function = function;
    model->cuda_rms_norm_batch_function = batch_function;
    model->cuda_rms_norm_module_loaded = true;
    model->cuda_rms_norm_f32_available = true;
    model->cuda_rms_norm_batch_available = true;
    model->cuda_rms_norm_available = true;
    model->cuda_rms_norm_result = 0;
    model->cuda_rms_norm_error[0] = '\0';
}

static zend_result king_inference_cuda_rms_norm_f32(
    king_inference_model_object *model,
    zend_string *weight_name,
    king_inference_cuda_device_ptr input_vector,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong length,
    double epsilon
) {
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    zval *descriptor;
    zval *dimensions;
    zend_ulong type;
    zend_ulong rank;
    zend_ulong width;
    unsigned int length32;
    unsigned int weight_type32;
    unsigned int block_x = 256;
    float epsilon32 = (float) epsilon;
    void *params[6];
    int result;

    if (!model->cuda_rms_norm_available || model->cuda_rms_norm_function == NULL) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_unavailable");
        return FAILURE;
    }
    if (weight_name == NULL || input_vector == 0 || output_vector == 0) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_argument_invalid");
        return FAILURE;
    }
    if (length == 0 || length > UINT_MAX) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_shape_invalid");
        return FAILURE;
    }

    descriptor = king_inference_tensor_index_descriptor(model, weight_name);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || rank != 1
        || (type != 0 && type != 1)
        || king_inference_tensor_dimension_at(dimensions, 0, &width) != SUCCESS
        || width != length) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_tensor_descriptor_invalid");
        return FAILURE;
    }

    upload = king_inference_cuda_weight_cache_find(model, weight_name, true);
    if (upload == NULL || upload->device_ptr == 0) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_weight_not_uploaded");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_rms_norm_driver_symbol(model, "cuLaunchKernel", (void **) &cached_cu_launch_kernel)) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    length32 = (unsigned int) length;
    weight_type32 = type == 0 ? 0u : 1u;
    params[0] = &input_vector;
    params[1] = &upload->device_ptr;
    params[2] = &output_vector;
    params[3] = &length32;
    params[4] = &epsilon32;
    params[5] = &weight_type32;

    result = cu_launch_kernel(
        model->cuda_rms_norm_function,
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
        king_inference_cuda_rms_norm_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    model->cuda_rms_norm_launch_count++;
    model->cuda_rms_norm_result = 0;
    model->cuda_rms_norm_error[0] = '\0';
    return SUCCESS;
}

static zend_result king_inference_cuda_rms_norm_f32_batch(
    king_inference_model_object *model,
    zend_string *weight_name,
    king_inference_cuda_device_ptr input_matrix,
    king_inference_cuda_device_ptr output_matrix,
    zend_ulong length,
    zend_ulong batch_count,
    double epsilon
) {
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    zval *descriptor;
    zval *dimensions;
    zend_ulong type;
    zend_ulong rank;
    zend_ulong width;
    unsigned int length32;
    unsigned int batch32;
    unsigned int weight_type32;
    unsigned int block_x = 256;
    float epsilon32 = (float) epsilon;
    void *params[7];
    int result;

    if (!model->cuda_rms_norm_batch_available || model->cuda_rms_norm_batch_function == NULL) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_batch_unavailable");
        return FAILURE;
    }
    if (weight_name == NULL || input_matrix == 0 || output_matrix == 0) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_batch_argument_invalid");
        return FAILURE;
    }
    if (length == 0 || length > UINT_MAX || batch_count == 0 || batch_count > UINT_MAX) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_batch_shape_invalid");
        return FAILURE;
    }

    descriptor = king_inference_tensor_index_descriptor(model, weight_name);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || rank != 1
        || (type != 0 && type != 1)
        || king_inference_tensor_dimension_at(dimensions, 0, &width) != SUCCESS
        || width != length) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_batch_tensor_descriptor_invalid");
        return FAILURE;
    }

    upload = king_inference_cuda_weight_cache_find(model, weight_name, true);
    if (upload == NULL || upload->device_ptr == 0) {
        king_inference_cuda_rms_norm_set_error(model, -1, "cuda_rms_norm_batch_weight_not_uploaded");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_rms_norm_driver_symbol(model, "cuLaunchKernel", (void **) &cached_cu_launch_kernel)) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    length32 = (unsigned int) length;
    batch32 = (unsigned int) batch_count;
    weight_type32 = type == 0 ? 0u : 1u;
    params[0] = &input_matrix;
    params[1] = &upload->device_ptr;
    params[2] = &output_matrix;
    params[3] = &length32;
    params[4] = &batch32;
    params[5] = &epsilon32;
    params[6] = &weight_type32;

    result = cu_launch_kernel(
        model->cuda_rms_norm_batch_function,
        batch32,
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
        king_inference_cuda_rms_norm_set_error(model, result, "cuLaunchKernel_batch_failed");
        return FAILURE;
    }

    model->cuda_rms_norm_batch_launch_count++;
    model->cuda_rms_norm_result = 0;
    model->cuda_rms_norm_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_rms_norm_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval rms_norm;
    zval supported_weight_types;

    array_init(&rms_norm);
    add_assoc_bool(&rms_norm, "attempted", model->cuda_rms_norm_attempted);
    add_assoc_bool(&rms_norm, "available", model->cuda_rms_norm_available);
    add_assoc_bool(&rms_norm, "nvrtc_available", model->cuda_rms_norm_nvrtc_available);
    add_assoc_bool(&rms_norm, "module_loaded", model->cuda_rms_norm_module_loaded);
    add_assoc_bool(&rms_norm, "f32_available", model->cuda_rms_norm_f32_available);
    add_assoc_bool(&rms_norm, "batch_available", model->cuda_rms_norm_batch_available);
    add_assoc_long(&rms_norm, "launches", (zend_long) model->cuda_rms_norm_launch_count);
    add_assoc_long(&rms_norm, "batch_launches", (zend_long) model->cuda_rms_norm_batch_launch_count);
    add_assoc_long(&rms_norm, "result", model->cuda_rms_norm_result);
    add_assoc_string(&rms_norm, "error", model->cuda_rms_norm_error);

    array_init(&supported_weight_types);
    add_next_index_string(&supported_weight_types, "F32");
    add_next_index_string(&supported_weight_types, "F16");
    add_assoc_zval(&rms_norm, "supported_weight_types", &supported_weight_types);
    add_assoc_zval(return_value, "rms_norm_kernel", &rms_norm);
}

static void king_inference_cuda_rms_norm_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_rms_norm;

    king_inference_cuda_rms_norm_add_status(model, return_value);
    if (!model->cuda_rms_norm_attempted
        || model->cuda_rms_norm_available
        || !model->cuda_weight_upload_complete) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_rms_norm = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_rms_norm) {
        king_inference_cuda_context_prepend_return_reason(return_value, "gpu_rms_norm_kernel_unavailable");
        add_assoc_string(return_value, "reason", "gpu_rms_norm_kernel_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_rms_norm_kernel_unavailable");
    }
}
