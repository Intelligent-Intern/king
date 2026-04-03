static zend_result king_http2_request_drive_multi_batch(
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_batch_state_t *batch_state)
{
    CURLMcode multi_code;
    CURLMcode remove_code;
    CURLMsg *message;
    int running_handles = 0;
    int ready_fds = 0;
    int msgs_in_queue = 0;
    zend_long wait_timeout_ms;
    zend_long current_wait_timeout_ms;
    zend_long deadline_start_ms = 0;
    size_t i;
    king_http2_push_transfer_node_t *node;

    if (batch_state != NULL && batch_state->capture_push) {
        if (king_http2_libcurl.curl_pushheader_bynum_fn == NULL) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() active libcurl HTTP/2 runtime does not expose server-push header capture support.",
                batch_state->function_name
            );
            return FAILURE;
        }

        multi_code = king_http2_libcurl.curl_multi_setopt_fn(
            pool_entry->multi,
            CURLMOPT_PUSHFUNCTION,
            king_http2_multi_push_callback
        );
        if (multi_code == CURLM_UNKNOWN_OPTION) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() active libcurl HTTP/2 runtime does not support server-push callbacks.",
                batch_state->function_name
            );
            return FAILURE;
        }
        if (multi_code != CURLM_OK) {
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to configure HTTP/2 server-push callbacks on the active libcurl session pool: %s",
                batch_state->function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            return FAILURE;
        }

        multi_code = king_http2_libcurl.curl_multi_setopt_fn(
            pool_entry->multi,
            CURLMOPT_PUSHDATA,
            batch_state
        );
        if (multi_code == CURLM_UNKNOWN_OPTION) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() active libcurl HTTP/2 runtime does not support HTTP/2 server-push userdata wiring.",
                batch_state->function_name
            );
            return FAILURE;
        }
        if (multi_code != CURLM_OK) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to attach HTTP/2 server-push state to the active libcurl session pool: %s",
                batch_state->function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            return FAILURE;
        }
    } else {
        king_http2_multi_reset_push_state(pool_entry);
    }

    for (i = 0; i < batch_state->request_count; ++i) {
        multi_code = king_http2_libcurl.curl_multi_add_handle_fn(
            pool_entry->multi,
            batch_state->request_transfers[i].easy
        );
        if (multi_code != CURLM_OK) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to attach request #%zu to the libcurl HTTP/2 session pool: %s",
                batch_state->function_name,
                i + 1,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
    }

    do {
        multi_code = king_http2_libcurl.curl_multi_perform_fn(pool_entry->multi, &running_handles);
    } while (multi_code == CURLM_CALL_MULTI_PERFORM);

    if (multi_code != CURLM_OK) {
        king_http2_multi_reset_push_state(pool_entry);
        king_http2_throw(
            king_ce_system_exception,
            "%s() failed to drive the active libcurl HTTP/2 session pool: %s",
            batch_state->function_name,
            king_http2_libcurl.curl_multi_strerror_fn(multi_code)
        );
        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    wait_timeout_ms = batch_state->options->timeout_ms > 0
        && batch_state->options->timeout_ms < KING_HTTP2_MULTI_WAIT_TIMEOUT_MS
        ? batch_state->options->timeout_ms
        : KING_HTTP2_MULTI_WAIT_TIMEOUT_MS;
    if (wait_timeout_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS) {
        wait_timeout_ms = KING_TRANSPORT_INTERRUPT_SLICE_MS;
    }
    if (batch_state->capture_push && batch_state->options->timeout_ms > 0) {
        deadline_start_ms = king_http2_multi_now_ms();
    }

    do {
        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
        if (king_transport_maybe_throw_cancel(
                batch_state->options->cancel_token,
                batch_state->function_name,
                batch_state->options->cancel_function_name,
                batch_state->options->cancel_exception_ce,
                "HTTP/2"
            ) != SUCCESS) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }

        if (deadline_start_ms > 0) {
            zend_long elapsed_ms = king_http2_multi_now_ms() - deadline_start_ms;

            if (elapsed_ms >= batch_state->options->timeout_ms) {
                king_http2_multi_reset_push_state(pool_entry);
                king_http2_throw(
                    king_ce_timeout_exception,
                    "%s() exceeded the active HTTP/2 batch timeout while waiting for pushed streams to finish.",
                    batch_state->function_name
                );
                king_http2_pool_remove(pool_entry);
                return FAILURE;
            }

            current_wait_timeout_ms = batch_state->options->timeout_ms - elapsed_ms;
            if (current_wait_timeout_ms > KING_HTTP2_MULTI_WAIT_TIMEOUT_MS) {
                current_wait_timeout_ms = KING_HTTP2_MULTI_WAIT_TIMEOUT_MS;
            }
        } else {
            current_wait_timeout_ms = wait_timeout_ms;
        }
        if (current_wait_timeout_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS) {
            current_wait_timeout_ms = KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }

        do {
            multi_code = king_http2_libcurl.curl_multi_perform_fn(pool_entry->multi, &running_handles);
        } while (multi_code == CURLM_CALL_MULTI_PERFORM);

        if (multi_code != CURLM_OK) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to continue the active libcurl HTTP/2 session pool: %s",
                batch_state->function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }

        if (running_handles > 0) {
            ready_fds = 0;
            multi_code = king_http2_libcurl.curl_multi_wait_fn(
                pool_entry->multi,
                NULL,
                0,
                (int) current_wait_timeout_ms,
                &ready_fds
            );
            if (multi_code != CURLM_OK) {
                king_http2_multi_reset_push_state(pool_entry);
                king_http2_throw(
                    king_ce_system_exception,
                    "%s() failed while waiting on the active libcurl HTTP/2 session pool: %s",
                    batch_state->function_name,
                    king_http2_libcurl.curl_multi_strerror_fn(multi_code)
                );
                king_http2_pool_remove(pool_entry);
                return FAILURE;
            }
        }

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
        if (king_transport_maybe_throw_cancel(
                batch_state->options->cancel_token,
                batch_state->function_name,
                batch_state->options->cancel_function_name,
                batch_state->options->cancel_exception_ce,
                "HTTP/2"
            ) != SUCCESS) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }

        while ((message = king_http2_libcurl.curl_multi_info_read_fn(pool_entry->multi, &msgs_in_queue)) != NULL) {
            if (message->msg == CURLMSG_DONE) {
                king_http2_multi_transfer_t *transfer = king_http2_multi_find_transfer(
                    batch_state,
                    message->easy_handle
                );

                if (transfer != NULL && !transfer->completed) {
                    transfer->curl_code = message->data.result;
                    transfer->completed = true;

                    remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(
                        pool_entry->multi,
                        message->easy_handle
                    );
                    if (remove_code != CURLM_OK) {
                        king_http2_multi_reset_push_state(pool_entry);
                        king_http2_throw(
                            king_ce_system_exception,
                            "%s() failed to detach a completed HTTP/2 stream from the libcurl session pool: %s",
                            batch_state->function_name,
                            king_http2_libcurl.curl_multi_strerror_fn(remove_code)
                        );
                        king_http2_pool_remove(pool_entry);
                        return FAILURE;
                    }

                    if (batch_state->open_transfers > 0) {
                        batch_state->open_transfers--;
                    }
                }
            }
        }
    } while (batch_state->open_transfers > 0);

    for (i = 0; i < batch_state->request_count; ++i) {
        if (!batch_state->request_transfers[i].completed) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() did not receive a completion signal for request #%zu from the libcurl HTTP/2 session pool.",
                batch_state->function_name,
                i + 1
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
    }

    for (node = batch_state->push_head; node != NULL; node = node->next) {
        if (!node->transfer.completed) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() did not receive a completion signal for a pushed HTTP/2 stream from the libcurl session pool.",
                batch_state->function_name
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
    }

    king_http2_multi_reset_push_state(pool_entry);
    return SUCCESS;
}

