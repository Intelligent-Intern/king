static bool king_inference_king_native_prompt_should_stop_token(
    king_inference_model_object *model,
    zend_ulong token_id)
{
    return (model->gguf.tokenizer_eos_id >= 0 && token_id == (zend_ulong) model->gguf.tokenizer_eos_id)
        || (model->gguf.tokenizer_bos_id >= 0 && token_id == (zend_ulong) model->gguf.tokenizer_bos_id);
}

static const char *king_inference_king_native_memmem(
    const char *haystack,
    size_t haystack_len,
    const char *needle,
    size_t needle_len)
{
    size_t offset;

    if (needle_len == 0 || haystack_len < needle_len) {
        return NULL;
    }
    for (offset = 0; offset <= haystack_len - needle_len; offset++) {
        if (memcmp(haystack + offset, needle, needle_len) == 0) {
            return haystack + offset;
        }
    }
    return NULL;
}

static zend_result king_inference_king_native_prompt_stop_list(zval *request, zval *stops)
{
    zval *stop = king_inference_array_find(request, "stop");
    zval *entry;

    array_init(stops);
    if (stop == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(stop) == IS_STRING) {
        add_next_index_str(stops, zend_string_copy(Z_STR_P(stop)));
        return SUCCESS;
    }
    if (!king_inference_openai_array_is_list(stop)) {
        zval_ptr_dtor(stops);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native prompt stop must be a string or list of strings.");
        return FAILURE;
    }
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stop), entry) {
        if (Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
            zval_ptr_dtor(stops);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King native prompt stop entries must be non-empty strings.");
            return FAILURE;
        }
        add_next_index_str(stops, zend_string_copy(Z_STR_P(entry)));
    } ZEND_HASH_FOREACH_END();
    return SUCCESS;
}

static bool king_inference_king_native_prompt_stop_exists(zval *stops, const char *stop)
{
    zval *entry;
    size_t stop_length = strlen(stop);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stops), entry) {
        if (Z_TYPE_P(entry) == IS_STRING
            && Z_STRLEN_P(entry) == stop_length
            && memcmp(Z_STRVAL_P(entry), stop, stop_length) == 0) {
            return true;
        }
    } ZEND_HASH_FOREACH_END();
    return false;
}

static void king_inference_king_native_prompt_add_default_stops(
    king_inference_model_object *model,
    zval *stops)
{
    static const char *turn_stops[] = {
        "<turn|>",
        "<|turn>",
        "<end_of_turn>",
        "<start_of_turn>"
    };
    size_t index;

    if (Z_TYPE(model->tokenizer_lookup) != IS_ARRAY) {
        return;
    }
    for (index = 0; index < sizeof(turn_stops) / sizeof(turn_stops[0]); index++) {
        const char *stop = turn_stops[index];
        size_t stop_length = strlen(stop);

        if (zend_hash_str_exists(Z_ARRVAL(model->tokenizer_lookup), stop, stop_length)
            && !king_inference_king_native_prompt_stop_exists(stops, stop)) {
            add_next_index_stringl(stops, stop, stop_length);
        }
    }
}

static bool king_inference_king_native_prompt_piece_matches_stop(zend_string *piece, zval *stops)
{
    zval *stop;

    if (piece == NULL || ZSTR_LEN(piece) == 0 || stops == NULL || Z_TYPE_P(stops) != IS_ARRAY) {
        return false;
    }
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stops), stop) {
        if (Z_TYPE_P(stop) == IS_STRING
            && ZSTR_LEN(piece) == Z_STRLEN_P(stop)
            && memcmp(ZSTR_VAL(piece), Z_STRVAL_P(stop), ZSTR_LEN(piece)) == 0) {
            return true;
        }
    } ZEND_HASH_FOREACH_END();
    return false;
}

static size_t king_inference_king_native_prompt_pending_keep(smart_str *pending, zval *stops)
{
    zval *stop;
    size_t keep = 0;

    if (pending->s == NULL) {
        return 0;
    }
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stops), stop) {
        size_t max_len;
        size_t length;

        if (Z_TYPE_P(stop) != IS_STRING || Z_STRLEN_P(stop) <= 1) {
            continue;
        }
        max_len = ZSTR_LEN(pending->s) < Z_STRLEN_P(stop) - 1 ? ZSTR_LEN(pending->s) : Z_STRLEN_P(stop) - 1;
        for (length = max_len; length > 0; length--) {
            if (memcmp(ZSTR_VAL(pending->s) + ZSTR_LEN(pending->s) - length, Z_STRVAL_P(stop), length) == 0) {
                if (length > keep) {
                    keep = length;
                }
                break;
            }
        }
    } ZEND_HASH_FOREACH_END();
    return keep;
}

