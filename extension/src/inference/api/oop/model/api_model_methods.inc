PHP_METHOD(King_Inference_Model, __construct)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_model_init(ZEND_THIS, config, "King\\Inference\\Model::__construct") != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, info)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_inference_model_info_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        return_value
    );
}

PHP_METHOD(King_Inference_Model, tokenize)
{
    zend_string *text;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(text)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tokenizer_encode(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        text,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, tokenDecode)
{
    zend_long token_id;
    zend_string *decoded;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(token_id)
    ZEND_PARSE_PARAMETERS_END();

    if (token_id < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token id must be non-negative.");
        RETURN_THROWS();
    }

    decoded = king_inference_tokenizer_decode_token(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        (zend_ulong) token_id
    );
    if (decoded == NULL) {
        RETURN_THROWS();
    }
    RETURN_STR(decoded);
}

PHP_METHOD(King_Inference_Model, tokenDecodeGraph)
{
    zval *token, *options = NULL;
    zend_long position;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(token)
        Z_PARAM_LONG(position)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();
    if (position < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph position must be non-negative.");
        RETURN_THROWS();
    }
    if (king_inference_token_decode_graph_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        token,
        (zend_ulong) position,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, kvCachePlan)
{
    zval *request = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(request)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_paged_kv_cache_plan(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        request,
        return_value
    );
}

PHP_METHOD(King_Inference_Model, tensorView)
{
    zend_string *name;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_view_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        name,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, tensorIndex)
{
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_tensor_index_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        options,
        return_value
    );
}

PHP_METHOD(King_Inference_Model, tensorDequantize)
{
    zend_string *name;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_dequantize_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        name,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, tensorMatmul)
{
    zend_string *name;
    zval *input;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(name)
        Z_PARAM_ARRAY(input)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_matmul_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        name,
        input,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, graphRun)
{
    zval *graph;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(graph)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_graph_run_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        graph,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

