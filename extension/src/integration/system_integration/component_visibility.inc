static void king_system_build_component_startup_dependencies(
    zval *dependencies,
    king_component_type_t type
)
{
    array_init(dependencies);

    switch (type) {
        case KING_COMPONENT_CONFIG:
            break;
        case KING_COMPONENT_CLIENT:
        case KING_COMPONENT_SERVER:
        case KING_COMPONENT_TELEMETRY:
        case KING_COMPONENT_OBJECT_STORE:
        case KING_COMPONENT_IIBIN:
            add_next_index_string(dependencies, "config");
            break;
        case KING_COMPONENT_MCP:
        case KING_COMPONENT_SEMANTIC_DNS:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "client");
            break;
        case KING_COMPONENT_ROUTER_LOADBALANCER:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "client");
            add_next_index_string(dependencies, "server");
            break;
        case KING_COMPONENT_PIPELINE_ORCHESTRATOR:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "telemetry");
            add_next_index_string(dependencies, "object_store");
            break;
        case KING_COMPONENT_CDN:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "server");
            add_next_index_string(dependencies, "object_store");
            break;
        case KING_COMPONENT_AUTOSCALING:
            add_next_index_string(dependencies, "config");
            add_next_index_string(dependencies, "telemetry");
            add_next_index_string(dependencies, "server");
            add_next_index_string(dependencies, "semantic_dns");
            break;
    }
}

static void king_system_build_component_pending_startup_dependencies(
    zval *pending_dependencies,
    king_component_type_t type
)
{
    zval dependencies;
    zval *dependency_name;

    array_init(pending_dependencies);
    king_system_build_component_startup_dependencies(&dependencies, type);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependencies), dependency_name) {
        king_component_info_t *dependency_info;
        const char *dependency;

        if (Z_TYPE_P(dependency_name) != IS_STRING) {
            continue;
        }

        dependency = Z_STRVAL_P(dependency_name);
        dependency_info = king_system_get_component_by_name(dependency);
        if (dependency_info == NULL || !king_system_component_startup_dependency_ready(dependency)) {
            add_next_index_string(pending_dependencies, dependency);
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&dependencies);
}

static void king_system_build_component_shutdown_dependents(
    zval *dependents,
    king_component_type_t type
)
{
    const king_system_startup_entry_t *target_entry;
    zval dependency_name_list;
    zval *dependency_name;
    size_t idx;

    array_init(dependents);
    target_entry = king_system_get_startup_entry(type);
    if (target_entry == NULL) {
        return;
    }

    for (
        idx = 0;
        idx < sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0]);
        idx++
    ) {
        const king_system_startup_entry_t *entry = &king_system_startup_plan[idx];
        zend_bool depends_on_target = 0;

        if (entry->type == type) {
            continue;
        }

        king_system_build_component_startup_dependencies(
            &dependency_name_list,
            entry->type
        );

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependency_name_list), dependency_name) {
            if (
                Z_TYPE_P(dependency_name) == IS_STRING &&
                strcmp(Z_STRVAL_P(dependency_name), target_entry->name) == 0
            ) {
                depends_on_target = 1;
                break;
            }
        } ZEND_HASH_FOREACH_END();

        zval_ptr_dtor(&dependency_name_list);

        if (depends_on_target) {
            add_next_index_string(dependents, (char *) entry->name);
        }
    }
}

static void king_system_build_component_pending_shutdown_dependents(
    zval *pending_dependents,
    king_component_type_t type
)
{
    zval dependents;
    zval *dependent_name;

    array_init(pending_dependents);
    king_system_build_component_shutdown_dependents(&dependents, type);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependents), dependent_name) {
        king_component_info_t *dependent_info;

        if (Z_TYPE_P(dependent_name) != IS_STRING) {
            continue;
        }

        dependent_info = king_system_get_component_by_name(
            Z_STRVAL_P(dependent_name)
        );
        if (
            dependent_info != NULL &&
            king_system_component_started(dependent_info->status)
        ) {
            add_next_index_string(
                pending_dependents,
                Z_STRVAL_P(dependent_name)
            );
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&dependents);
}

/*
 * Publish the canonical startup graph even before ordered startup transitions
 * are implemented so callers can inspect the intended dependency plan without
 * reverse-engineering it from registration order.
 */
