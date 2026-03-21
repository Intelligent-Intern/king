PHP_FUNCTION(king_proto_get_defined_schemas)
{
    ZEND_PARSE_PARAMETERS_NONE();
    king_iibin_get_defined_schemas(return_value);
}

PHP_FUNCTION(king_proto_get_defined_enums)
{
    ZEND_PARSE_PARAMETERS_NONE();
    king_iibin_get_defined_enums(return_value);
}