static zend_result king_inference_king_native_prompt_emit_text(
    king_inference_stream_object *stream,
    smart_str *pending,
    zend_string *piece,
    zval *stops,
    bool *stopped)
{
    zval *stop;
    const char *stop_at = NULL;
    size_t stop_offset = 0;
    size_t keep;
    size_t emit_len;

    *stopped = false;
    if (piece == NULL || ZSTR_LEN(piece) == 0) {
        return SUCCESS;
    }
    if (zend_hash_num_elements(Z_ARRVAL_P(stops)) == 0) {
        add_next_index_str(&stream->native_events, zend_string_copy(piece));
        return SUCCESS;
    }

    smart_str_append(pending, piece);
    smart_str_0(pending);
    if (pending->s == NULL) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stops), stop) {
        const char *match;

        if (Z_TYPE_P(stop) != IS_STRING || Z_STRLEN_P(stop) == 0) {
            continue;
        }
        match = king_inference_king_native_memmem(
            ZSTR_VAL(pending->s),
            ZSTR_LEN(pending->s),
            Z_STRVAL_P(stop),
            Z_STRLEN_P(stop)
        );
        if (match != NULL && (stop_at == NULL || match < stop_at)) {
            stop_at = match;
        }
    } ZEND_HASH_FOREACH_END();

    if (stop_at != NULL) {
        stop_offset = (size_t) (stop_at - ZSTR_VAL(pending->s));
        if (stop_offset > 0) {
            add_next_index_stringl(&stream->native_events, ZSTR_VAL(pending->s), stop_offset);
        }
        smart_str_free(pending);
        *stopped = true;
        return SUCCESS;
    }

    keep = king_inference_king_native_prompt_pending_keep(pending, stops);
    emit_len = ZSTR_LEN(pending->s) - keep;
    if (emit_len > 0) {
        add_next_index_stringl(&stream->native_events, ZSTR_VAL(pending->s), emit_len);
    }
    if (keep > 0) {
        zend_string *suffix = zend_string_init(ZSTR_VAL(pending->s) + emit_len, keep, 0);
        smart_str_free(pending);
        smart_str_append(pending, suffix);
        zend_string_release(suffix);
    } else {
        smart_str_free(pending);
    }

    return SUCCESS;
}

static zend_result king_inference_king_native_prompt_flush_pending(
    king_inference_stream_object *stream,
    smart_str *pending)
{
    if (pending->s != NULL && ZSTR_LEN(pending->s) > 0) {
        add_next_index_str(&stream->native_events, zend_string_copy(pending->s));
    }
    smart_str_free(pending);
    return SUCCESS;
}

