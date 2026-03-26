static zend_string *king_semantic_dns_state_dup_string_field(
    HashTable *source,
    const char *key,
    size_t key_len
)
{
    zval *field = zend_hash_str_find(source, key, key_len);

    if (field == NULL || Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        return NULL;
    }

    return zend_string_dup(Z_STR_P(field), 1);
}

static bool king_semantic_dns_state_assign_non_negative_long(
    HashTable *source,
    const char *key,
    size_t key_len,
    zend_long *target
)
{
    zval *field = zend_hash_str_find(source, key, key_len);

    if (field == NULL) {
        *target = 0;
        return true;
    }

    if (Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) < 0) {
        return false;
    }

    *target = Z_LVAL_P(field);
    return true;
}

static bool king_semantic_dns_state_assign_attributes(
    HashTable *source,
    king_semantic_dns_service *service
)
{
    zval *attributes = zend_hash_str_find(source, "attributes", sizeof("attributes") - 1);
    zend_string *attribute_name;
    zval *attribute_value;

    if (attributes == NULL || Z_TYPE_P(attributes) == IS_NULL) {
        return true;
    }

    if (Z_TYPE_P(attributes) != IS_ARRAY) {
        return false;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(attributes), attribute_name, attribute_value) {
        zval stored_value;

        if (attribute_name == NULL) {
            return false;
        }

        if (!king_semantic_dns_copy_persistent_scalar_zval(&stored_value, attribute_value)) {
            return false;
        }

        zend_hash_update(
            &service->attributes,
            zend_string_copy(attribute_name),
            &stored_value
        );
    } ZEND_HASH_FOREACH_END();

    return true;
}

static king_semantic_dns_service *king_semantic_dns_service_create_from_state_entry(zval *entry)
{
    HashTable *source;
    king_semantic_dns_service *service;
    zval *field;

    if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
        return NULL;
    }

    source = Z_ARRVAL_P(entry);
    service = pecalloc(1, sizeof(*service), 1);
    zend_hash_init(&service->attributes, 4, NULL, king_semantic_dns_attribute_zval_dtor, 1);

    service->service_id = king_semantic_dns_state_dup_string_field(
        source,
        "service_id",
        sizeof("service_id") - 1
    );
    service->service_name = king_semantic_dns_state_dup_string_field(
        source,
        "service_name",
        sizeof("service_name") - 1
    );
    service->service_type = king_semantic_dns_state_dup_string_field(
        source,
        "service_type",
        sizeof("service_type") - 1
    );
    service->hostname = king_semantic_dns_state_dup_string_field(
        source,
        "hostname",
        sizeof("hostname") - 1
    );
    service->status = king_semantic_dns_state_dup_string_field(
        source,
        "status",
        sizeof("status") - 1
    );

    if (
        service->service_id == NULL
        || service->service_name == NULL
        || service->service_type == NULL
        || service->hostname == NULL
        || service->status == NULL
        || !king_semantic_dns_status_is_valid(service->status)
    ) {
        king_semantic_dns_service_free(service);
        return NULL;
    }

    field = zend_hash_str_find(source, "port", sizeof("port") - 1);
    if (
        field == NULL
        || Z_TYPE_P(field) != IS_LONG
        || Z_LVAL_P(field) <= 0
        || Z_LVAL_P(field) > 65535
    ) {
        king_semantic_dns_service_free(service);
        return NULL;
    }
    service->port = Z_LVAL_P(field);

    if (
        !king_semantic_dns_state_assign_non_negative_long(
            source,
            "current_load_percent",
            sizeof("current_load_percent") - 1,
            &service->current_load_percent
        )
        || !king_semantic_dns_state_assign_non_negative_long(
            source,
            "active_connections",
            sizeof("active_connections") - 1,
            &service->active_connections
        )
        || !king_semantic_dns_state_assign_non_negative_long(
            source,
            "total_requests",
            sizeof("total_requests") - 1,
            &service->total_requests
        )
        || !king_semantic_dns_state_assign_attributes(source, service)
    ) {
        king_semantic_dns_service_free(service);
        return NULL;
    }

    field = zend_hash_str_find(source, "registered_at", sizeof("registered_at") - 1);
    if (field != NULL && Z_TYPE_P(field) == IS_LONG && Z_LVAL_P(field) > 0) {
        service->registered_at = (time_t) Z_LVAL_P(field);
    } else {
        service->registered_at = time(NULL);
    }

    return service;
}

