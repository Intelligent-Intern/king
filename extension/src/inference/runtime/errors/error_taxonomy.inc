/*
 * Stable inference error taxonomy shared by router responses and diagnostics.
 *
 * The taxonomy intentionally carries no request body, prompt, message content,
 * or model output. Human-facing details stay in the existing error message.
 */

static bool king_inference_error_ascii_contains(const char *haystack, const char *needle)
{
    size_t haystack_len;
    size_t needle_len;

    if (haystack == NULL || needle == NULL || needle[0] == '\0') {
        return false;
    }

    haystack_len = strlen(haystack);
    needle_len = strlen(needle);
    if (needle_len > haystack_len) {
        return false;
    }

    for (size_t i = 0; i + needle_len <= haystack_len; i++) {
        if (strncasecmp(haystack + i, needle, needle_len) == 0) {
            return true;
        }
    }

    return false;
}

static const char *king_inference_error_category_from_code(const char *code)
{
    if (code == NULL) {
        return "router";
    }
    if (strncmp(code, "king.validation.", sizeof("king.validation.") - 1) == 0) {
        return "validation";
    }
    if (strncmp(code, "king.model_artifact.", sizeof("king.model_artifact.") - 1) == 0) {
        return "model_artifact";
    }
    if (strncmp(code, "king.tokenizer.", sizeof("king.tokenizer.") - 1) == 0) {
        return "tokenizer";
    }
    if (strncmp(code, "king.graph.", sizeof("king.graph.") - 1) == 0) {
        return "graph";
    }
    if (strncmp(code, "king.allocation.", sizeof("king.allocation.") - 1) == 0) {
        return "allocation";
    }
    if (strncmp(code, "king.kernel.", sizeof("king.kernel.") - 1) == 0) {
        return "kernel";
    }
    if (strncmp(code, "king.numeric_mismatch.", sizeof("king.numeric_mismatch.") - 1) == 0) {
        return "numeric_mismatch";
    }
    if (strncmp(code, "king.kv_cache.", sizeof("king.kv_cache.") - 1) == 0) {
        return "kv_cache";
    }
    if (strncmp(code, "king.policy.", sizeof("king.policy.") - 1) == 0) {
        return "policy";
    }
    if (strncmp(code, "king.timeout.", sizeof("king.timeout.") - 1) == 0) {
        return "timeout";
    }
    if (strncmp(code, "king.cancellation.", sizeof("king.cancellation.") - 1) == 0) {
        return "cancellation";
    }
    if (strncmp(code, "king.unsupported_feature.", sizeof("king.unsupported_feature.") - 1) == 0) {
        return "unsupported_feature";
    }

    return "router";
}

static bool king_inference_error_code_is_stable(const char *code)
{
    return code != NULL && strncmp(code, "king.", sizeof("king.") - 1) == 0;
}

static const char *king_inference_error_code_from_runtime_reason(const char *reason)
{
    if (reason == NULL || reason[0] == '\0') {
        return "king.router.internal";
    }
    if (king_inference_error_ascii_contains(reason, "backend_config")) {
        return "king.validation.invalid_backend_config";
    }
    if (king_inference_error_ascii_contains(reason, "artifact_missing")
        || king_inference_error_ascii_contains(reason, "artifact_configured")) {
        return "king.model_artifact.missing";
    }
    if (king_inference_error_ascii_contains(reason, "artifact_not_readable")
        || king_inference_error_ascii_contains(reason, "not_readable")) {
        return "king.model_artifact.unreadable";
    }
    if (king_inference_error_ascii_contains(reason, "tokenizer")) {
        return "king.tokenizer.unavailable";
    }
    if (king_inference_error_ascii_contains(reason, "graph")) {
        return "king.graph.invalid_request";
    }
    if (king_inference_error_ascii_contains(reason, "kv_cache")
        || king_inference_error_ascii_contains(reason, "kv_")) {
        return "king.kv_cache.invalid";
    }
    if (king_inference_error_ascii_contains(reason, "numeric")) {
        return "king.numeric_mismatch.detected";
    }
    if (king_inference_error_ascii_contains(reason, "vram")
        || king_inference_error_ascii_contains(reason, "allocator")
        || king_inference_error_ascii_contains(reason, "allocation")
        || king_inference_error_ascii_contains(reason, "memory")) {
        return "king.allocation.failed";
    }
    if (king_inference_error_ascii_contains(reason, "gpu_temperature_limit_reached")) {
        return "king.policy.gpu_thermal_limit_reached";
    }
    if (king_inference_error_ascii_contains(reason, "gpu_power_limit_reached")) {
        return "king.policy.gpu_power_limit_reached";
    }
    if (king_inference_error_ascii_contains(reason, "thermal")
        || king_inference_error_ascii_contains(reason, "power")
        || king_inference_error_ascii_contains(reason, "disabled")
        || king_inference_error_ascii_contains(reason, "not_admitted")
        || king_inference_error_ascii_contains(reason, "policy")) {
        return "king.policy.refused";
    }
    if (king_inference_error_ascii_contains(reason, "timeout")
        || king_inference_error_ascii_contains(reason, "deadline")) {
        return "king.timeout.deadline_exceeded";
    }
    if (king_inference_error_ascii_contains(reason, "cancel")) {
        return "king.cancellation.cancelled";
    }
    if (king_inference_error_ascii_contains(reason, "unsupported")) {
        return "king.unsupported_feature.requested";
    }
    if (king_inference_error_ascii_contains(reason, "kernel")
        || king_inference_error_ascii_contains(reason, "cuda")
        || king_inference_error_ascii_contains(reason, "decoder")
        || king_inference_error_ascii_contains(reason, "gpu_")) {
        return "king.kernel.unavailable";
    }

    return "king.router.internal";
}

