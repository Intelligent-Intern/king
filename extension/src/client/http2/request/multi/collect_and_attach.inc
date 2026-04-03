static zend_result king_http2_multi_collect_transfer_meta(
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_transfer_t *transfer,
    const char *function_name,
    const char *transfer_label)
{
    if (transfer->curl_code != CURLE_OK) {
        if (transfer->response.header_overflowed) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() %s exceeded the active runtime HTTP/2 response-header size limit.",
                function_name,
                transfer_label
            );
        } else if (transfer->response.body_overflowed) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() %s exceeded the active runtime HTTP/2 response-body size limit.",
                function_name,
                transfer_label
            );
        } else {
            king_http2_throw(
                king_http2_map_curl_exception(transfer->curl_code),
                "%s() %s libcurl HTTP/2 transfer failed: %s",
                function_name,
                transfer_label,
                king_http2_libcurl.curl_easy_strerror_fn(transfer->curl_code)
            );
        }

        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    if (transfer->response.body_overflowed) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() %s exceeded the active runtime HTTP/2 response-body size limit.",
            function_name,
            transfer_label
        );
        return FAILURE;
    }

    if (transfer->response.header_overflowed) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() %s exceeded the active runtime HTTP/2 response-header size limit.",
            function_name,
            transfer_label
        );
        return FAILURE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            transfer->easy,
            CURLINFO_RESPONSE_CODE,
            &transfer->response_code
        ) != CURLE_OK) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() failed to read the HTTP/2 response code for %s from libcurl.",
            function_name,
            transfer_label
        );
        return FAILURE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            transfer->easy,
            CURLINFO_HTTP_VERSION,
            &transfer->http_version
        ) != CURLE_OK) {
        transfer->http_version = CURL_HTTP_VERSION_NONE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            transfer->easy,
            CURLINFO_EFFECTIVE_URL,
            &transfer->effective_url
        ) != CURLE_OK) {
        transfer->effective_url = NULL;
    }

    return SUCCESS;
}

static zend_result king_http2_multi_collect_meta(
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_batch_state_t *batch_state)
{
    size_t i;
    size_t push_index = 0;
    char label[96];
    king_http2_push_transfer_node_t *node;

    for (i = 0; i < batch_state->request_count; ++i) {
        snprintf(label, sizeof(label), "request #%zu", i + 1);
        if (king_http2_multi_collect_transfer_meta(
                pool_entry,
                &batch_state->request_transfers[i],
                batch_state->function_name,
                label
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    for (node = batch_state->push_head; node != NULL; node = node->next) {
        push_index++;
        snprintf(label, sizeof(label), "captured push #%zu", push_index);
        if (king_http2_multi_collect_transfer_meta(
                pool_entry,
                &node->transfer,
                batch_state->function_name,
                label
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http2_multi_attach_pushes(
    zval *response_value,
    king_http2_multi_batch_state_t *batch_state,
    size_t request_index)
{
    zval pushes;
    king_http2_push_transfer_node_t *node;
    size_t push_count = 0;

    array_init(&pushes);

    for (node = batch_state->push_head; node != NULL; node = node->next) {
        zval push_value;

        if (!node->transfer.has_parent_request_index
            || node->transfer.parent_request_index != request_index) {
            continue;
        }

        ZVAL_UNDEF(&push_value);
        if (king_http2_request_materialize_transfer_response(
                &push_value,
                &node->transfer
            ) != SUCCESS) {
            if (Z_TYPE(push_value) != IS_UNDEF) {
                zval_ptr_dtor(&push_value);
            }
            zval_ptr_dtor(&pushes);
            return FAILURE;
        }

        add_next_index_zval(&pushes, &push_value);
        push_count++;
    }

    add_assoc_long(response_value, "push_count", (zend_long) push_count);
    add_assoc_zval(response_value, "pushes", &pushes);

    return SUCCESS;
}

