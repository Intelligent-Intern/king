/*
 * Root proto predicate leafs: expose the small public presence checks for
 * any proto symbol, schemas specifically, and enums specifically.
 */

PHP_FUNCTION(king_proto_is_defined)
{
    zend_string *name;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_BOOL(king_iibin_is_defined(name));
}

PHP_FUNCTION(king_proto_is_schema_defined)
{
    zend_string *schema_name;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(schema_name)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_BOOL(king_iibin_is_schema_defined(schema_name));
}

PHP_FUNCTION(king_proto_is_enum_defined)
{
    zend_string *enum_name;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(enum_name)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_BOOL(king_iibin_is_enum_defined(enum_name));
}