static const char *king_inference_error_code_from_message(
    zend_long status,
    const char *type,
    const char *message)
{
    if (status == 404) {
        return "king.router.route_not_found";
    }
    if (status == 405) {
        return "king.router.method_not_allowed";
    }

    if (message != NULL) {
        if (king_inference_error_ascii_contains(message, "not valid JSON")
            || king_inference_error_ascii_contains(message, "must be")
            || king_inference_error_ascii_contains(message, "must not")
            || king_inference_error_ascii_contains(message, "invalid")) {
            return "king.validation.invalid_request";
        }
        if (king_inference_error_ascii_contains(message, "backend configuration")) {
            return "king.validation.invalid_backend_config";
        }
        if (king_inference_error_ascii_contains(message, "artifact")) {
            return king_inference_error_ascii_contains(message, "readable")
                ? "king.model_artifact.unreadable"
                : "king.model_artifact.missing";
        }
        if (king_inference_error_ascii_contains(message, "tokenizer")) {
            return "king.tokenizer.unavailable";
        }
        if (king_inference_error_ascii_contains(message, "graph")) {
            return "king.graph.invalid_request";
        }
        if (king_inference_error_ascii_contains(message, "KV")
            || king_inference_error_ascii_contains(message, "cache")) {
            return "king.kv_cache.invalid";
        }
        if (king_inference_error_ascii_contains(message, "numeric")) {
            return "king.numeric_mismatch.detected";
        }
        if (king_inference_error_ascii_contains(message, "allocation")
            || king_inference_error_ascii_contains(message, "memory")
            || king_inference_error_ascii_contains(message, "VRAM")) {
            return "king.allocation.failed";
        }
        if (king_inference_error_ascii_contains(message, "runtime policy")
            || king_inference_error_ascii_contains(message, "not ready")
            || king_inference_error_ascii_contains(message, "cannot generate")
            || king_inference_error_ascii_contains(message, "not admitted")) {
            return "king.policy.refused";
        }
        if (king_inference_error_ascii_contains(message, "refused GPU inference because sensor")
            && king_inference_error_ascii_contains(message, "at or above")) {
            return "king.policy.gpu_thermal_limit_reached";
        }
        if (king_inference_error_ascii_contains(message, "power draw")
            && king_inference_error_ascii_contains(message, "at or above")) {
            return "king.policy.gpu_power_limit_reached";
        }
        if (king_inference_error_ascii_contains(message, "timeout")
            || king_inference_error_ascii_contains(message, "deadline")) {
            return "king.timeout.deadline_exceeded";
        }
        if (king_inference_error_ascii_contains(message, "cancel")) {
            return "king.cancellation.cancelled";
        }
        if (king_inference_error_ascii_contains(message, "unsupported")
            || king_inference_error_ascii_contains(message, "does not support")
            || king_inference_error_ascii_contains(message, "requires")
            || king_inference_error_ascii_contains(message, "not found")) {
            return "king.unsupported_feature.requested";
        }
        if (king_inference_error_ascii_contains(message, "kernel")
            || king_inference_error_ascii_contains(message, "CUDA")
            || king_inference_error_ascii_contains(message, "decoder")) {
            return "king.kernel.unavailable";
        }
    }

    if (type != NULL && strcmp(type, "invalid_request_error") == 0) {
        return "king.validation.invalid_request";
    }
    if (status >= 500 || (type != NULL && strcmp(type, "server_error") == 0)) {
        return "king.router.internal";
    }

    return "king.router.internal";
}