static zend_result king_inference_king_native_prompt_tokens(
    king_inference_model_object *model,
    zval *encoded,
    zval *tokenized_prompt,
    zend_ulong *count_out)
{
    zval *tokens = king_inference_array_find(encoded, "tokens");
    zval prompt_tokens;
    zval *entry;
    zend_ulong source_count;
    zend_ulong source_index = 0;
    zend_ulong copied = 0;
    zend_ulong context = model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH];
    zend_ulong start = 0;
    zend_ulong tail_count;
    zend_long first_token = -1;
    bool has_bos = model->gguf.tokenizer_bos_id >= 0;
    bool source_starts_with_bos = false;
    bool add_bos = false;

    if (tokens == NULL || Z_TYPE_P(tokens) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native prompt tokenizer returned no tokens array.");
        return FAILURE;
    }
    source_count = (zend_ulong) zend_hash_num_elements(Z_ARRVAL_P(tokens));
    entry = zend_hash_index_find(Z_ARRVAL_P(tokens), 0);
    if (entry != NULL && Z_TYPE_P(entry) == IS_LONG) {
        first_token = Z_LVAL_P(entry);
        source_starts_with_bos = has_bos && first_token == model->gguf.tokenizer_bos_id;
    }
    add_bos = has_bos && !source_starts_with_bos;

    if (context > 0 && source_count + (add_bos ? 1 : 0) > context) {
        if (has_bos && context > 1) {
            add_bos = true;
            tail_count = context - 1;
            if (source_starts_with_bos && source_count > 0) {
                source_count--;
                start = 1;
            }
            if (source_count > tail_count) {
                start += source_count - tail_count;
            }
        } else {
            add_bos = false;
            if (source_count > context) {
                start = source_count - context;
            }
        }
    }

    array_init(tokenized_prompt);
    array_init(&prompt_tokens);
    if (add_bos) {
        add_next_index_long(&prompt_tokens, model->gguf.tokenizer_bos_id);
        copied++;
    }
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tokens), entry) {
        if (source_index++ < start) {
            continue;
        }
        if (Z_TYPE_P(entry) != IS_LONG || Z_LVAL_P(entry) < 0) {
            zval_ptr_dtor(&prompt_tokens);
            zval_ptr_dtor(tokenized_prompt);
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native prompt tokenizer returned an invalid token id.");
            return FAILURE;
        }
        add_next_index_long(&prompt_tokens, Z_LVAL_P(entry));
        copied++;
    } ZEND_HASH_FOREACH_END();

    if (copied == 0) {
        zval_ptr_dtor(&prompt_tokens);
        zval_ptr_dtor(tokenized_prompt);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native prompt produced no tokens.");
        return FAILURE;
    }

    add_assoc_zval(tokenized_prompt, "tokens", &prompt_tokens);
    *count_out = copied;
    return SUCCESS;
}

static void king_inference_king_native_prompt_decode_options(king_inference_stream_object *stream, zval *decode_options)
{
    array_init(decode_options);
    king_inference_king_native_copy_decode_option(decode_options, &stream->request, "temperature");
    king_inference_king_native_copy_decode_option(decode_options, &stream->request, "top_k");
    king_inference_king_native_copy_decode_option(decode_options, &stream->request, "top_p");
    king_inference_king_native_copy_decode_option(decode_options, &stream->request, "seed");
}

