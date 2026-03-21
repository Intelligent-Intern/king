PHP_FUNCTION(king_set_ca_file)
{
    king_tls_set_ca_file_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_set_ca_file"
    );
}

PHP_FUNCTION(king_client_tls_set_ca_file)
{
    king_tls_set_ca_file_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_client_tls_set_ca_file"
    );
}

PHP_FUNCTION(king_set_client_cert)
{
    king_tls_set_client_cert_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_set_client_cert"
    );
}

PHP_FUNCTION(king_client_tls_set_client_cert)
{
    king_tls_set_client_cert_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_client_tls_set_client_cert"
    );
}

PHP_FUNCTION(king_export_session_ticket)
{
    king_tls_export_ticket_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_export_session_ticket"
    );
}

PHP_FUNCTION(king_client_tls_export_session_ticket)
{
    king_tls_export_ticket_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_client_tls_export_session_ticket"
    );
}

PHP_FUNCTION(king_import_session_ticket)
{
    king_tls_import_ticket_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_import_session_ticket"
    );
}

PHP_FUNCTION(king_client_tls_import_session_ticket)
{
    king_tls_import_ticket_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_client_tls_import_session_ticket"
    );
}
