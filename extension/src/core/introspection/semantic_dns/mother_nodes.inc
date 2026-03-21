PHP_FUNCTION(king_semantic_dns_register_mother_node)
{
    zval *mother_node_info;
    zval *field;
    king_semantic_dns_mother_node *mother_node;
    zval stored_mother_node;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(mother_node_info)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_semantic_dns_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Semantic-DNS registry is unavailable."
        );
        RETURN_THROWS();
    }

    field = zend_hash_str_find(Z_ARRVAL_P(mother_node_info), "node_id", sizeof("node_id") - 1);
    if (field == NULL || Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_register_mother_node() requires a non-empty 'node_id'."
        );
        RETURN_THROWS();
    }

    mother_node = pecalloc(1, sizeof(*mother_node), 1);
    mother_node->node_id = zend_string_dup(Z_STR_P(field), 1);

    field = zend_hash_str_find(Z_ARRVAL_P(mother_node_info), "hostname", sizeof("hostname") - 1);
    if (field == NULL || Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        king_semantic_dns_mother_node_free(mother_node);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_register_mother_node() requires a non-empty 'hostname'."
        );
        RETURN_THROWS();
    }
    mother_node->hostname = zend_string_dup(Z_STR_P(field), 1);

    field = zend_hash_str_find(Z_ARRVAL_P(mother_node_info), "port", sizeof("port") - 1);
    if (field == NULL || Z_TYPE_P(field) != IS_LONG
        || Z_LVAL_P(field) <= 0 || Z_LVAL_P(field) > 65535) {
        king_semantic_dns_mother_node_free(mother_node);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_register_mother_node() requires 'port' between 1 and 65535."
        );
        RETURN_THROWS();
    }
    mother_node->port = Z_LVAL_P(field);

    field = zend_hash_str_find(Z_ARRVAL_P(mother_node_info), "status", sizeof("status") - 1);
    if (field == NULL) {
        mother_node->status = zend_string_init("healthy", sizeof("healthy") - 1, 1);
    } else if (Z_TYPE_P(field) != IS_STRING || !king_semantic_dns_status_is_valid(Z_STR_P(field))) {
        king_semantic_dns_mother_node_free(mother_node);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_register_mother_node() 'status' must be one of: healthy, degraded, unhealthy, maintenance, unknown."
        );
        RETURN_THROWS();
    } else {
        mother_node->status = zend_string_dup(Z_STR_P(field), 1);
    }

    field = zend_hash_str_find(
        Z_ARRVAL_P(mother_node_info),
        "managed_services_count",
        sizeof("managed_services_count") - 1
    );
    if (field != NULL) {
        if (Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) < 0) {
            king_semantic_dns_mother_node_free(mother_node);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "king_semantic_dns_register_mother_node() 'managed_services_count' must be >= 0."
            );
            RETURN_THROWS();
        }
        mother_node->managed_services_count = Z_LVAL_P(field);
    }

    field = zend_hash_str_find(Z_ARRVAL_P(mother_node_info), "trust_score", sizeof("trust_score") - 1);
    if (field != NULL) {
        if (Z_TYPE_P(field) == IS_LONG) {
            mother_node->trust_score = (double) Z_LVAL_P(field);
        } else if (Z_TYPE_P(field) == IS_DOUBLE) {
            mother_node->trust_score = Z_DVAL_P(field);
        } else {
            king_semantic_dns_mother_node_free(mother_node);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "king_semantic_dns_register_mother_node() 'trust_score' must be between 0 and 1."
            );
            RETURN_THROWS();
        }

        if (mother_node->trust_score < 0.0 || mother_node->trust_score > 1.0) {
            king_semantic_dns_mother_node_free(mother_node);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "king_semantic_dns_register_mother_node() 'trust_score' must be between 0 and 1."
            );
            RETURN_THROWS();
        }
    }

    mother_node->registered_at = time(NULL);

    ZVAL_PTR(&stored_mother_node, mother_node);
    if (zend_hash_update(&king_semantic_dns_mother_node_registry, mother_node->node_id, &stored_mother_node) == NULL) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to register Semantic-DNS mother node '%s'.",
            ZSTR_VAL(mother_node->node_id)
        );
        king_semantic_dns_mother_node_free(mother_node);
        RETURN_THROWS();
    }

    RETURN_TRUE;
}
