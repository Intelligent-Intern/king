PHP_FUNCTION(king_semantic_dns_register_service)
{
    zval *service_info;
    king_semantic_dns_service *service;
    king_semantic_dns_service *existing_service;
    zval stored_service;
    int lock_fd = -1;
    bool use_state_transaction = false;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(service_info)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_semantic_dns_registry_require_available()) {
        RETURN_THROWS();
    }

    service = king_semantic_dns_service_create_from_payload(
        service_info,
        "king_semantic_dns_register_service"
    );
    if (service == NULL) {
        RETURN_THROWS();
    }

    if (king_semantic_dns_runtime.initialized && king_semantic_dns_runtime.config.semantic_mode_enable) {
        if (!king_semantic_dns_state_transaction_begin_or_throw(
                "king_semantic_dns_register_service",
                &lock_fd
            )) {
            king_semantic_dns_service_free(service);
            RETURN_THROWS();
        }
        use_state_transaction = true;
    }

    existing_service = king_semantic_dns_registry_get_service(service->service_id);
    if (existing_service != NULL && king_semantic_dns_service_equals(existing_service, service)) {
        king_semantic_dns_service_free(service);
        if (use_state_transaction) {
            king_semantic_dns_state_transaction_end(lock_fd);
        }
        RETURN_TRUE;
    }

    ZVAL_PTR(&stored_service, service);
    if (zend_hash_update(&king_semantic_dns_service_registry, service->service_id, &stored_service) == NULL) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to register Semantic-DNS service '%s'.",
            ZSTR_VAL(service->service_id)
        );
        king_semantic_dns_service_free(service);
        if (use_state_transaction) {
            king_semantic_dns_state_transaction_end(lock_fd);
        }
        RETURN_THROWS();
    }

    if (use_state_transaction
        && !king_semantic_dns_state_persist_locked_or_throw(
            "king_semantic_dns_register_service"
        )) {
        king_semantic_dns_state_transaction_end(lock_fd);
        RETURN_THROWS();
    }

    if (use_state_transaction) {
        king_semantic_dns_state_transaction_end(lock_fd);
    }

    RETURN_TRUE;
}
