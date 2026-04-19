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

/* Batch encode: encode multiple records to amortize PHP↔C overhead */
PHP_FUNCTION(king_proto_encode_batch)
{
    zend_string *schema_name;
    zval *records;
    zval result;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ARRAY(records)
    ZEND_PARSE_PARAMETERS_END();

    if (king_iibin_encode_batch(schema_name, records, &result) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_ZVAL(&result, 0, 0);
}

/* Batch decode: decode multiple binary records at once */
PHP_FUNCTION(king_proto_decode_batch)
{
    zend_string *schema_name;
    zval *binary_data;
    zval *decode_as_object = NULL;
    zval result;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ZVAL(binary_data)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(decode_as_object)
    ZEND_PARSE_PARAMETERS_END();

    if (king_iibin_decode_batch(schema_name, binary_data, decode_as_object, &result) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_ZVAL(&result, 0, 0);
}

/* Varint batch encode for A/B benchmark */
PHP_FUNCTION(king_proto_encode_batch_varint)
{
    zend_string *schema_name;
    zval *records;
    zval result;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ARRAY(records)
    ZEND_PARSE_PARAMETERS_END();

    if (king_iibin_encode_batch_varint(schema_name, records, &result) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_ZVAL(&result, 0, 0);
}

/* Varint batch decode for A/B benchmark */
PHP_FUNCTION(king_proto_decode_batch_varint)
{
    zend_string *schema_name;
    zval *binary_data;
    zval *decode_as_object = NULL;
    zval result;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ZVAL(binary_data)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(decode_as_object)
    ZEND_PARSE_PARAMETERS_END();

    if (king_iibin_decode_batch_varint(schema_name, binary_data, decode_as_object, &result) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_ZVAL(&result, 0, 0);
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
