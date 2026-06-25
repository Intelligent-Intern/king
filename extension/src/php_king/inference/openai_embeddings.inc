static zend_string *king_inference_openai_embeddings_payload_model_name(zval *model_value, zval *payload)
{
    zval *requested = king_inference_array_find(payload, "model");
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ_P(model_value));

    if (requested != NULL && Z_TYPE_P(requested) == IS_STRING && Z_STRLEN_P(requested) > 0) {
        return zend_string_copy(Z_STR_P(requested));
    }
    if (model->name != NULL && ZSTR_LEN(model->name) > 0) {
        return zend_string_copy(model->name);
    }

    return zend_string_init("local-quantized-model", sizeof("local-quantized-model") - 1, 0);
}

static zend_string *king_inference_openai_embeddings_configured_tensor_name(
    king_inference_model_object *model,
    zval *options)
{
    zval *field;

    field = king_inference_array_find(options, "embedding_tensor");
    if (field != NULL && Z_TYPE_P(field) == IS_STRING && Z_STRLEN_P(field) > 0) {
        return zend_string_copy(Z_STR_P(field));
    }

    field = king_inference_array_find(&model->config, "embedding_tensor");
    if (field != NULL && Z_TYPE_P(field) == IS_STRING && Z_STRLEN_P(field) > 0) {
        return zend_string_copy(Z_STR_P(field));
    }

    field = king_inference_array_find(&model->config, "token_embedding_tensor");
    if (field != NULL && Z_TYPE_P(field) == IS_STRING && Z_STRLEN_P(field) > 0) {
        return zend_string_copy(Z_STR_P(field));
    }

    return NULL;
}

static zend_string *king_inference_openai_embeddings_default_tensor_name(king_inference_model_object *model)
{
    static const char *candidates[] = {
        "token_embd.weight",
        "tok_embeddings.weight",
        "model.embed_tokens.weight",
        "transformer.wte.weight",
        "gpt_neox.embed_in.weight",
        NULL
    };
    size_t i;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return NULL;
    }

    for (i = 0; candidates[i] != NULL; i++) {
        if (zend_hash_str_exists(Z_ARRVAL(model->tensor_index), candidates[i], strlen(candidates[i]))) {
            return zend_string_init(candidates[i], strlen(candidates[i]), 0);
        }
    }

    return NULL;
}

static zend_result king_inference_openai_embeddings_resolve_view(
    king_inference_model_object *model,
    zval *options,
    zval *return_value,
    king_inference_tensor_native_view *view,
    zend_string **tensor_name_out,
    zend_ulong *dimensions_out,
    zend_ulong *vocab_out)
{
    zend_string *tensor = king_inference_openai_embeddings_configured_tensor_name(model, options);
    zend_ulong dimensions;
    zend_ulong vocab;
    zend_long max_dimensions = king_inference_openai_option_long(options, "max_embedding_dimensions", 8192);

    *tensor_name_out = NULL;
    *dimensions_out = 0;
    *vocab_out = 0;

    if (tensor == NULL) {
        tensor = king_inference_openai_embeddings_default_tensor_name(model);
    }
    if (tensor == NULL) {
        return king_inference_openai_error_response(
            return_value,
            500,
            "King embedding endpoint requires an embedding_tensor or a known token embedding tensor in the loaded model.",
            "server_error"
        );
    }

    if (king_inference_tensor_native_view_resolve(model, tensor, view) != SUCCESS) {
        zend_string_release(tensor);
        if (EG(exception)) {
            zend_clear_exception();
        }
        return king_inference_openai_error_response(
            return_value,
            500,
            "King embedding tensor could not be resolved from the loaded model.",
            "server_error"
        );
    }
    if (view->rank != 2
        || king_inference_tensor_dimension_at(view->dimensions, 0, &dimensions) != SUCCESS
        || king_inference_tensor_dimension_at(view->dimensions, 1, &vocab) != SUCCESS
        || dimensions == 0
        || vocab == 0
        || (max_dimensions > 0 && dimensions > (zend_ulong) max_dimensions)) {
        zend_string_release(tensor);
        return king_inference_openai_error_response(
            return_value,
            500,
            "King embedding tensor must be a bounded rank-2 token embedding matrix.",
            "server_error"
        );
    }

    *tensor_name_out = tensor;
    *dimensions_out = dimensions;
    *vocab_out = vocab;
    return SUCCESS;
}

