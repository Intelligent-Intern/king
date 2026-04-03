static const char *king_object_store_initial_backend_status(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);

    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_DISTRIBUTED:
        case KING_STORAGE_BACKEND_CLOUD_S3:
        case KING_STORAGE_BACKEND_CLOUD_GCS:
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            return king_object_store_adapter_status_ok;
        default:
            return king_object_store_adapter_status_unimplemented;
    }
}

void king_object_store_initialize_adapter_statuses(void)
{
    strncpy(
        king_object_store_runtime.primary_adapter_status,
        king_object_store_initial_backend_status(king_object_store_runtime.config.primary_backend),
        sizeof(king_object_store_runtime.primary_adapter_status) - 1
    );
    strncpy(
        king_object_store_runtime.backup_adapter_status,
        king_object_store_initial_backend_status(king_object_store_runtime.config.backup_backend),
        sizeof(king_object_store_runtime.backup_adapter_status) - 1
    );
    strncpy(
        king_object_store_runtime.primary_adapter_contract,
        king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
        sizeof(king_object_store_runtime.primary_adapter_contract) - 1
    );
    strncpy(
        king_object_store_runtime.backup_adapter_contract,
        king_object_store_backend_contract_to_string(king_object_store_runtime.config.backup_backend),
        sizeof(king_object_store_runtime.backup_adapter_contract) - 1
    );
}

void king_object_store_set_runtime_adapter_status(
    const char *scope,
    const char *status,
    const char *contract,
    const char *error
)
{
    char *status_target = NULL;
    char *contract_target = NULL;
    char *error_target = NULL;
    const char *safe_status = status == NULL ? king_object_store_adapter_status_unknown : status;
    const char *safe_contract = contract == NULL ? king_object_store_adapter_contract_unconfigured : contract;

    if (scope == NULL || scope[0] == '\0') {
        return;
    }
    if (strcmp(scope, "primary") == 0) {
        status_target = king_object_store_runtime.primary_adapter_status;
        contract_target = king_object_store_runtime.primary_adapter_contract;
        error_target = king_object_store_runtime.primary_adapter_error;
    } else if (strcmp(scope, "backup") == 0) {
        status_target = king_object_store_runtime.backup_adapter_status;
        contract_target = king_object_store_runtime.backup_adapter_contract;
        error_target = king_object_store_runtime.backup_adapter_error;
    } else {
        return;
    }

    snprintf(status_target, 24, "%s", safe_status);
    snprintf(contract_target, 16, "%s", safe_contract);
    if (error == NULL || error[0] == '\0') {
        error_target[0] = '\0';
    } else {
        size_t error_target_size = 512;
        king_object_store_append_adapter_error(error_target, error_target_size, error);
    }
}

static void king_object_store_set_backend_runtime_result(
    const char *scope,
    king_storage_backend_t backend,
    int success,
    const char *error
)
{
    int operation_succeeded = (success == SUCCESS);
    const char *contract = king_object_store_backend_contract_to_string(backend);
    const char *status = operation_succeeded ? king_object_store_initial_backend_status(backend) : king_object_store_adapter_status_failed;
    if (!operation_succeeded && (error == NULL || error[0] == '\0')) {
        error = "Object-store backend operation failed.";
    }
    king_object_store_set_runtime_adapter_status(scope, status, contract, error);
}

static char *king_object_store_runtime_adapter_error_target(const char *scope)
{
    if (scope == NULL || scope[0] == '\0') {
        return NULL;
    }

    if (strcmp(scope, "primary") == 0) {
        return king_object_store_runtime.primary_adapter_error;
    }
    if (strcmp(scope, "backup") == 0) {
        return king_object_store_runtime.backup_adapter_error;
    }

    return NULL;
}

static void king_object_store_replace_runtime_adapter_status(
    const char *scope,
    const char *status,
    const char *contract,
    const char *error
)
{
    char *error_target = king_object_store_runtime_adapter_error_target(scope);

    if (error_target != NULL) {
        error_target[0] = '\0';
    }
    king_object_store_set_runtime_adapter_status(scope, status, contract, error);
}

static void king_object_store_finalize_backend_runtime_result(
    const char *scope,
    king_storage_backend_t backend,
    int result,
    const char *fallback_error
)
{
    const char *contract = king_object_store_backend_contract_to_string(backend);
    char *error_target = king_object_store_runtime_adapter_error_target(scope);
    const char *message = NULL;
    char error_copy[512];

    if (result == SUCCESS || result == KING_OBJECT_STORE_RESULT_MISS) {
        king_object_store_replace_runtime_adapter_status(
            scope,
            king_object_store_initial_backend_status(backend),
            contract,
            NULL
        );
        return;
    }

    if (result == KING_OBJECT_STORE_RESULT_UNAVAILABLE) {
        if (error_target == NULL || error_target[0] == '\0') {
            king_object_store_replace_runtime_adapter_status(
                scope,
                king_object_store_adapter_status_failed,
                contract,
                fallback_error != NULL ? fallback_error : "Object-store backend is unavailable."
            );
        }
        return;
    }

    message = (error_target != NULL && error_target[0] != '\0')
        ? error_target
        : fallback_error;
    if (message != NULL) {
        snprintf(error_copy, sizeof(error_copy), "%s", message);
        message = error_copy;
    }
    king_object_store_replace_runtime_adapter_status(
        scope,
        king_object_store_adapter_status_failed,
        contract,
        message != NULL ? message : "Object-store backend operation failed."
    );
}

static int king_object_store_backend_is_local(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);
    return backend == KING_STORAGE_BACKEND_LOCAL_FS;
}

static int king_object_store_backend_is_real(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);
    return backend == KING_STORAGE_BACKEND_LOCAL_FS
        || backend == KING_STORAGE_BACKEND_DISTRIBUTED
        || backend == KING_STORAGE_BACKEND_CLOUD_S3
        || backend == KING_STORAGE_BACKEND_CLOUD_GCS
        || backend == KING_STORAGE_BACKEND_CLOUD_AZURE;
}

static zend_bool king_object_store_backend_is_future_cloud(king_storage_backend_t backend)
{
    (void) backend;
    return 0;
}

static const char *king_object_store_missing_future_cloud_credentials_error(const char *scope)
{
    if (scope != NULL && strcmp(scope, "backup") == 0) {
        return "Cloud credentials are required to enable native cloud backup backends.";
    }

    return "Cloud credentials are required to enable native cloud backend operation.";
}

static int king_object_store_require_honest_backend(
    const char *scope,
    king_storage_backend_t backend,
    const char *operation
)
{
    const char *backend_name;
    const char *contract;
    char message[256];

    if (king_object_store_backend_is_real(backend) == 1) {
        return SUCCESS;
    }

    if (operation == NULL || operation[0] == '\0') {
        operation = "an operation";
    }

    backend_name = king_storage_backend_to_string(backend);
    contract = king_object_store_backend_contract_to_string(backend);

    if (
        king_object_store_backend_is_future_cloud(backend)
        && Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s",
            king_object_store_missing_future_cloud_credentials_error(scope)
        );
    } else {
        snprintf(
            message,
            sizeof(message),
            "object-store backend '%s' is unavailable for %s.",
            backend_name,
            operation
        );
    }

    king_object_store_replace_runtime_adapter_status(
        scope,
        king_object_store_adapter_status_failed,
        contract,
        message
    );

    return FAILURE;
}
