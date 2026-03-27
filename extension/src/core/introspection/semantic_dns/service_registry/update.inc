PHP_FUNCTION(king_semantic_dns_update_service_status)
{
    zend_string *service_id;
    zend_string *status;
    zval *metrics = NULL;
    king_semantic_dns_service *service;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(service_id)
        Z_PARAM_STR(status)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(metrics)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_semantic_dns_registry_require_available()) {
        RETURN_THROWS();
    }

    if (ZSTR_LEN(service_id) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_update_service_status() requires a non-empty 'service_id'."
        );
        RETURN_THROWS();
    }

    if (!king_semantic_dns_status_is_valid(status)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_update_service_status() Invalid status '%s'; expected one of: healthy, degraded, unhealthy, maintenance, unknown.",
            ZSTR_VAL(status)
        );
        RETURN_THROWS();
    }

    service = king_semantic_dns_registry_get_service(service_id);
    if (service == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_update_service_status() service '%s' not found.",
            ZSTR_VAL(service_id)
        );
        RETURN_THROWS();
    }

    if (!zend_string_equals(service->status, status)) {
        zend_string_release_ex(service->status, 1);
        service->status = king_semantic_dns_copy_persistent_string(status);
    }

    if (metrics != NULL && !king_semantic_dns_service_apply_metrics(
            Z_ARRVAL_P(metrics),
            "king_semantic_dns_update_service_status",
            service
        )) {
        RETURN_THROWS();
    }

    if (king_semantic_dns_runtime.initialized && king_semantic_dns_runtime.config.semantic_mode_enable) {
        (void) king_semantic_dns_state_save();
    }

    RETURN_TRUE;
}
