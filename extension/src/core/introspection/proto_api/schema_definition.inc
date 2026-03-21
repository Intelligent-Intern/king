PHP_FUNCTION(king_proto_define_schema)
{
    zend_string *schema_name;
    zval *schema_definition;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(schema_name)
        Z_PARAM_ARRAY(schema_definition)
    ZEND_PARSE_PARAMETERS_END();

    if (king_iibin_define_schema(schema_name, schema_definition) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_TRUE;
}
