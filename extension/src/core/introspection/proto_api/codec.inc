/*
 * Root proto codec leafs: expose the public schema-bound encode/decode
 * surface and delegate the real runtime work to the active IIBIN codec.
 */

PHP_FUNCTION(king_proto_encode)
{
    zend_string *schema_name;
    zval *data;
    smart_str encoded = {0};

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ZVAL(data)
    ZEND_PARSE_PARAMETERS_END();

    if (king_iibin_encode(schema_name, data, &encoded) != SUCCESS) {
        RETURN_THROWS();
    }

    if (encoded.s == NULL) {
        RETURN_EMPTY_STRING();
    }

    smart_str_0(&encoded);
    RETURN_STR(encoded.s);
}

PHP_FUNCTION(king_proto_encode_batch)
{
    zend_string *schema_name;
    zval *records;
    zval *record;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ARRAY(records)
    ZEND_PARSE_PARAMETERS_END();

    array_init(return_value);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(records), record) {
        smart_str encoded = {0};
        zval encoded_value;

        if (king_iibin_encode(schema_name, record, &encoded) != SUCCESS) {
            zval_ptr_dtor(return_value);
            RETURN_THROWS();
        }

        if (encoded.s == NULL) {
            ZVAL_EMPTY_STRING(&encoded_value);
        } else {
            smart_str_0(&encoded);
            ZVAL_STR(&encoded_value, encoded.s);
        }

        add_next_index_zval(return_value, &encoded_value);
    } ZEND_HASH_FOREACH_END();
}

PHP_FUNCTION(king_proto_decode)
{
    zend_string *schema_name;
    zend_string *binary_data;
    zval *decode_as_object = NULL;
    zval decoded_result;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(schema_name)
        Z_PARAM_STR(binary_data)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(decode_as_object)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_UNDEF(&decoded_result);
    if (king_iibin_decode(
            schema_name,
            binary_data,
            decode_as_object,
            &decoded_result
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_ZVAL(&decoded_result, 0, 1);
}

PHP_FUNCTION(king_proto_decode_batch)
{
    zend_string *schema_name;
    zval *binary_records;
    zval *decode_as_object = NULL;
    zval *binary_data;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ARRAY(binary_records)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(decode_as_object)
    ZEND_PARSE_PARAMETERS_END();

    array_init(return_value);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(binary_records), binary_data) {
        zval decoded_result;

        if (Z_TYPE_P(binary_data) != IS_STRING) {
            zval_ptr_dtor(return_value);
            zend_argument_value_error(2, "must contain only strings");
            RETURN_THROWS();
        }

        ZVAL_UNDEF(&decoded_result);
        if (king_iibin_decode(
                schema_name,
                Z_STR_P(binary_data),
                decode_as_object,
                &decoded_result
            ) != SUCCESS) {
            zval_ptr_dtor(return_value);
            RETURN_THROWS();
        }

        add_next_index_zval(return_value, &decoded_result);
    } ZEND_HASH_FOREACH_END();
}
