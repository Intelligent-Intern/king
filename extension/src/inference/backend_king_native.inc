static zval *king_inference_king_native_graph_options(king_inference_stream_object *stream)
{
    zval *options = king_inference_array_find(&stream->request, "graph_options");

    if (options != NULL && king_inference_openai_array_is_object(options)) {
        return options;
    }

    options = king_inference_array_find(&stream->options, "graph_options");
    return options != NULL && king_inference_openai_array_is_object(options) ? options : NULL;
}

static zend_result king_inference_king_native_append_prompt_generated_tokens(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *prompt_text,
    zval *graph_options,
    zend_long max_tokens);

static zend_result king_inference_king_native_stream_token_limit(
    king_inference_stream_object *stream,
    zval *graph_options,
    zend_long *limit)
{
    zval *value = king_inference_array_find(&stream->options, "max_native_stream_tokens");

    if (value == NULL && graph_options != NULL) {
        value = king_inference_array_find(graph_options, "max_native_stream_tokens");
    }
    if (value == NULL) {
        *limit = 4096;
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) <= 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native stream max_native_stream_tokens must be a positive integer."
        );
        return FAILURE;
    }

    *limit = Z_LVAL_P(value);
    return SUCCESS;
}

static zval *king_inference_king_native_find_memory_option(zval *source)
{
    zval *value = king_inference_array_find(source, "with_memory");
    zval *alias = king_inference_array_find(source, "with-memory");

    if (value != NULL && alias != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native stream memory config must use either with_memory or with-memory, not both."
        );
        return NULL;
    }

    return value != NULL ? value : alias;
}

static zend_result king_inference_king_native_apply_memory_option(
    zval *source,
    bool *with_memory)
{
    zval *value = king_inference_king_native_find_memory_option(source);

    if (EG(exception)) {
        return FAILURE;
    }
    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native stream with_memory must be a boolean."
        );
        return FAILURE;
    }

    *with_memory = Z_TYPE_P(value) == IS_TRUE;
    return SUCCESS;
}