static const char *king_inference_error_code_from_explicit_or_message(
    zend_long status,
    const char *type,
    const char *message,
    const char *explicit_code)
{
    if (king_inference_error_code_is_stable(explicit_code)) {
        return explicit_code;
    }

    return king_inference_error_code_from_message(status, type, message);
}

static void king_inference_error_taxonomy_add_unique_string(zval *values, const char *value)
{
    zval *entry;

    if (value == NULL || value[0] == '\0') {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(values), entry) {
        if (Z_TYPE_P(entry) == IS_STRING && strcmp(Z_STRVAL_P(entry), value) == 0) {
            return;
        }
    } ZEND_HASH_FOREACH_END();

    add_next_index_string(values, value);
}

static void king_inference_error_taxonomy_entry(zval *return_value, const char *code)
{
    array_init(return_value);
    add_assoc_string(return_value, "code", code);
    add_assoc_string(return_value, "category", king_inference_error_category_from_code(code));
}

static void king_inference_error_taxonomy_catalog_array(zval *return_value)
{
    static const char *codes[] = {
        "king.validation.invalid_request",
        "king.validation.invalid_backend_config",
        "king.model_artifact.missing",
        "king.model_artifact.unreadable",
        "king.tokenizer.unavailable",
        "king.graph.invalid_request",
        "king.graph.unsupported_payload",
        "king.allocation.failed",
        "king.kernel.unavailable",
        "king.numeric_mismatch.detected",
        "king.kv_cache.invalid",
        "king.policy.refused",
        "king.policy.gpu_thermal_limit_reached",
        "king.policy.gpu_power_limit_reached",
        "king.router.internal",
        "king.router.route_not_found",
        "king.router.method_not_allowed",
        "king.timeout.deadline_exceeded",
        "king.cancellation.cancelled",
        "king.unsupported_feature.requested",
    };
    zval categories;
    zval code_list;

    array_init(return_value);
    add_assoc_long(return_value, "schema_version", 1);
    add_assoc_bool(return_value, "prompt_included", false);
    add_assoc_bool(return_value, "request_body_included", false);
    add_assoc_bool(return_value, "safe_for_model_list_diagnostics", true);

    array_init(&categories);
    for (size_t i = 0; i < sizeof(codes) / sizeof(codes[0]); i++) {
        king_inference_error_taxonomy_add_unique_string(
            &categories,
            king_inference_error_category_from_code(codes[i])
        );
    }
    add_assoc_zval(return_value, "categories", &categories);

    array_init(&code_list);
    for (size_t i = 0; i < sizeof(codes) / sizeof(codes[0]); i++) {
        zval entry;
        king_inference_error_taxonomy_entry(&entry, codes[i]);
        add_next_index_zval(&code_list, &entry);
    }
    add_assoc_zval(return_value, "codes", &code_list);
}

static void king_inference_error_taxonomy_codes_from_reason_list(
    zval *reasons,
    zval *return_value)
{
    zval *entry;

    array_init(return_value);
    if (reasons == NULL || Z_TYPE_P(reasons) != IS_ARRAY) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(reasons), entry) {
        if (Z_TYPE_P(entry) == IS_STRING && Z_STRLEN_P(entry) > 0) {
            king_inference_error_taxonomy_add_unique_string(
                return_value,
                king_inference_error_code_from_runtime_reason(Z_STRVAL_P(entry))
            );
        }
    } ZEND_HASH_FOREACH_END();
}

static void king_inference_error_taxonomy_categories_from_code_list(
    zval *codes,
    zval *return_value)
{
    zval *entry;

    array_init(return_value);
    if (codes == NULL || Z_TYPE_P(codes) != IS_ARRAY) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(codes), entry) {
        if (Z_TYPE_P(entry) == IS_STRING && Z_STRLEN_P(entry) > 0) {
            king_inference_error_taxonomy_add_unique_string(
                return_value,
                king_inference_error_category_from_code(Z_STRVAL_P(entry))
            );
        }
    } ZEND_HASH_FOREACH_END();
}