static zend_result king_inference_king_native_prompt_run_graph(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph,
    zval *graph_options,
    zval *state,
    zend_ulong *token_id_out)
{
    zval working_graph;
    zval graph_result;
    zval *next_state;
    zend_result status;

    ZVAL_DUP(&working_graph, graph);
    SEPARATE_ARRAY(&working_graph);
    if (!Z_ISUNDEF_P(state) && king_inference_array_find(&working_graph, "state") == NULL) {
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
    next_state = king_inference_array_find(&graph_result, "state");
    if (next_state == NULL || Z_TYPE_P(next_state) != IS_ARRAY) {
        zval_ptr_dtor(&graph_result);
        zval_ptr_dtor(&working_graph);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native prompt graph produced no KV state.");
        return FAILURE;
    }
    if (token_id_out != NULL
        && king_inference_king_native_token_id_from_result(&graph_result, &working_graph, token_id_out) != SUCCESS) {
        zval_ptr_dtor(&graph_result);
        zval_ptr_dtor(&working_graph);
        return FAILURE;
    }
    if (token_id_out != NULL) {
        king_inference_king_native_update_decoder_metrics(stream, &graph_result, &working_graph, *token_id_out);
    }
    if (!Z_ISUNDEF_P(state)) {
        zval_ptr_dtor(state);
    }
    ZVAL_COPY(state, next_state);
    zval_ptr_dtor(&graph_result);
    zval_ptr_dtor(&working_graph);
    return SUCCESS;
}

static zend_result king_inference_king_native_append_prompt_generated_tokens(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *prompt_text,
    zval *graph_options,
    zend_long max_tokens)
{
    zval encoded;
    zval tokenized_prompt;
    zval decode_options;
    zval stops;
    zval state;
    zend_ulong token_count = 0;
    zend_ulong position;
    zend_ulong token_id = 0;
    zend_long generated = 0;
    smart_str pending = {0};
    bool stopped = false;

    ZVAL_UNDEF(&encoded);
    ZVAL_UNDEF(&tokenized_prompt);
    ZVAL_UNDEF(&decode_options);
    ZVAL_UNDEF(&stops);
    ZVAL_UNDEF(&state);

    if (prompt_text == NULL || Z_TYPE_P(prompt_text) != IS_STRING) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native prompt generation requires native_prompt_text.");
        return FAILURE;
    }
    if (king_inference_tokenizer_encode(model, Z_STR_P(prompt_text), &encoded) != SUCCESS
        || king_inference_king_native_prompt_tokens(model, &encoded, &tokenized_prompt, &token_count) != SUCCESS) {
        if (!Z_ISUNDEF(encoded)) {
            zval_ptr_dtor(&encoded);
        }
        return FAILURE;
    }
    if (king_inference_king_native_prompt_stop_list(&stream->request, &stops) != SUCCESS) {
        zval_ptr_dtor(&tokenized_prompt);
        zval_ptr_dtor(&encoded);
        return FAILURE;
    }
    king_inference_king_native_prompt_add_default_stops(model, &stops);
    king_inference_king_native_prompt_decode_options(stream, &decode_options);
    ZVAL_UNDEF(&state);

    for (position = 0; position < token_count; position++) {
        zval graph;
        zval *emit_token;

        ZVAL_UNDEF(&graph);
        add_assoc_bool(&decode_options, "emit_token", position + 1 == token_count);
        if (king_inference_token_decode_graph_array(model, &tokenized_prompt, position, &decode_options, &graph) != SUCCESS) {
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            smart_str_free(&pending);
            return FAILURE;
        }
        emit_token = king_inference_array_find(&graph, "terminal");
        if (emit_token != NULL) {
            emit_token = king_inference_array_find(emit_token, "emits_token");
        }
        if (king_inference_king_native_prompt_run_graph(
                model,
                stream,
                &graph,
                graph_options,
                &state,
                emit_token != NULL && zend_is_true(emit_token) ? &token_id : NULL
            ) != SUCCESS) {
            zval_ptr_dtor(&graph);
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            smart_str_free(&pending);
            return FAILURE;
        }
        zval_ptr_dtor(&graph);
    }

    while (generated < max_tokens && !stopped) {
        zval graph;
        zval token;
        zend_string *piece;

        ZVAL_UNDEF(&graph);
        if (king_inference_king_native_prompt_should_stop_token(model, token_id)) {
            break;
        }
        piece = king_inference_tokenizer_decode_token(model, token_id);
        if (piece == NULL) {
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            smart_str_free(&pending);
            return FAILURE;
        }
        if (king_inference_king_native_prompt_emit_text(stream, &pending, piece, &stops, &stopped) != SUCCESS) {
            zend_string_release(piece);
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            smart_str_free(&pending);
            return FAILURE;
        }
        zend_string_release(piece);
        generated++;
        if (generated >= max_tokens || stopped) {
            break;
        }
        if (position >= (zend_ulong) ZEND_LONG_MAX || token_id > (zend_ulong) ZEND_LONG_MAX) {
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            smart_str_free(&pending);
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "King native prompt generation exceeded supported token or position limits.");
            return FAILURE;
        }

        ZVAL_LONG(&token, (zend_long) token_id);
        add_assoc_bool(&decode_options, "emit_token", true);
        if (king_inference_token_decode_graph_array(model, &token, position, &decode_options, &graph) != SUCCESS
            || king_inference_king_native_prompt_run_graph(model, stream, &graph, graph_options, &state, &token_id) != SUCCESS) {
            if (!Z_ISUNDEF(graph)) {
                zval_ptr_dtor(&graph);
            }
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            if (!Z_ISUNDEF(state)) {
                zval_ptr_dtor(&state);
            }
            smart_str_free(&pending);
            return FAILURE;
        }
        position++;
        zval_ptr_dtor(&graph);
    }

    if (!stopped) {
        king_inference_king_native_prompt_flush_pending(stream, &pending);
    } else {
        smart_str_free(&pending);
    }
    if (zend_hash_num_elements(Z_ARRVAL(stream->native_events)) == 0) {
        add_next_index_stringl(&stream->native_events, "", 0);
    }

    zval_ptr_dtor(&decode_options);
    zval_ptr_dtor(&stops);
    zval_ptr_dtor(&tokenized_prompt);
    zval_ptr_dtor(&encoded);
    if (!Z_ISUNDEF(state)) {
        zval_ptr_dtor(&state);
    }
    return SUCCESS;
}