static zend_result king_inference_openai_embeddings_tokenize_text(
    king_inference_model_object *model,
    zend_string *text,
    zval *tokens_out)
{
    zval encoded;
    zval *tokens;

    if (king_inference_tokenizer_encode(model, text, &encoded) != SUCCESS) {
        return FAILURE;
    }

    tokens = king_inference_array_find(&encoded, "tokens");
    if (tokens == NULL || Z_TYPE_P(tokens) != IS_ARRAY) {
        zval_ptr_dtor(&encoded);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native tokenizer returned no token list.");
        return FAILURE;
    }

    ZVAL_COPY(tokens_out, tokens);
    zval_ptr_dtor(&encoded);
    return SUCCESS;
}

static bool king_inference_openai_embeddings_list_is_long_list(zval *value)
{
    zval *entry;

    if (!king_inference_openai_array_is_list(value)) {
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry) {
        if (Z_TYPE_P(entry) != IS_LONG) {
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static zend_result king_inference_openai_embeddings_add_vector(
    zval *data,
    king_inference_tensor_native_view *view,
    zval *tokens,
    zend_ulong index,
    zend_ulong dimensions,
    zend_ulong vocab,
    zend_ulong max_tokens,
    zend_ulong *total_tokens)
{
    zend_ulong token_count = (zend_ulong) zend_hash_num_elements(Z_ARRVAL_P(tokens));
    double *sums;
    zend_ulong col;
    zval *token;
    zval item;
    zval embedding;

    if (token_count == 0 || token_count > max_tokens || dimensions > (zend_ulong) (SIZE_MAX / sizeof(double))) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Embedding input token count or dimension exceeds configured limits.");
        return FAILURE;
    }

    sums = ecalloc((size_t) dimensions, sizeof(double));
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tokens), token) {
        zend_ulong token_id;
        if (Z_TYPE_P(token) != IS_LONG || Z_LVAL_P(token) < 0 || (zend_ulong) Z_LVAL_P(token) >= vocab) {
            efree(sums);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "Embedding token id is outside the model vocabulary.");
            return FAILURE;
        }
        token_id = (zend_ulong) Z_LVAL_P(token);
        if (token_id > ZEND_ULONG_MAX / dimensions) {
            efree(sums);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "Embedding token offset exceeds native tensor bounds.");
            return FAILURE;
        }
        for (col = 0; col < dimensions; col++) {
            double value;
            if (king_inference_tensor_value_at(view, token_id * dimensions + col, &value) != SUCCESS) {
                efree(sums);
                return FAILURE;
            }
            sums[col] += value;
        }
    } ZEND_HASH_FOREACH_END();

    array_init(&item);
    add_assoc_string(&item, "object", "embedding");
    add_assoc_long(&item, "index", (zend_long) index);
    array_init(&embedding);
    for (col = 0; col < dimensions; col++) {
        add_next_index_double(&embedding, sums[col] / (double) token_count);
    }
    add_assoc_zval(&item, "embedding", &embedding);
    add_next_index_zval(data, &item);

    efree(sums);
    *total_tokens += token_count;
    return SUCCESS;
}

static zend_result king_inference_openai_embeddings_add_string_input(
    zval *data,
    king_inference_model_object *model,
    king_inference_tensor_native_view *view,
    zend_string *input,
    zend_ulong index,
    zend_ulong dimensions,
    zend_ulong vocab,
    zend_ulong max_tokens,
    zend_ulong *total_tokens)
{
    zval tokens;
    zend_result result;

    if (king_inference_openai_embeddings_tokenize_text(model, input, &tokens) != SUCCESS) {
        return FAILURE;
    }
    result = king_inference_openai_embeddings_add_vector(
        data,
        view,
        &tokens,
        index,
        dimensions,
        vocab,
        max_tokens,
        total_tokens
    );
    zval_ptr_dtor(&tokens);
    return result;
}

