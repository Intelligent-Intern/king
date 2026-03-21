PHP_FUNCTION(king_proto_define_enum)
{
    zend_string *enum_name;
    zval *enum_values;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(enum_name)
        Z_PARAM_ARRAY(enum_values)
    ZEND_PARSE_PARAMETERS_END();

    if (king_iibin_define_enum(enum_name, enum_values) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_TRUE;
}
