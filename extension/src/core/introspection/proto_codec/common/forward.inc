static bool king_proto_runtime_encode_schema_payload(
    smart_str *buf,
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    zval *data
);

static bool king_proto_runtime_decode_schema_payload(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    const unsigned char *buf,
    size_t buf_len,
    bool decode_as_object,
    zval *decoded_result
);

static bool king_proto_runtime_append_encoded_field_value(
    smart_str *buf,
    const king_proto_runtime_field *field,
    zval *value
);

static bool king_proto_runtime_decode_field_value(
    const king_proto_runtime_field *field,
    zend_string *schema_name,
    const unsigned char **buf_ptr,
    const unsigned char *buf_end,
    bool decode_as_object,
    zval *decoded_value
);
