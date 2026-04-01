/* --- System lifecycle --- */

int king_object_store_init_system(king_object_store_config_t *config)
{
    char error[256];

    if (config == NULL) {
        return FAILURE;
    }

    king_object_store_upload_sessions_reset();
    king_object_store_runtime_metadata_cache_reset();
    king_object_store_config_clear(&king_object_store_runtime.config);
    memset(&king_object_store_runtime, 0, sizeof(king_object_store_runtime));
    ZVAL_UNDEF(&king_object_store_runtime.config.cloud_credentials);

    king_object_store_config_copy(&king_object_store_runtime.config, config);
    king_object_store_runtime.initialized = true;
    king_object_store_initialize_adapter_statuses();
    king_object_store_distributed_state_reset_runtime();

    if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
        mkdir(king_object_store_runtime.config.storage_root_path, 0755);
    }

    if (king_object_store_initialize_distributed_coordinator_state(error, sizeof(error)) != SUCCESS
        && error[0] == '\0'
        && king_object_store_runtime.distributed_coordinator_state_error[0] != '\0') {
        snprintf(error, sizeof(error), "%s", king_object_store_runtime.distributed_coordinator_state_error);
    }

    if (!king_object_store_backend_is_real(king_object_store_runtime.config.primary_backend)) {
        const char *message = "Primary backend is simulated-only and unavailable in this runtime.";
        if (king_object_store_backend_is_future_cloud(king_object_store_runtime.config.primary_backend) &&
            Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF) {
            message = king_object_store_missing_future_cloud_credentials_error("primary");
        }

        king_object_store_set_runtime_adapter_status(
            "primary",
            king_object_store_adapter_status_simulated,
            king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
            message
        );
    }

    if (!king_object_store_backend_is_real(king_object_store_runtime.config.backup_backend)) {
        const char *message = "Backup backend is simulated-only and unavailable in this runtime.";
        if (king_object_store_backend_is_future_cloud(king_object_store_runtime.config.backup_backend) &&
            Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF) {
            message = king_object_store_missing_future_cloud_credentials_error("backup");
        }

        king_object_store_set_runtime_adapter_status(
            "backup",
            king_object_store_adapter_status_simulated,
            king_object_store_backend_contract_to_string(king_object_store_runtime.config.backup_backend),
            message
        );
    }

    if (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_S3
        || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS
        || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
            king_object_store_set_runtime_adapter_status(
                "primary",
                king_object_store_adapter_status_failed,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
                "Missing required storage_root_path for cloud object-store metadata sidecars."
            );
        } else if (
            (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_S3
                && king_object_store_s3_rehydrate_stats(error, sizeof(error)) != SUCCESS)
            || (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                && king_object_store_gcs_rehydrate_stats(error, sizeof(error)) != SUCCESS)
            || (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE
                && king_object_store_azure_rehydrate_stats(error, sizeof(error)) != SUCCESS)
        ) {
            king_object_store_set_runtime_adapter_status(
                "primary",
                king_object_store_adapter_status_failed,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
                error
            );
        }
    }

    if (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS ||
        king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE ||
        king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
            /* Rehydrate live stats from any existing on-disk objects */
            king_object_store_rehydrate_stats();
        } else {
            king_object_store_set_runtime_adapter_status(
                "primary",
                king_object_store_adapter_status_unimplemented,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
                king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED
                    ? "Missing required storage_root_path for the distributed object-store data plane."
                    : "Missing required storage_root_path for local file-backed adapters."
            );
        }
    }

    if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
        DIR *dir;
        struct dirent *ent;
        char meta_path[1024];

        if (king_native_object_store_config.metadata_cache_enable
            && king_object_store_runtime_metadata_cache_ensure() == SUCCESS) {
            dir = opendir(king_object_store_runtime.config.storage_root_path);
            if (dir != NULL) {
                while ((ent = readdir(dir)) != NULL) {
                    king_object_metadata_t metadata;
                    size_t name_len;

                    if (ent->d_name[0] == '.') {
                        continue;
                    }

                    name_len = strlen(ent->d_name);
                    if (name_len <= 5 || strcmp(ent->d_name + name_len - 5, ".meta") != 0) {
                        continue;
                    }

                    snprintf(
                        meta_path,
                        sizeof(meta_path),
                        "%s/%s",
                        king_object_store_runtime.config.storage_root_path,
                        ent->d_name
                    );

                    if (king_object_store_meta_read_from_path(meta_path, &metadata) == SUCCESS) {
                        (void) king_object_store_runtime_metadata_cache_store(
                            metadata.object_id,
                            &metadata
                        );
                    }
                }

                closedir(dir);
            }
        }

        if (king_object_store_rehydrate_upload_sessions(error, sizeof(error)) != SUCCESS) {
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                error
            );
        }
    }

    return SUCCESS;
}

void king_object_store_shutdown_system(void)
{
    king_object_store_upload_sessions_reset();
    king_object_store_runtime_metadata_cache_reset();
    king_object_store_config_clear(&king_object_store_runtime.config);
    memset(&king_object_store_runtime, 0, sizeof(king_object_store_runtime));
    king_object_store_shutdown_libcurl_runtime();
}

void king_object_store_request_shutdown(void)
{
    king_object_store_upload_sessions_reset();
}