static zend_result king_inference_king_native_stream_with_memory(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph_options,
    bool *with_memory)
{
    *with_memory = king_high_perf_compute_ai_config.inference_with_memory;

    if (king_inference_king_native_apply_memory_option(&model->config, with_memory) != SUCCESS
        || king_inference_king_native_apply_memory_option(&stream->request, with_memory) != SUCCESS
        || king_inference_king_native_apply_memory_option(&stream->options, with_memory) != SUCCESS) {
        return FAILURE;
    }
    if (graph_options != NULL
        && king_inference_king_native_apply_memory_option(graph_options, with_memory) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zval *king_inference_king_native_graph_token_payload(zval *graph_result, zval *graph)
{
    zval *final = king_inference_array_find(graph_result, "final");
    zval *output_id = king_inference_array_find(graph, "output");
    zval *payload = NULL;

    if (final == NULL || Z_TYPE_P(final) != IS_ARRAY) {
        return NULL;
    }
    if (output_id != NULL && Z_TYPE_P(output_id) == IS_STRING) {
        payload = zend_hash_find(Z_ARRVAL_P(final), Z_STR_P(output_id));
        if (payload != NULL) {
            return payload;
        }
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(final), payload) {
        return payload;
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_king_native_token_id_from_result(
    zval *graph_result,
    zval *graph,
    zend_ulong *token_id
) {
    zval *payload = king_inference_king_native_graph_token_payload(graph_result, graph);
    zval *values;
    zval *token;
    double token_value;

    if (payload == NULL || Z_TYPE_P(payload) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native graph stream produced no final token payload.");
        return FAILURE;
    }

    values = king_inference_array_find(payload, "values");
    if (values == NULL || Z_TYPE_P(values) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native graph token payload has no values vector.");
        return FAILURE;
    }

    token = zend_hash_index_find(Z_ARRVAL_P(values), 0);
    if (token == NULL) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native graph token payload is empty.");
        return FAILURE;
    }
    if (Z_TYPE_P(token) == IS_LONG) {
        if (Z_LVAL_P(token) < 0) {
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native graph produced a negative token id.");
            return FAILURE;
        }
        *token_id = (zend_ulong) Z_LVAL_P(token);
        return SUCCESS;
    }
    if (Z_TYPE_P(token) != IS_DOUBLE || Z_DVAL_P(token) < 0.0) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native graph produced an invalid token id.");
        return FAILURE;
    }

    token_value = Z_DVAL_P(token);
    if (token_value > (double) ZEND_ULONG_MAX) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native graph token id exceeds supported limits.");
        return FAILURE;
    }

    *token_id = (zend_ulong) token_value;
    return SUCCESS;
}

static bool king_inference_king_native_metric_double(zval *values, zend_ulong index, double *value)
{
    zval *field = zend_hash_index_find(Z_ARRVAL_P(values), index);

    if (field == NULL) {
        return false;
    }
    if (Z_TYPE_P(field) == IS_LONG) {
        *value = (double) Z_LVAL_P(field);
        return true;
    }
    if (Z_TYPE_P(field) == IS_DOUBLE) {
        *value = Z_DVAL_P(field);
        return true;
    }

    return false;
}

static void king_inference_king_native_update_decoder_metrics(
    king_inference_stream_object *stream,
    zval *graph_result,
    zval *graph,
    zend_ulong token_id
) {
    zval *payload = king_inference_king_native_graph_token_payload(graph_result, graph);
    zval *values = payload != NULL && Z_TYPE_P(payload) == IS_ARRAY ? king_inference_array_find(payload, "values") : NULL;
    double probability;
    double logit;
    double rank;

    stream->native_decoder_token_count++;
    stream->native_decoder_last_token_id = token_id;
    stream->native_decoder_last_token_available = true;
    stream->native_decoder_last_score_available = false;
    if (values == NULL || Z_TYPE_P(values) != IS_ARRAY) {
        return;
    }
    if (king_inference_king_native_metric_double(values, 1, &probability)
        && king_inference_king_native_metric_double(values, 2, &logit)
        && king_inference_king_native_metric_double(values, 3, &rank)) {
        stream->native_decoder_last_probability = probability;
        stream->native_decoder_last_logit = logit;
        stream->native_decoder_last_rank = rank;
        stream->native_decoder_last_score_available = true;
    }
}

static bool king_inference_king_native_is_token_decode_graph(zval *graph)
{
    zval *type = king_inference_array_find(graph, "graph_type");

    return type != NULL
        && Z_TYPE_P(type) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(type), "king_native_cpu_token_decode");
}

static zval *king_inference_king_native_next_token_op(zval *graph)
{
    zval *ops = king_inference_array_find(graph, "ops");
    zval *entry;
    zval *op_name;

    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && (zend_string_equals_literal(Z_STR_P(op_name), "argmax_token")
                || zend_string_equals_literal(Z_STR_P(op_name), "sample_token"))) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static void king_inference_king_native_copy_decode_option(zval *target, zval *source, const char *key)
{
    zval *value = king_inference_array_find(source, key);

    if (value != NULL) {
        zval copied;
        ZVAL_COPY(&copied, value);
        add_assoc_zval(target, key, &copied);
    }
}

static zend_result king_inference_king_native_decode_options_from_graph(zval *graph, zval *decode_options)
{
    zval *op = king_inference_king_native_next_token_op(graph);
    zval *op_name;

    array_init(decode_options);
    if (op == NULL) {
        zval_ptr_dtor(decode_options);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native generated token stream requires a token-selection graph op.");
        return FAILURE;
    }
    op_name = king_inference_array_find(op, "op");
    if (op_name == NULL || Z_TYPE_P(op_name) != IS_STRING) {
        zval_ptr_dtor(decode_options);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native generated token stream token-selection op is invalid.");
        return FAILURE;
    }
    if (zend_string_equals_literal(Z_STR_P(op_name), "argmax_token")) {
        add_assoc_string(decode_options, "sampler", "argmax");
        return SUCCESS;
    }
    if (!zend_string_equals_literal(Z_STR_P(op_name), "sample_token")) {
        zval_ptr_dtor(decode_options);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native generated token stream token-selection op is unsupported.");
        return FAILURE;
    }

    add_assoc_string(decode_options, "sampler", "sample");
    king_inference_king_native_copy_decode_option(decode_options, op, "temperature");
    king_inference_king_native_copy_decode_option(decode_options, op, "top_k");
    king_inference_king_native_copy_decode_option(decode_options, op, "top_p");
    king_inference_king_native_copy_decode_option(decode_options, op, "seed");
    return SUCCESS;
}