static zend_result king_inference_openai_embeddings_payload_data(
    zval *data,
    king_inference_model_object *model,
    king_inference_tensor_native_view *view,
    zval *input,
    zend_ulong dimensions,
    zend_ulong vocab,
    zend_ulong max_inputs,
    zend_ulong max_tokens,
    zend_ulong *total_tokens)
{
    zend_ulong index = 0;
    zval *entry;

    array_init(data);
    *total_tokens = 0;

    if (Z_TYPE_P(input) == IS_STRING) {
        return king_inference_openai_embeddings_add_string_input(
            data,
            model,
            view,
            Z_STR_P(input),
            0,
            dimensions,
            vocab,
            max_tokens,
            total_tokens
        );
    }

    if (!king_inference_openai_array_is_list(input)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Embeddings input must be a string, string list, token list, or token-list batch.");
        return FAILURE;
    }
    if (zend_hash_num_elements(Z_ARRVAL_P(input)) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Embeddings input must not be empty.");
        return FAILURE;
    }

    if (king_inference_openai_embeddings_list_is_long_list(input)) {
        return king_inference_openai_embeddings_add_vector(
            data,
            view,
            input,
            0,
            dimensions,
            vocab,
            max_tokens,
            total_tokens
        );
    }
    if (zend_hash_num_elements(Z_ARRVAL_P(input)) > max_inputs) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Embeddings input batch size exceeds configured limits.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(input), entry) {
        zend_result result;
        if (Z_TYPE_P(entry) == IS_STRING) {
            result = king_inference_openai_embeddings_add_string_input(
                data,
                model,
                view,
                Z_STR_P(entry),
                index,
                dimensions,
                vocab,
                max_tokens,
                total_tokens
            );
        } else if (Z_TYPE_P(entry) == IS_ARRAY && king_inference_openai_embeddings_list_is_long_list(entry)) {
            result = king_inference_openai_embeddings_add_vector(
                data,
                view,
                entry,
                index,
                dimensions,
                vocab,
                max_tokens,
                total_tokens
            );
        } else {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "Embeddings input batch entries must be strings or token-id lists.");
            return FAILURE;
        }
        if (result != SUCCESS) {
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_inference_openai_embeddings_payload_response(
    zval *return_value,
    zval *model_value,
    zval *payload,
    zval *options)
{
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ_P(model_value));
    zval *input = king_inference_array_find(payload, "input");
    zval *encoding = king_inference_array_find(payload, "encoding_format");
    zend_long max_inputs = king_inference_openai_option_long(options, "max_embedding_inputs", 2048);
    zend_long max_tokens = king_inference_openai_option_long(options, "max_embedding_tokens", 8192);
    king_inference_tensor_native_view view;
    zend_string *tensor_name = NULL;
    zend_string *model_name = NULL;
    zend_string *json = NULL;
    zend_ulong dimensions = 0;
    zend_ulong vocab = 0;
    zend_ulong total_tokens = 0;
    zval data;
    zval response_payload;
    zval usage;
    zend_result result;

    if (input == NULL) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Embeddings request requires input.",
            "invalid_request_error"
        );
    }
    if (encoding != NULL
        && (Z_TYPE_P(encoding) != IS_STRING || !zend_string_equals_literal(Z_STR_P(encoding), "float"))) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "King embeddings currently support encoding_format=float.",
            "invalid_request_error"
        );
    }
    if (max_inputs <= 0 || max_tokens <= 0) {
        return king_inference_openai_error_response(
            return_value,
            500,
            "King embedding router limits must be positive.",
            "server_error"
        );
    }
    if (king_inference_openai_embeddings_resolve_view(
        model,
        options,
        return_value,
        &view,
        &tensor_name,
        &dimensions,
        &vocab
    ) != SUCCESS) {
        return Z_TYPE_P(return_value) == IS_ARRAY ? SUCCESS : FAILURE;
    }

    result = king_inference_openai_embeddings_payload_data(
        &data,
        model,
        &view,
        input,
        dimensions,
        vocab,
        (zend_ulong) max_inputs,
        (zend_ulong) max_tokens,
        &total_tokens
    );
    if (result != SUCCESS) {
        bool validation_error = EG(exception) != NULL
            && instanceof_function(EG(exception)->ce, king_ce_validation_exception);
        zend_string_release(tensor_name);
        zval_ptr_dtor(&data);
        if (EG(exception)) {
            zend_clear_exception();
            return king_inference_openai_error_response(
                return_value,
                validation_error ? 400 : 500,
                validation_error
                    ? "Embedding request could not be processed within configured limits."
                    : "King embedding computation failed for the loaded model.",
                validation_error ? "invalid_request_error" : "server_error"
            );
        }
        return FAILURE;
    }

    model_name = king_inference_openai_embeddings_payload_model_name(model_value, payload);
    array_init(&response_payload);
    add_assoc_string(&response_payload, "object", "list");
    add_assoc_zval(&response_payload, "data", &data);
    add_assoc_str(&response_payload, "model", model_name);
    add_assoc_str(&response_payload, "embedding_tensor", tensor_name);
    add_assoc_long(&response_payload, "dimensions", (zend_long) dimensions);
    array_init(&usage);
    add_assoc_long(&usage, "prompt_tokens", (zend_long) total_tokens);
    add_assoc_long(&usage, "total_tokens", (zend_long) total_tokens);
    add_assoc_zval(&response_payload, "usage", &usage);

    result = king_inference_openai_json_encode(&response_payload, &json);
    zval_ptr_dtor(&response_payload);
    if (result != SUCCESS) {
        return FAILURE;
    }

    king_inference_openai_http_array_response(return_value, 200, "application/json", json, false);
    zend_string_release(json);
    return SUCCESS;
}
