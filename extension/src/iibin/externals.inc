int king_proto_registry_minit(void);
void king_proto_registry_mshutdown(void);
int king_iibin_minit(void);

PHP_FUNCTION(king_proto_is_defined);
PHP_FUNCTION(king_proto_is_schema_defined);
PHP_FUNCTION(king_proto_is_enum_defined);
PHP_FUNCTION(king_proto_define_schema);
PHP_FUNCTION(king_proto_define_enum);
PHP_FUNCTION(king_proto_encode);
PHP_FUNCTION(king_proto_encode_batch);
PHP_FUNCTION(king_proto_decode);
PHP_FUNCTION(king_proto_decode_batch);
PHP_FUNCTION(king_proto_get_defined_schemas);
PHP_FUNCTION(king_proto_get_defined_enums);