static zend_result king_inference_king_native_graph_position(zval *graph, zend_ulong *position)
{
    zval *value = king_inference_array_find(graph, "position");

    if (value == NULL || Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native generated token stream requires a non-negative graph position.");
        return FAILURE;
    }

    *position = (zend_ulong) Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_inference_king_native_append_graph_token(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph,
    zval *graph_options,
    zval *state,
    bool with_memory,
    zend_ulong *token_id_out
) {
    zval working_graph;
    zval graph_result;
    zval *next_state;
    zend_ulong token_id;
    zend_string *token_text;
    zend_result status;

    if (graph == NULL || Z_TYPE_P(graph) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native stream graph entries must be arrays.");
        return FAILURE;
    }

    ZVAL_DUP(&working_graph, graph);
    SEPARATE_ARRAY(&working_graph);
    if (with_memory
        && !Z_ISUNDEF_P(state)
        && king_inference_array_find(&working_graph, "state") == NULL) {
        zval state_copy;
        ZVAL_COPY(&state_copy, state);
        add_assoc_zval(&working_graph, "state", &state_copy);
    }

    ZVAL_UNDEF(&graph_result);
    status = king_inference_graph_run_array(model, &working_graph, graph_options, &graph_result);
    if (status != SUCCESS) {
        zval_ptr_dtor(&working_graph);
        return FAILURE;
    }
    if (king_inference_king_native_token_id_from_result(&graph_result, &working_graph, &token_id) != SUCCESS) {
        zval_ptr_dtor(&graph_result);
        zval_ptr_dtor(&working_graph);
        return FAILURE;
    }

    token_text = king_inference_tokenizer_decode_token(model, token_id);
    if (token_text == NULL) {
        zval_ptr_dtor(&graph_result);
        zval_ptr_dtor(&working_graph);
        return FAILURE;
    }
    king_inference_king_native_update_decoder_metrics(stream, &graph_result, &working_graph, token_id);
    add_next_index_str(&stream->native_events, token_text);
    if (token_id_out != NULL) {
        *token_id_out = token_id;
    }

    next_state = with_memory ? king_inference_array_find(&graph_result, "state") : NULL;
    if (next_state != NULL && Z_TYPE_P(next_state) == IS_ARRAY) {
        if (!Z_ISUNDEF_P(state)) {
            zval_ptr_dtor(state);
        }
        ZVAL_COPY(state, next_state);
    }

    zval_ptr_dtor(&graph_result);
    zval_ptr_dtor(&working_graph);
    return SUCCESS;
}

static zend_result king_inference_king_native_append_generated_tokens(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph,
    zval *graph_options,
    zval *state,
    bool with_memory,
    zend_long max_tokens
) {
    zval current_graph;
    zval decode_options;
    zend_ulong token_id = 0;
    zend_ulong position = 0;
    zend_long emitted;

    if (king_inference_king_native_graph_position(graph, &position) != SUCCESS
        || king_inference_king_native_decode_options_from_graph(graph, &decode_options) != SUCCESS) {
        return FAILURE;
    }

    ZVAL_DUP(&current_graph, graph);
    for (emitted = 0; emitted < max_tokens; emitted++) {
        if (king_inference_king_native_append_graph_token(model, stream, &current_graph, graph_options, state, with_memory, &token_id) != SUCCESS) {
            zval_ptr_dtor(&current_graph);
            zval_ptr_dtor(&decode_options);
            return FAILURE;
        }
        if (emitted + 1 >= max_tokens) {
            break;
        }
        if (position >= (zend_ulong) ZEND_LONG_MAX || token_id > (zend_ulong) ZEND_LONG_MAX) {
            zval_ptr_dtor(&current_graph);
            zval_ptr_dtor(&decode_options);
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native generated token stream exceeded supported token or position limits.");
            return FAILURE;
        }
        zval_ptr_dtor(&current_graph);
        {
            zval token;
            ZVAL_LONG(&token, (zend_long) token_id);
            position++;
            if (king_inference_token_decode_graph_array(model, &token, position, &decode_options, &current_graph) != SUCCESS) {
                zval_ptr_dtor(&decode_options);
                return FAILURE;
            }
        }
    }

    zval_ptr_dtor(&current_graph);
    zval_ptr_dtor(&decode_options);
    return SUCCESS;
}

static zend_result king_inference_king_native_prepare_events(
    king_inference_model_object *model,
    king_inference_stream_object *stream
) {
    zval *graph = king_inference_array_find(&stream->request, "graph");
    zval *graphs = king_inference_array_find(&stream->request, "graphs");
    zval *prompt_text = king_inference_array_find(&stream->request, "native_prompt_text");
    zval *request_graph_options = king_inference_array_find(&stream->request, "graph_options");
    zval *option_graph_options = king_inference_array_find(&stream->options, "graph_options");
    zval *graph_options = king_inference_king_native_graph_options(stream);
    zval *max_tokens_value = king_inference_array_find(&stream->request, "max_tokens");
    zval state;
    zval *entry;
    zend_long max_tokens = 1;
    zend_long max_native_stream_tokens;
    zend_long emitted = 0;
    bool with_memory = false;

    if (graph != NULL && !king_inference_openai_array_is_object(graph)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native stream graph must be an object array.");
        return FAILURE;
    }
    if (graphs != NULL && !king_inference_openai_array_is_list(graphs)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native stream graphs must be a list array.");
        return FAILURE;
    }
    if ((request_graph_options != NULL && !king_inference_openai_array_is_object(request_graph_options))
        || (option_graph_options != NULL && !king_inference_openai_array_is_object(option_graph_options))) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native stream graph_options must be an object array.");
        return FAILURE;
    }
    if (graphs == NULL && graph == NULL && prompt_text == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native CPU streaming requires a native graph, graphs, or prompt request."
        );
        return FAILURE;
    }
    if (max_tokens_value != NULL) {
        if (Z_TYPE_P(max_tokens_value) != IS_LONG) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King native stream max_tokens must be an integer.");
            return FAILURE;
        }
        max_tokens = Z_LVAL_P(max_tokens_value);
    } else if (graphs != NULL) {
        max_tokens = (zend_long) zend_hash_num_elements(Z_ARRVAL_P(graphs));
    }
    if (max_tokens <= 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native stream max_tokens must be positive.");
        return FAILURE;
    }
    if (king_inference_king_native_stream_token_limit(
        stream,
        graph_options,
        &max_native_stream_tokens
    ) != SUCCESS) {
        return FAILURE;
    }
    if (max_tokens > max_native_stream_tokens) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native stream max_tokens exceeds configured max_native_stream_tokens."
        );
        return FAILURE;
    }
    if (king_inference_king_native_stream_with_memory(model, stream, graph_options, &with_memory) != SUCCESS) {
        return FAILURE;
    }

    if (!Z_ISUNDEF(stream->native_events)) {
        zval_ptr_dtor(&stream->native_events);
    }
    array_init(&stream->native_events);
    stream->native_event_index = 0;
    ZVAL_UNDEF(&state);

    if (king_inference_llm_cache_prepare_stream(
            model,
            stream,
            graph_options,
            with_memory,
            "King native graph stream"
        ) != SUCCESS) {
        return FAILURE;
    }

    if (graphs != NULL) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(graphs), entry) {
            if (emitted >= max_tokens) {
                break;
            }
            if (king_inference_king_native_append_graph_token(model, stream, entry, graph_options, &state, with_memory, NULL) != SUCCESS) {
                if (!Z_ISUNDEF(state)) {
                    zval_ptr_dtor(&state);
                }
                return FAILURE;
            }
            emitted++;
        } ZEND_HASH_FOREACH_END();
    } else if (prompt_text != NULL) {
        if (king_inference_king_native_append_prompt_generated_tokens(
                model,
                stream,
                prompt_text,
                graph_options,
                max_tokens
            ) != SUCCESS) {
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            return FAILURE;
        }
    } else if (king_inference_king_native_is_token_decode_graph(graph)) {
        if (king_inference_king_native_append_generated_tokens(model, stream, graph, graph_options, &state, with_memory, max_tokens) != SUCCESS) {
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            return FAILURE;
        }
    } else {
        while (emitted < max_tokens) {
            if (king_inference_king_native_append_graph_token(model, stream, graph, graph_options, &state, with_memory, NULL) != SUCCESS) {
                if (!Z_ISUNDEF(state)) {
                    zval_ptr_dtor(&state);
                }
                return FAILURE;
            }
            emitted++;
        }
    }

    if (!Z_ISUNDEF(state)) {
        zval_ptr_dtor(&state);
    }
    if (zend_hash_num_elements(Z_ARRVAL(stream->native_events)) == 0) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native stream produced no token events.");
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_king_native_cpu_stream_start(
    king_inference_stream_object *stream,
    const char *function_name
) {
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));

    if (king_inference_check_gpu_policy_before_run(stream, function_name) != SUCCESS) {
        return FAILURE;
    }

    return king_inference_king_native_prepare_events(model, stream);
}