static king_semantic_dns_mother_node *king_semantic_dns_mother_node_create_from_state_entry(zval *entry)
{
    HashTable *source;
    king_semantic_dns_mother_node *mother_node;
    zval *field;

    if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
        return NULL;
    }

    source = Z_ARRVAL_P(entry);
    mother_node = pecalloc(1, sizeof(*mother_node), 1);

    mother_node->node_id = king_semantic_dns_state_dup_string_field(
        source,
        "node_id",
        sizeof("node_id") - 1
    );
    mother_node->hostname = king_semantic_dns_state_dup_string_field(
        source,
        "hostname",
        sizeof("hostname") - 1
    );
    mother_node->status = king_semantic_dns_state_dup_string_field(
        source,
        "status",
        sizeof("status") - 1
    );

    if (
        mother_node->node_id == NULL
        || mother_node->hostname == NULL
        || mother_node->status == NULL
        || !king_semantic_dns_status_is_valid(mother_node->status)
    ) {
        king_semantic_dns_mother_node_free(mother_node);
        return NULL;
    }

    field = zend_hash_str_find(source, "port", sizeof("port") - 1);
    if (
        field == NULL
        || Z_TYPE_P(field) != IS_LONG
        || Z_LVAL_P(field) <= 0
        || Z_LVAL_P(field) > 65535
    ) {
        king_semantic_dns_mother_node_free(mother_node);
        return NULL;
    }
    mother_node->port = Z_LVAL_P(field);

    if (
        !king_semantic_dns_state_assign_non_negative_long(
            source,
            "managed_services_count",
            sizeof("managed_services_count") - 1,
            &mother_node->managed_services_count
        )
    ) {
        king_semantic_dns_mother_node_free(mother_node);
        return NULL;
    }

    field = zend_hash_str_find(source, "trust_score", sizeof("trust_score") - 1);
    if (field == NULL) {
        mother_node->trust_score = 0.0;
    } else if (Z_TYPE_P(field) == IS_LONG) {
        mother_node->trust_score = (double) Z_LVAL_P(field);
    } else if (Z_TYPE_P(field) == IS_DOUBLE) {
        mother_node->trust_score = Z_DVAL_P(field);
    } else {
        king_semantic_dns_mother_node_free(mother_node);
        return NULL;
    }

    if (mother_node->trust_score < 0.0 || mother_node->trust_score > 1.0) {
        king_semantic_dns_mother_node_free(mother_node);
        return NULL;
    }

    field = zend_hash_str_find(source, "registered_at", sizeof("registered_at") - 1);
    if (field != NULL && Z_TYPE_P(field) == IS_LONG && Z_LVAL_P(field) > 0) {
        mother_node->registered_at = (time_t) Z_LVAL_P(field);
    } else {
        mother_node->registered_at = time(NULL);
    }

    return mother_node;
}

static int king_semantic_dns_import_mother_nodes_into_runtime_config(zval *mother_nodes)
{
    uint32_t count = 0;
    uint32_t index = 0;
    zval *entry;
    king_mother_node_t *runtime_nodes = NULL;

    if (mother_nodes == NULL || Z_TYPE_P(mother_nodes) != IS_ARRAY) {
        return SUCCESS;
    }

    count = (uint32_t) zend_hash_num_elements(Z_ARRVAL_P(mother_nodes));
    if (count == 0) {
        return SUCCESS;
    }

    runtime_nodes = pecalloc(count, sizeof(*runtime_nodes), 1);
    if (runtime_nodes == NULL) {
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(mother_nodes), entry) {
        zval *field;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY || index >= count) {
            pefree(runtime_nodes, 1);
            return FAILURE;
        }

        field = zend_hash_str_find(Z_ARRVAL_P(entry), "node_id", sizeof("node_id") - 1);
        if (field == NULL || Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
            pefree(runtime_nodes, 1);
            return FAILURE;
        }
        strncpy(
            runtime_nodes[index].node_id,
            Z_STRVAL_P(field),
            sizeof(runtime_nodes[index].node_id) - 1
        );

        field = zend_hash_str_find(Z_ARRVAL_P(entry), "port", sizeof("port") - 1);
        if (
            field == NULL
            || Z_TYPE_P(field) != IS_LONG
            || Z_LVAL_P(field) <= 0
            || Z_LVAL_P(field) > 65535
        ) {
            pefree(runtime_nodes, 1);
            return FAILURE;
        }
        runtime_nodes[index].port = (uint16_t) Z_LVAL_P(field);

        field = zend_hash_str_find(Z_ARRVAL_P(entry), "managed_services_count", sizeof("managed_services_count") - 1);
        if (field != NULL && Z_TYPE_P(field) == IS_LONG && Z_LVAL_P(field) >= 0) {
            runtime_nodes[index].managed_services_count = (uint32_t) Z_LVAL_P(field);
        }

        field = zend_hash_str_find(Z_ARRVAL_P(entry), "status", sizeof("status") - 1);
        if (field != NULL && Z_TYPE_P(field) == IS_STRING) {
            if (zend_string_equals_literal(Z_STR_P(field), "healthy")) {
                runtime_nodes[index].status = KING_SERVICE_STATUS_HEALTHY;
            } else if (zend_string_equals_literal(Z_STR_P(field), "degraded")) {
                runtime_nodes[index].status = KING_SERVICE_STATUS_DEGRADED;
            } else if (zend_string_equals_literal(Z_STR_P(field), "unhealthy")) {
                runtime_nodes[index].status = KING_SERVICE_STATUS_UNHEALTHY;
            } else if (zend_string_equals_literal(Z_STR_P(field), "maintenance")) {
                runtime_nodes[index].status = KING_SERVICE_STATUS_MAINTENANCE;
            } else {
                runtime_nodes[index].status = KING_SERVICE_STATUS_UNKNOWN;
            }
        }

        field = zend_hash_str_find(Z_ARRVAL_P(entry), "trust_score", sizeof("trust_score") - 1);
        if (field != NULL) {
            if (Z_TYPE_P(field) == IS_LONG) {
                runtime_nodes[index].trust_score = (double) Z_LVAL_P(field);
            } else if (Z_TYPE_P(field) == IS_DOUBLE) {
                runtime_nodes[index].trust_score = Z_DVAL_P(field);
            }
        }

        field = zend_hash_str_find(Z_ARRVAL_P(entry), "registered_at", sizeof("registered_at") - 1);
        if (field != NULL && Z_TYPE_P(field) == IS_LONG && Z_LVAL_P(field) > 0) {
            runtime_nodes[index].last_heartbeat = (time_t) Z_LVAL_P(field);
        } else {
            runtime_nodes[index].last_heartbeat = time(NULL);
        }

        index++;
    } ZEND_HASH_FOREACH_END();

    if (king_semantic_dns_runtime.config.mother_nodes != NULL) {
        pefree(king_semantic_dns_runtime.config.mother_nodes, 1);
    }

    king_semantic_dns_runtime.config.mother_nodes = runtime_nodes;
    king_semantic_dns_runtime.config.mother_node_count = count;

    return SUCCESS;
}

