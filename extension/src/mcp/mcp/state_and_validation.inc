static void king_mcp_set_errorf(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static void king_mcp_state_set_error_kind(
    king_mcp_state *state,
    king_mcp_error_kind_t kind
)
{
    if (state != NULL) {
        state->last_error_kind = kind;
    }
}

static void king_mcp_state_clear_error_kind(king_mcp_state *state)
{
    king_mcp_state_set_error_kind(state, KING_MCP_ERROR_NONE);
}

static void king_mcp_state_set_error_message(
    king_mcp_state *state,
    king_mcp_error_kind_t kind,
    const char *message
)
{
    king_mcp_state_set_error_kind(state, kind);
    king_set_error(message);
}

static void king_mcp_state_set_errorf(
    king_mcp_state *state,
    king_mcp_error_kind_t kind,
    const char *format,
    ...
)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_mcp_state_set_error_message(state, kind, message);
}

static zend_string *king_mcp_base64url_encode_bytes(
    const unsigned char *value,
    size_t value_len
)
{
    zend_string *encoded = php_base64_encode(value, value_len);
    size_t read_index = 0;
    size_t write_index = 0;

    if (encoded == NULL) {
        return NULL;
    }

    for (read_index = 0; read_index < ZSTR_LEN(encoded); read_index++) {
        char current = ZSTR_VAL(encoded)[read_index];

        if (current == '=') {
            continue;
        }
        if (current == '+') {
            current = '-';
        } else if (current == '/') {
            current = '_';
        }

        ZSTR_VAL(encoded)[write_index++] = current;
    }

    ZSTR_VAL(encoded)[write_index] = '\0';
    ZSTR_LEN(encoded) = write_index;

    return encoded;
}

static zend_string *king_mcp_transfer_key_create(
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len
)
{
    zend_string *encoded_service = NULL;
    zend_string *encoded_method = NULL;
    zend_string *encoded_id = NULL;
    size_t key_len;
    zend_string *key = NULL;
    char *cursor;

    encoded_service = king_mcp_base64url_encode_bytes((const unsigned char *) service, service_len);
    encoded_method = king_mcp_base64url_encode_bytes((const unsigned char *) method, method_len);
    encoded_id = king_mcp_base64url_encode_bytes((const unsigned char *) id, id_len);
    if (encoded_service == NULL || encoded_method == NULL || encoded_id == NULL) {
        if (encoded_service != NULL) {
            zend_string_release(encoded_service);
        }
        if (encoded_method != NULL) {
            zend_string_release(encoded_method);
        }
        if (encoded_id != NULL) {
            zend_string_release(encoded_id);
        }
        return NULL;
    }

    key_len = (sizeof("mcp.v1.") - 1)
        + ZSTR_LEN(encoded_service)
        + 1
        + ZSTR_LEN(encoded_method)
        + 1
        + ZSTR_LEN(encoded_id);
    key = zend_string_alloc(key_len, 0);
    cursor = ZSTR_VAL(key);

    memcpy(cursor, "mcp.v1.", sizeof("mcp.v1.") - 1);
    cursor += sizeof("mcp.v1.") - 1;
    memcpy(cursor, ZSTR_VAL(encoded_service), ZSTR_LEN(encoded_service));
    cursor += ZSTR_LEN(encoded_service);
    *cursor++ = '.';
    memcpy(cursor, ZSTR_VAL(encoded_method), ZSTR_LEN(encoded_method));
    cursor += ZSTR_LEN(encoded_method);
    *cursor++ = '.';
    memcpy(cursor, ZSTR_VAL(encoded_id), ZSTR_LEN(encoded_id));
    ZSTR_VAL(key)[key_len] = '\0';

    zend_string_release(encoded_service);
    zend_string_release(encoded_method);
    zend_string_release(encoded_id);

    return key;
}

static const char *king_mcp_transfer_state_path(void)
{
    return king_mcp_orchestrator_config.mcp_transfer_state_path;
}

static int king_mcp_transfer_state_path_is_configured(void)
{
    const char *state_path = king_mcp_transfer_state_path();

    return state_path != NULL && state_path[0] != '\0';
}

