/*
 * =========================================================================
 * FILENAME:   src/client/session/state/resource.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Resource/object bridge and lifetime cleanup for `King\\Session`. This file
 * resolves either resource or object handles back to the active session
 * state, and releases the accumulated transport, TLS, config, server, and
 * cancel snapshot fields when the session resource is destroyed.
 * =========================================================================
 */

king_client_session_t *king_client_session_fetch_resource(
    zval *zsession,
    uint32_t arg_num)
{
    if (Z_TYPE_P(zsession) == IS_OBJECT
        && king_ce_session != NULL
        && instanceof_function(Z_OBJCE_P(zsession), king_ce_session)) {
        king_session_object *intern = php_king_obj_from_zend(Z_OBJ_P(zsession));

        if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
            if (arg_num > 0) {
                zend_argument_type_error(
                    arg_num,
                    "must be an initialized King\\Session object or resource"
                );
            } else {
                zend_throw_exception_ex(
                    king_ce_runtime_exception,
                    0,
                    "King\\Session object is not initialized."
                );
            }
            return NULL;
        }

        zsession = &intern->resource;
    }

    if (Z_TYPE_P(zsession) != IS_RESOURCE) {
        zend_argument_type_error(arg_num, "must be a King\\Session resource or King\\Session object");
        return NULL;
    }

    return (king_client_session_t *) zend_fetch_resource(
        Z_RES_P(zsession),
        "King\\Session",
        le_king_session
    );
}

void king_client_session_free(void *session_ptr)
{
    king_client_session_t *session = (king_client_session_t *) session_ptr;

    if (session == NULL) {
        return;
    }

    if (session->host != NULL) {
        zend_string_release(session->host);
    }

    if (session->negotiated_alpn != NULL) {
        zend_string_release(session->negotiated_alpn);
    }

    if (session->config_autoscale_provider != NULL) {
        zend_string_release(session->config_autoscale_provider);
    }

    if (session->config_quic_cc_algorithm != NULL) {
        zend_string_release(session->config_quic_cc_algorithm);
    }

    if (session->config_storage_default_redundancy_mode != NULL) {
        zend_string_release(session->config_storage_default_redundancy_mode);
    }

    if (session->config_cdn_cache_mode != NULL) {
        zend_string_release(session->config_cdn_cache_mode);
    }

    if (session->config_dns_mode != NULL) {
        zend_string_release(session->config_dns_mode);
    }

    if (session->config_security_cors_allowed_origins != NULL) {
        zend_string_release(session->config_security_cors_allowed_origins);
    }

    if (session->config_otel_service_name != NULL) {
        zend_string_release(session->config_otel_service_name);
    }

    if (session->config_otel_exporter_endpoint != NULL) {
        zend_string_release(session->config_otel_exporter_endpoint);
    }

    if (session->config_otel_exporter_protocol != NULL) {
        zend_string_release(session->config_otel_exporter_protocol);
    }

    if (session->config_geometry_calculation_precision != NULL) {
        zend_string_release(session->config_geometry_calculation_precision);
    }

    if (session->config_smartcontract_dlt_provider != NULL) {
        zend_string_release(session->config_smartcontract_dlt_provider);
    }

    if (session->config_ssh_gateway_auth_mode != NULL) {
        zend_string_release(session->config_ssh_gateway_auth_mode);
    }

    if (session->config_tcp_tls_min_version_allowed != NULL) {
        zend_string_release(session->config_tcp_tls_min_version_allowed);
    }

    if (session->last_cancel_mode != NULL) {
        zend_string_release(session->last_cancel_mode);
    }

    if (session->tls_default_ca_file != NULL) {
        zend_string_release(session->tls_default_ca_file);
    }

    if (session->tls_default_cert_file != NULL) {
        zend_string_release(session->tls_default_cert_file);
    }

    if (session->tls_default_key_file != NULL) {
        zend_string_release(session->tls_default_key_file);
    }

    if (session->tls_ticket_key_file != NULL) {
        zend_string_release(session->tls_ticket_key_file);
    }

    if (session->tls_session_ticket != NULL) {
        if (ZSTR_LEN(session->tls_session_ticket) > 0) {
            king_secure_zero(ZSTR_VAL(session->tls_session_ticket), ZSTR_LEN(session->tls_session_ticket));
        }
        zend_string_release(session->tls_session_ticket);
    }

    if (session->transport_backend != NULL) {
        zend_string_release(session->transport_backend);
    }

    if (session->transport_local_address != NULL) {
        zend_string_release(session->transport_local_address);
    }

    if (session->transport_peer_address != NULL) {
        zend_string_release(session->transport_peer_address);
    }

    if (session->transport_socket_family != NULL) {
        zend_string_release(session->transport_socket_family);
    }

    if (session->transport_error_scope != NULL) {
        zend_string_release(session->transport_error_scope);
    }

    if (session->server_peer_cert_subject != NULL) {
        zend_string_release(session->server_peer_cert_subject);
    }

    if (session->server_close_reason != NULL) {
        zend_string_release(session->server_close_reason);
    }

    if (session->server_last_admin_api_bind_host != NULL) {
        zend_string_release(session->server_last_admin_api_bind_host);
    }

    if (session->server_last_admin_api_auth_mode != NULL) {
        zend_string_release(session->server_last_admin_api_auth_mode);
    }

    if (session->server_last_cors_policy != NULL) {
        zend_string_release(session->server_last_cors_policy);
    }

    if (session->server_last_cors_origin != NULL) {
        zend_string_release(session->server_last_cors_origin);
    }

    if (session->server_last_cors_allow_origin != NULL) {
        zend_string_release(session->server_last_cors_allow_origin);
    }

    if (session->server_telemetry_service_name != NULL) {
        zend_string_release(session->server_telemetry_service_name);
    }

    if (session->server_telemetry_exporter_endpoint != NULL) {
        zend_string_release(session->server_telemetry_exporter_endpoint);
    }

    if (session->server_telemetry_exporter_protocol != NULL) {
        zend_string_release(session->server_telemetry_exporter_protocol);
    }

    if (session->server_telemetry_last_protocol != NULL) {
        zend_string_release(session->server_telemetry_last_protocol);
    }

    if (session->server_last_tls_cert_file != NULL) {
        zend_string_release(session->server_last_tls_cert_file);
    }

    if (session->server_last_tls_key_file != NULL) {
        zend_string_release(session->server_last_tls_key_file);
    }

    if (session->server_last_tls_ticket_key_file != NULL) {
        zend_string_release(session->server_last_tls_ticket_key_file);
    }

    if (session->server_last_websocket_url != NULL) {
        zend_string_release(session->server_last_websocket_url);
    }

    if (session->server_pending_request_target != NULL) {
        zend_string_release(session->server_pending_request_target);
    }

    if (session->server_pending_websocket_key != NULL) {
        zend_string_release(session->server_pending_websocket_key);
    }

    if (session->cancelled_streams_initialized) {
        zend_hash_destroy(&session->cancelled_streams);
    }

    if (session->server_cancel_handlers_initialized) {
        zend_hash_destroy(&session->server_cancel_handlers);
    }

    if (session->server_upgraded_streams_initialized) {
        zend_hash_destroy(&session->server_upgraded_streams);
    }

    if (!Z_ISUNDEF(session->server_last_early_hints)) {
        zval_ptr_dtor(&session->server_last_early_hints);
    }

    king_client_session_close_socket(session);
    efree(session);
}