int king_semantic_dns_export_state_payload(zval *return_value)
{
    zval services;
    zval mother_nodes;
    zval *service_entry;
    zval *mother_node_entry;

    if (return_value == NULL || !king_semantic_dns_registry_initialized) {
        return FAILURE;
    }

    array_init(return_value);
    array_init(&services);
    array_init(&mother_nodes);

    ZEND_HASH_FOREACH_VAL(&king_semantic_dns_service_registry, service_entry) {
        king_semantic_dns_service *service;
        zval exported_service;

        if (Z_TYPE_P(service_entry) != IS_PTR) {
            continue;
        }

        service = (king_semantic_dns_service *) Z_PTR_P(service_entry);
        if (service == NULL) {
            continue;
        }

        king_semantic_dns_export_service(&exported_service, service);
        add_next_index_zval(&services, &exported_service);
    } ZEND_HASH_FOREACH_END();

    ZEND_HASH_FOREACH_VAL(&king_semantic_dns_mother_node_registry, mother_node_entry) {
        king_semantic_dns_mother_node *mother_node;
        zval exported_mother_node;

        if (Z_TYPE_P(mother_node_entry) != IS_PTR) {
            continue;
        }

        mother_node = (king_semantic_dns_mother_node *) Z_PTR_P(mother_node_entry);
        if (mother_node == NULL) {
            continue;
        }

        king_semantic_dns_export_mother_node(&exported_mother_node, mother_node);
        add_next_index_zval(&mother_nodes, &exported_mother_node);
    } ZEND_HASH_FOREACH_END();

    add_assoc_zval(return_value, "services", &services);
    add_assoc_zval(return_value, "mother_nodes", &mother_nodes);

    return SUCCESS;
}

int king_semantic_dns_import_state_payload(zval *payload)
{
    zval *services;
    zval *mother_nodes;
    zval *entry;

    if (
        payload == NULL
        || Z_TYPE_P(payload) != IS_ARRAY
        || !king_semantic_dns_registry_initialized
    ) {
        return FAILURE;
    }

    services = zend_hash_str_find(Z_ARRVAL_P(payload), "services", sizeof("services") - 1);
    mother_nodes = zend_hash_str_find(Z_ARRVAL_P(payload), "mother_nodes", sizeof("mother_nodes") - 1);

    if (
        (services != NULL && Z_TYPE_P(services) != IS_ARRAY)
        || (mother_nodes != NULL && Z_TYPE_P(mother_nodes) != IS_ARRAY)
    ) {
        return FAILURE;
    }

    zend_hash_clean(&king_semantic_dns_service_registry);
    zend_hash_clean(&king_semantic_dns_mother_node_registry);

    if (services != NULL) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(services), entry) {
            king_semantic_dns_service *service;
            zval stored_service;

            service = king_semantic_dns_service_create_from_state_entry(entry);
            if (service == NULL) {
                return FAILURE;
            }

            ZVAL_PTR(&stored_service, service);
            zend_hash_update(&king_semantic_dns_service_registry, service->service_id, &stored_service);
        } ZEND_HASH_FOREACH_END();
    }

    if (mother_nodes != NULL) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(mother_nodes), entry) {
            king_semantic_dns_mother_node *mother_node;
            zval stored_mother_node;

            mother_node = king_semantic_dns_mother_node_create_from_state_entry(entry);
            if (mother_node == NULL) {
                return FAILURE;
            }

            ZVAL_PTR(&stored_mother_node, mother_node);
            zend_hash_update(&king_semantic_dns_mother_node_registry, mother_node->node_id, &stored_mother_node);
        } ZEND_HASH_FOREACH_END();

        if (king_semantic_dns_import_mother_nodes_into_runtime_config(mother_nodes) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}
