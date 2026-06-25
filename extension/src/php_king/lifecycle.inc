/*
 * Extension lifecycle hooks for php_king. Wires MINIT/MSHUTDOWN/RINIT/
 * RSHUTDOWN/MINFO across the module bootstrap, registry setup and per-request
 * runtime teardown paths.
 */

PHP_MINIT_FUNCTION(king)
{
    if (king_init_modules(type, module_number) != SUCCESS) {
        php_error_docref(NULL, E_WARNING, "king_init_modules failed during MINIT");
        return FAILURE;
    }

    if (king_proto_registry_minit() != SUCCESS) {
        php_error_docref(NULL, E_WARNING, "king_proto_registry_minit failed during MINIT");
        return FAILURE;
    }
    if (king_semantic_dns_registry_minit() != SUCCESS) {
        php_error_docref(NULL, E_WARNING, "king_semantic_dns_registry_minit failed during MINIT");
        king_proto_registry_mshutdown();
        return FAILURE;
    }
    if (king_cdn_cache_registry_minit() != SUCCESS) {
        php_error_docref(NULL, E_WARNING, "king_cdn_cache_registry_minit failed during MINIT");
        king_semantic_dns_registry_mshutdown();
        king_proto_registry_mshutdown();
        return FAILURE;
    }

    if (king_iibin_minit() != SUCCESS) {
        php_error_docref(NULL, E_WARNING, "king_iibin_minit failed during MINIT");
        king_cdn_cache_registry_mshutdown();
        king_semantic_dns_registry_mshutdown();
        king_proto_registry_mshutdown();
        return FAILURE;
    }

    le_king_session = zend_register_list_destructors_ex(
        king_session_dtor, NULL, "King\\Session", module_number);
    le_king_cfg = zend_register_list_destructors_ex(
        king_cfg_dtor, NULL, "King\\Config", module_number);
    le_king_mcp = zend_register_list_destructors_ex(
        king_mcp_dtor, NULL, "King\\MCP", module_number);
    le_king_ws = zend_register_list_destructors_ex(
        king_ws_dtor, NULL, "King\\WebSocket", module_number);
    le_king_request_context = zend_register_list_destructors_ex(
        king_http1_request_context_dtor, NULL, "King\\HttpRequestContext", module_number);
    king_rtp_minit(module_number);

    king_register_php_classes_and_handlers();

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(king)
{
    king_xslt_shutdown_system();
    king_http1_pool_module_shutdown();
    king_http2_pool_module_shutdown();
    king_object_store_shutdown_system();
    king_semantic_dns_shutdown_system();
    king_shutdown_modules(type, module_number);
    king_cdn_cache_registry_mshutdown();
    king_semantic_dns_registry_mshutdown();
    king_proto_registry_mshutdown();
    return SUCCESS;
}

PHP_RINIT_FUNCTION(king)
{
    king_last_error[0] = '\0';
    return king_request_init(type, module_number);
}

PHP_RSHUTDOWN_FUNCTION(king)
{
    king_object_store_request_shutdown();
    king_semantic_dns_request_shutdown();
    king_http1_pool_request_shutdown();
    king_http2_pool_request_shutdown();
    return king_request_shutdown(type, module_number);
}

PHP_MINFO_FUNCTION(king)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "King support", "enabled");
    php_info_print_table_row(2, "Version", PHP_KING_VERSION);
    php_info_print_table_row(2, "Build", "v1 (runtime-loaded QUIC)");
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    php_info_print_table_row(2, "QUIC backend", "runtime-loaded LSQUIC");
#else
    php_info_print_table_row(2, "QUIC backend", "not built (LSQUIC required)");
#endif
    php_info_print_table_row(2, "Active runtimes", king_get_active_runtime_summary());
    php_info_print_table_row(2, "Stubbed API groups", king_get_stubbed_api_summary());
    php_info_print_table_end();
    DISPLAY_INI_ENTRIES();
}
