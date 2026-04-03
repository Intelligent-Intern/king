static int king_object_store_azure_rehydrate_stats(char *error, size_t error_size)
{
    king_object_store_azure_runtime_config_t config;
    king_object_store_http_response_t response;
    uint64_t object_count = 0;
    uint64_t stored_bytes = 0;
    time_t latest_object_at = 0;

    if (king_object_store_azure_resolve_config(&config, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_http_response_init(&response);
    if (king_object_store_azure_http_request(
            &config,
            "GET",
            NULL,
            1,
            NULL,
            0,
            NULL,
            0,
            NULL,
            NULL,
            &response,
            error,
            error_size,
            NULL
        ) != SUCCESS) {
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.status_code < 200 || response.status_code >= 300) {
        king_object_store_azure_format_response_error(
            "GET",
            NULL,
            "inventory rehydrate",
            &response,
            error,
            error_size
        );
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    if (response.body.s != NULL && king_object_store_azure_parse_list_xml(
            ZSTR_VAL(response.body.s),
            NULL,
            &object_count,
            &stored_bytes,
            &latest_object_at
        ) != SUCCESS) {
        snprintf(error, error_size, "Failed to parse cloud_azure inventory XML during rehydrate.");
        king_object_store_http_response_destroy(&response);
        return FAILURE;
    }

    king_object_store_runtime.current_object_count = object_count;
    king_object_store_runtime.current_stored_bytes = stored_bytes;
    king_object_store_runtime.latest_object_at = latest_object_at;

    king_object_store_http_response_destroy(&response);
    return SUCCESS;
}