static void king_system_build_startup_visibility(zval *startup)
{
    zval blocked_components;
    zval component_entry;
    zval components;
    zval dependencies;
    zval pending_components;
    zval pending_dependencies;
    zval ready_to_start_components;
    zval started_components;
    zval status_copy;
    zval ordered_components;
    size_t idx;

    array_init(startup);
    array_init(&blocked_components);
    array_init(&components);
    array_init(&ordered_components);
    array_init(&pending_components);
    array_init(&ready_to_start_components);
    array_init(&started_components);

    for (
        idx = 0;
        idx < sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0]);
        idx++
    ) {
        const king_system_startup_entry_t *entry = &king_system_startup_plan[idx];
        king_component_info_t *info = king_system_get_component_internal(entry->type);
        zend_bool started = 0;
        zend_bool ready_to_start = 0;

        if (info != NULL) {
            started = king_system_component_started(info->status);
        }

        add_next_index_string(&ordered_components, (char *) entry->name);
        king_system_build_component_startup_dependencies(&dependencies, entry->type);
        king_system_build_component_pending_startup_dependencies(
            &pending_dependencies,
            entry->type
        );
        ready_to_start = !started
            && zend_hash_num_elements(Z_ARRVAL(pending_dependencies)) == 0;

        if (started) {
            add_next_index_string(&started_components, (char *) entry->name);
        } else {
            add_next_index_string(&pending_components, (char *) entry->name);
        }

        if (ready_to_start) {
            add_next_index_string(&ready_to_start_components, (char *) entry->name);
        }

        if (
            !started &&
            zend_hash_num_elements(Z_ARRVAL(pending_dependencies)) > 0
        ) {
            ZVAL_COPY(&status_copy, &pending_dependencies);
            add_assoc_zval(&blocked_components, entry->name, &status_copy);
        }

        array_init(&component_entry);
        add_assoc_long(&component_entry, "order", (zend_long) entry->order);
        add_assoc_zval(&component_entry, "dependencies", &dependencies);
        add_assoc_zval(
            &component_entry,
            "pending_dependencies",
            &pending_dependencies
        );
        add_assoc_bool(&component_entry, "status_visible", info != NULL ? 1 : 0);
        add_assoc_bool(&component_entry, "started", started);
        add_assoc_bool(&component_entry, "ready_to_start", ready_to_start);
        add_assoc_zval(&components, entry->name, &component_entry);
    }

    add_assoc_long(
        startup,
        "catalog_component_count",
        (zend_long) (
            sizeof(king_system_startup_plan) / sizeof(king_system_startup_plan[0])
        )
    );
    add_assoc_zval(startup, "ordered_components", &ordered_components);
    add_assoc_zval(startup, "started_components", &started_components);
    add_assoc_zval(startup, "pending_components", &pending_components);
    add_assoc_zval(
        startup,
        "ready_to_start_components",
        &ready_to_start_components
    );
    add_assoc_zval(startup, "blocked_components", &blocked_components);
    add_assoc_zval(startup, "components", &components);
}

/*
 * Publish the canonical shutdown graph separately from the startup graph so
 * operators can inspect which components are leaf shutdown candidates, which
 * still have live dependents, and that King requires a drain-first stop path.
 */
static void king_system_build_shutdown_visibility(zval *shutdown)
{
    zval active_components;
    zval blocked_components;
    zval component_entry;
    zval components;
    zval inactive_components;
    zval ordered_components;
    zval ready_to_stop_components;
    zval status_copy;
    size_t idx;

    array_init(shutdown);
    array_init(&active_components);
    array_init(&blocked_components);
    array_init(&components);
    array_init(&inactive_components);
    array_init(&ordered_components);
    array_init(&ready_to_stop_components);

    for (
        idx = 0;
        idx < sizeof(king_system_shutdown_plan) / sizeof(king_system_shutdown_plan[0]);
        idx++
    ) {
        const king_system_startup_entry_t *entry = &king_system_shutdown_plan[idx];
        king_component_info_t *info = king_system_get_component_internal(entry->type);
        zval component_pending_dependents;
        zval component_dependents;
        zend_bool started = 0;
        zend_bool ready_to_stop = 0;

        if (info != NULL) {
            started = king_system_component_started(info->status);
        }

        add_next_index_string(&ordered_components, (char *) entry->name);
        king_system_build_component_shutdown_dependents(
            &component_dependents,
            entry->type
        );
        king_system_build_component_pending_shutdown_dependents(
            &component_pending_dependents,
            entry->type
        );
        ready_to_stop = started
            && zend_hash_num_elements(
                Z_ARRVAL(component_pending_dependents)
            ) == 0;

        if (started) {
            add_next_index_string(&active_components, (char *) entry->name);
        } else {
            add_next_index_string(&inactive_components, (char *) entry->name);
        }

        if (ready_to_stop) {
            add_next_index_string(&ready_to_stop_components, (char *) entry->name);
        }

        if (
            started &&
            zend_hash_num_elements(Z_ARRVAL(component_pending_dependents)) > 0
        ) {
            ZVAL_COPY(&status_copy, &component_pending_dependents);
            add_assoc_zval(&blocked_components, entry->name, &status_copy);
        }

        array_init(&component_entry);
        add_assoc_long(&component_entry, "order", (zend_long) entry->order);
        add_assoc_zval(&component_entry, "dependents", &component_dependents);
        add_assoc_zval(
            &component_entry,
            "pending_dependents",
            &component_pending_dependents
        );
        add_assoc_bool(&component_entry, "status_visible", info != NULL ? 1 : 0);
        add_assoc_bool(&component_entry, "started", started);
        add_assoc_bool(&component_entry, "ready_to_stop", ready_to_stop);
        add_assoc_zval(&components, entry->name, &component_entry);
    }

    add_assoc_bool(shutdown, "drain_first_required", 1);
    add_assoc_long(
        shutdown,
        "catalog_component_count",
        (zend_long) (
            sizeof(king_system_shutdown_plan) / sizeof(king_system_shutdown_plan[0])
        )
    );
    add_assoc_zval(shutdown, "ordered_components", &ordered_components);
    add_assoc_zval(shutdown, "active_components", &active_components);
    add_assoc_zval(shutdown, "inactive_components", &inactive_components);
    add_assoc_zval(shutdown, "ready_to_stop_components", &ready_to_stop_components);
    add_assoc_zval(shutdown, "blocked_components", &blocked_components);
    add_assoc_zval(shutdown, "components", &components);
}
