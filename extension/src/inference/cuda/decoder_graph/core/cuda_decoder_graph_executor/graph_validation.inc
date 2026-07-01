static zend_result king_inference_cuda_decoder_graph_executor_validate_terminal(
    king_inference_model_object *model,
    zval *graph,
    zval *defined
) {
    zval *terminal = king_inference_array_find(graph, "terminal");
    static const char *fields[] = {
        "hidden_output",
        "final_norm",
        "logits"
    };
    size_t i;

    if (terminal == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(terminal) != IS_ARRAY) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            NULL,
            "terminal",
            "expected_object_array"
        );
    }
    for (i = 0; i < sizeof(fields) / sizeof(fields[0]); i++) {
        zval *field = king_inference_array_find(terminal, fields[i]);

        if (field == NULL) {
            continue;
        }
        if (Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "field_type",
                NULL,
                fields[i],
                "terminal_reference_must_be_non_empty_string"
            );
        }
        if (!zend_hash_exists(Z_ARRVAL_P(defined), Z_STR_P(field))) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "dangling_read",
                NULL,
                fields[i],
                Z_STRVAL_P(field)
            );
        }
    }

    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_graph(
    king_inference_model_object *model,
    zval *graph
) {
    zval *ops;
    zval *entry;
    zval defined;
    zval lengths;
    zend_ulong op_count = 0;
    zend_ulong supported_count = 0;
    zend_result status = SUCCESS;

    if (!model->cuda_decoder_graph_executor_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_executor_unavailable"
        );
        return FAILURE;
    }
    if (graph == NULL || Z_TYPE_P(graph) != IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_invalid");
        return FAILURE;
    }

    ops = king_inference_array_find(graph, "ops");
    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(ops)) == 0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ops_missing");
        return FAILURE;
    }

    array_init(&defined);
    array_init(&lengths);
    status = king_inference_cuda_decoder_graph_executor_validate_graph_inputs(
        model,
        graph,
        &defined,
        &lengths
    );
    if (status != SUCCESS) {
        zval_ptr_dtor(&lengths);
        zval_ptr_dtor(&defined);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zend_string *id = NULL;
        zend_string *op_name = NULL;
        zend_ulong output_length = 0;
        zval marker;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "node",
                NULL,
                "ops",
                "entry_must_be_object_array"
            );
            break;
        }
        if (king_inference_graph_required_string(entry, "id", &id) != SUCCESS
            || king_inference_graph_required_string(entry, "op", &op_name) != SUCCESS) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "node",
                id,
                id == NULL ? "id" : "op",
                "id_and_op_required"
            );
            break;
        }
        if (zend_hash_exists(Z_ARRVAL(defined), id)) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "ambiguous_write",
                id,
                "id",
                "duplicate_output_id"
            );
            break;
        }
        if (!king_inference_cuda_decoder_graph_executor_supported_op(op_name)) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "unsupported",
                id,
                "op",
                ZSTR_VAL(op_name)
            );
            break;
        }
        if (king_inference_cuda_decoder_graph_executor_validate_op_contract(
            model,
            entry,
            id,
            op_name,
            &defined,
            &lengths,
            &output_length
        ) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_set_validated_length(
                model,
                &lengths,
                id,
                output_length
        ) != SUCCESS) {
            status = FAILURE;
            break;
        }
        ZVAL_TRUE(&marker);
        zend_hash_update(Z_ARRVAL(defined), id, &marker);
        op_count++;
        supported_count++;
    } ZEND_HASH_FOREACH_END();

    if (status == SUCCESS) {
        status = king_inference_cuda_decoder_graph_executor_validate_terminal(model, graph, &defined);
    }
    zval_ptr_dtor(&lengths);
    zval_ptr_dtor(&defined);
    if (status != SUCCESS) {
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_graph_count++;
    model->cuda_decoder_graph_executor_last_op_count = op_count;
    model->cuda_decoder_graph_executor_last_supported_op_count = supported_count;
    model->cuda_decoder_graph_executor_result = 0;
    model->cuda_decoder_graph_executor_error[0] = '\0';
    return SUCCESS;
}