static void king_inference_king_native_model_info(
    king_inference_model_object *intern,
    zval *return_value
) {
    add_assoc_string(return_value, "engine", "king_native_cpu");
    add_assoc_string(return_value, "loader", "king_gguf_structure");
    add_assoc_bool(return_value, "external_runtime", false);
    add_assoc_bool(return_value, "tokenization_ready", intern->gguf.tokenizer_lookup_loaded);
    add_assoc_bool(return_value, "token_generation_ready", true);
    add_assoc_string(return_value, "generation_runtime", "king_native_graph");
    add_assoc_long(return_value, "native_file_bytes", (zend_long) intern->gguf.file_size);
    add_assoc_bool(return_value, "native_metadata_ready", intern->gguf.metadata_parsed);
    add_assoc_bool(return_value, "native_tensor_directory_ready", intern->gguf.tensor_directory_parsed);
    add_assoc_bool(return_value, "native_model_mapped", intern->native_map_loaded);
    if (!Z_ISUNDEF(intern->paged_kv_cache_plan) && Z_TYPE(intern->paged_kv_cache_plan) == IS_ARRAY) {
        zval *ready = zend_hash_str_find(Z_ARRVAL(intern->paged_kv_cache_plan), "ready", sizeof("ready") - 1);
        add_assoc_bool(return_value, "paged_kv_cache_ready", ready != NULL && zend_is_true(ready));
    }
    add_assoc_bool(return_value, "native_tokenizer_tokens_loaded", intern->gguf.tokenizer_tokens_loaded);
    add_assoc_bool(return_value, "native_tokenizer_lookup_loaded", intern->gguf.tokenizer_lookup_loaded);
    add_assoc_bool(return_value, "native_tokenizer_merges_loaded", intern->gguf.tokenizer_merges_loaded);
    add_assoc_long(return_value, "native_map_bytes", (zend_long) intern->native_map_size);
    add_assoc_long(
        return_value,
        "native_tensor_index_count",
        (zend_long) zend_hash_num_elements(Z_ARRVAL(intern->tensor_index))
    );
    add_assoc_long(
        return_value,
        "native_tokenizer_token_count",
        (zend_long) zend_hash_num_elements(Z_ARRVAL(intern->tokenizer_tokens))
    );
    add_assoc_long(
        return_value,
        "native_tokenizer_merge_count",
        (zend_long) zend_hash_num_elements(Z_ARRVAL(intern->tokenizer_merges))
    );
    add_assoc_long(return_value, "tokenizer_bos_id", intern->gguf.tokenizer_bos_id);
    add_assoc_long(return_value, "tokenizer_eos_id", intern->gguf.tokenizer_eos_id);
    add_assoc_long(return_value, "tokenizer_unknown_id", intern->gguf.tokenizer_unknown_id);
    add_assoc_long(return_value, "tokenizer_pad_id", intern->gguf.tokenizer_pad_id);
    if (intern->gguf.architecture != NULL) {
        add_assoc_str(return_value, "architecture", zend_string_copy(intern->gguf.architecture));
    }
    if (intern->gguf.tokenizer_model != NULL) {
        add_assoc_str(return_value, "tokenizer_model", zend_string_copy(intern->gguf.tokenizer_model));
    }
}