static zend_string *king_mcp_normalize_host_literal(
    const char *host,
    size_t host_len,
    const char **error_out
)
{
    const char *normalized_host = host;
    size_t normalized_len = host_len;
    bool ipv6_literal = false;
    zend_string *result;
    size_t i;

    if (error_out != NULL) {
        *error_out = NULL;
    }

    if (host == NULL || host_len == 0) {
        if (error_out != NULL) {
            *error_out = "MCP peer host must be a non-empty host string.";
        }
        return NULL;
    }

    if (memchr(host, '\0', host_len) != NULL) {
        if (error_out != NULL) {
            *error_out = "MCP peer host must not contain NUL bytes.";
        }
        return NULL;
    }

    if (host[0] == '[') {
        if (host_len < 3 || host[host_len - 1] != ']') {
            if (error_out != NULL) {
                *error_out = "MCP peer host must use balanced brackets for IPv6 literals.";
            }
            return NULL;
        }

        normalized_host = host + 1;
        normalized_len = host_len - 2;
        ipv6_literal = true;
    } else if (host[host_len - 1] == ']') {
        if (error_out != NULL) {
            *error_out = "MCP peer host must use balanced brackets for IPv6 literals.";
        }
        return NULL;
    } else if (memchr(host, ':', host_len) != NULL) {
        ipv6_literal = true;
    }

    if (normalized_len == 0) {
        if (error_out != NULL) {
            *error_out = "MCP peer host must be a non-empty host string.";
        }
        return NULL;
    }

    for (i = 0; i < normalized_len; i++) {
        unsigned char current = (unsigned char) normalized_host[i];

        if (iscntrl(current) || isspace(current)) {
            if (error_out != NULL) {
                *error_out = "MCP peer host contains unsupported whitespace or control characters.";
            }
            return NULL;
        }

        if (ipv6_literal) {
            if (!isxdigit(current) && current != ':' && current != '.') {
                if (error_out != NULL) {
                    *error_out = "MCP peer host contains unsupported characters.";
                }
                return NULL;
            }
            continue;
        }

        if (!isalnum(current) && current != '.' && current != '-') {
            if (error_out != NULL) {
                *error_out = "MCP peer host contains unsupported characters.";
            }
            return NULL;
        }
    }

    result = zend_string_alloc(normalized_len, 0);
    for (i = 0; i < normalized_len; i++) {
        ZSTR_VAL(result)[i] = (char) tolower((unsigned char) normalized_host[i]);
    }
    ZSTR_VAL(result)[normalized_len] = '\0';

    return result;
}

static bool king_mcp_host_is_loopback(zend_string *normalized_host)
{
    struct in_addr addr4;
    struct in6_addr addr6;

    if (normalized_host == NULL) {
        return false;
    }

    if (zend_string_equals_literal(normalized_host, "localhost")) {
        return true;
    }

    if (inet_pton(AF_INET, ZSTR_VAL(normalized_host), &addr4) == 1) {
        return (ntohl(addr4.s_addr) & 0xff000000U) == 0x7f000000U;
    }

    if (inet_pton(AF_INET6, ZSTR_VAL(normalized_host), &addr6) == 1) {
        return IN6_IS_ADDR_LOOPBACK(&addr6);
    }

    return false;
}

static bool king_mcp_host_is_in_allowlist(
    zend_string *normalized_host,
    bool *config_invalid_out
)
{
    const char *allowlist = king_mcp_orchestrator_config.mcp_allowed_peer_hosts;
    const char *cursor = allowlist;

    if (config_invalid_out != NULL) {
        *config_invalid_out = false;
    }

    if (
        normalized_host == NULL
        || allowlist == NULL
        || allowlist[0] == '\0'
    ) {
        return false;
    }

    while (*cursor != '\0') {
        const char *entry_start = cursor;
        const char *entry_end;
        size_t entry_len;
        const char *entry_error = NULL;
        zend_string *normalized_entry;

        while (*entry_start != '\0' && isspace((unsigned char) *entry_start)) {
            entry_start++;
        }

        cursor = entry_start;
        while (*cursor != '\0' && *cursor != ',') {
            cursor++;
        }
        entry_end = cursor;
        while (
            entry_end > entry_start
            && isspace((unsigned char) *(entry_end - 1))
        ) {
            entry_end--;
        }

        entry_len = (size_t) (entry_end - entry_start);
        if (entry_len > 0) {
            normalized_entry = king_mcp_normalize_host_literal(
                entry_start,
                entry_len,
                &entry_error
            );
            if (normalized_entry == NULL) {
                if (config_invalid_out != NULL) {
                    *config_invalid_out = true;
                }
                return false;
            }

            if (zend_string_equals(normalized_entry, normalized_host)) {
                zend_string_release(normalized_entry);
                return true;
            }

            zend_string_release(normalized_entry);
        }

        if (*cursor == ',') {
            cursor++;
        }
    }

    return false;
}

static zend_result king_mcp_validate_peer_target(
    const char *host,
    size_t host_len,
    zend_long port,
    zend_string **normalized_host_out
)
{
    const char *error_message = NULL;
    zend_string *normalized_host = NULL;
    bool config_invalid = false;

    if (normalized_host_out != NULL) {
        *normalized_host_out = NULL;
    }

    if (port <= 0 || port > 65535) {
        king_set_error("MCP peer port must be between 1 and 65535.");
        return FAILURE;
    }

    normalized_host = king_mcp_normalize_host_literal(host, host_len, &error_message);
    if (normalized_host == NULL) {
        king_set_error(
            error_message != NULL
                ? error_message
                : "MCP peer host is invalid."
        );
        return FAILURE;
    }

    if (!king_mcp_host_is_loopback(normalized_host)) {
        if (!king_mcp_host_is_in_allowlist(normalized_host, &config_invalid)) {
            if (config_invalid) {
                king_set_error(
                    "king.mcp_allowed_peer_hosts contains an invalid host entry."
                );
            } else {
                king_mcp_set_errorf(
                    "MCP peer host '%s' is not permitted. Only loopback peers are allowed by default; allow remote peers with king.mcp_allowed_peer_hosts.",
                    ZSTR_VAL(normalized_host)
                );
            }
            zend_string_release(normalized_host);
            return FAILURE;
        }
    }

    if (normalized_host_out != NULL) {
        *normalized_host_out = normalized_host;
    } else {
        zend_string_release(normalized_host);
    }

    return SUCCESS;
}

