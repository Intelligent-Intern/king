/*
 * Native MCP runtime for King. Owns the local MCP state, the bounded
 * line-framed remote-peer protocol for request/upload/download operations and
 * the persisted transfer-state helpers that keep transfers resumable across
 * restart and multi-host execution paths.
 */
#include "include/mcp/mcp.h"
#include "include/php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/base64.h"
#include "main/php_network.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <netinet/in.h>
#include <stdarg.h>
#include <string.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

#define KING_MCP_REMOTE_LINE_OVERHEAD 4096
#define KING_MCP_REMOTE_OP_REQUEST "REQ"
#define KING_MCP_REMOTE_OP_UPLOAD "PUT"
#define KING_MCP_REMOTE_OP_DOWNLOAD "GET"
#define KING_MCP_TRANSFER_STATE_VERSION 1

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

static int king_mcp_build_transfer_state_lock_path(
    const char *state_path,
    char *lock_path,
    size_t lock_path_len
)
{
    if (
        state_path == NULL
        || state_path[0] == '\0'
        || lock_path == NULL
        || lock_path_len == 0
    ) {
        return FAILURE;
    }

    if (snprintf(lock_path, lock_path_len, "%s.lock", state_path) >= (int) lock_path_len) {
        return FAILURE;
    }

    if (php_check_open_basedir(lock_path) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_mcp_transfer_state_lock_acquire(
    king_mcp_state *state,
    int *lock_fd_out
)
{
    char lock_path[1024];
    const char *state_path = king_mcp_transfer_state_path();
    int flags = O_RDWR | O_CREAT;
    int fd;

    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_mcp_build_transfer_state_lock_path(state_path, lock_path, sizeof(lock_path)) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not build the local transfer state lock path."
        );
        return FAILURE;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(lock_path, flags, 0600);
    if (fd < 0) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not open the local transfer state lock file."
        );
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not secure the local transfer state lock file."
        );
        return FAILURE;
    }

    if (flock(fd, LOCK_EX) != 0) {
        close(fd);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not acquire the local transfer state lock."
        );
        return FAILURE;
    }

    *lock_fd_out = fd;
    return SUCCESS;
}

static void king_mcp_transfer_state_lock_release(int lock_fd)
{
    if (lock_fd < 0) {
        return;
    }

    (void) flock(lock_fd, LOCK_UN);
    close(lock_fd);
}

static FILE *king_mcp_open_nofollow_stream(
    const char *path,
    int flags,
    mode_t mode,
    const char *stream_mode
)
{
    int fd;
    FILE *stream;

    if (path == NULL || stream_mode == NULL) {
        return NULL;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(path, flags, mode);
    if (fd < 0) {
        return NULL;
    }

    stream = fdopen(fd, stream_mode);
    if (stream == NULL) {
        close(fd);
        return NULL;
    }

    return stream;
}

static int king_mcp_transfer_state_path_validate_existing(const char *state_path)
{
    struct stat st;

    if (state_path == NULL || state_path[0] == '\0') {
        return FAILURE;
    }

    if (php_check_open_basedir(state_path) != 0) {
        return FAILURE;
    }

    if (lstat(state_path, &st) != 0) {
        return errno == ENOENT ? SUCCESS : FAILURE;
    }

    return S_ISREG(st.st_mode) ? SUCCESS : FAILURE;
}

static int king_mcp_build_transfer_state_tmp_template(
    const char *state_path,
    char *tmp_path,
    size_t tmp_path_len
)
{
    if (
        state_path == NULL
        || state_path[0] == '\0'
        || tmp_path == NULL
        || tmp_path_len == 0
    ) {
        return FAILURE;
    }

    if (snprintf(tmp_path, tmp_path_len, "%s.tmp.XXXXXX", state_path) >= (int) tmp_path_len) {
        return FAILURE;
    }

    if (php_check_open_basedir(tmp_path) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_string *king_mcp_transfer_state_decode_payload(
    king_mcp_state *state,
    const char *encoded,
    size_t encoded_len
)
{
    zend_string *decoded = php_base64_decode((const unsigned char *) encoded, encoded_len);

    if (decoded == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime encountered invalid persisted transfer payload data."
        );
        return NULL;
    }

    return decoded;
}

static zend_result king_mcp_transfer_state_transaction_begin(
    king_mcp_state *state,
    HashTable *transfers,
    int *lock_fd_out
)
{
    const char *state_path = king_mcp_transfer_state_path();
    FILE *stream = NULL;
    char *line = NULL;
    size_t line_cap = 0;
    ssize_t line_len;

    if (transfers == NULL || lock_fd_out == NULL) {
        return FAILURE;
    }

    zend_hash_init(transfers, 8, NULL, ZVAL_PTR_DTOR, 0);
    *lock_fd_out = -1;

    if (king_mcp_transfer_state_lock_acquire(state, lock_fd_out) != SUCCESS) {
        zend_hash_destroy(transfers);
        return FAILURE;
    }

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime refused the configured local transfer state path."
        );
        goto failure;
    }

    stream = king_mcp_open_nofollow_stream(state_path, O_RDONLY, 0, "rb");
    if (stream == NULL) {
        if (errno == ENOENT) {
            return SUCCESS;
        }

        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not open the configured local transfer state file."
        );
        goto failure;
    }

    while ((line_len = getline(&line, &line_cap, stream)) != -1) {
        char *saveptr = NULL;
        char *kind;
        char *key;
        char *payload_b64;
        zval payload_zv;
        zend_string *payload;

        while (line_len > 0 && (line[line_len - 1] == '\n' || line[line_len - 1] == '\r')) {
            line[--line_len] = '\0';
        }

        if (line_len == 0 || line[0] == '#') {
            continue;
        }

        kind = strtok_r(line, "\t", &saveptr);
        if (kind == NULL) {
            continue;
        }

        if (strcmp(kind, "version") == 0) {
            char *version_text = strtok_r(NULL, "\t", &saveptr);
            zend_long version;

            if (version_text == NULL) {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_BACKEND,
                    "MCP runtime encountered a malformed local transfer state header."
                );
                goto failure;
            }

            version = ZEND_STRTOL(version_text, NULL, 10);
            if (version != KING_MCP_TRANSFER_STATE_VERSION) {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_BACKEND,
                    "MCP runtime encountered an unsupported local transfer state version."
                );
                goto failure;
            }
            continue;
        }

        if (strcmp(kind, "transfer") != 0) {
            continue;
        }

        key = strtok_r(NULL, "\t", &saveptr);
        payload_b64 = strtok_r(NULL, "\t", &saveptr);
        if (key == NULL || payload_b64 == NULL || key[0] == '\0') {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime encountered a malformed persisted transfer entry."
            );
            goto failure;
        }

        payload = king_mcp_transfer_state_decode_payload(state, payload_b64, strlen(payload_b64));
        if (payload == NULL) {
            goto failure;
        }

        ZVAL_STR(&payload_zv, payload);
        zend_hash_str_update(transfers, key, strlen(key), &payload_zv);
    }

    if (stream != NULL) {
        fclose(stream);
    }
    if (line != NULL) {
        free(line);
    }

    return SUCCESS;

failure:
    if (stream != NULL) {
        fclose(stream);
    }
    if (line != NULL) {
        free(line);
    }
    zend_hash_destroy(transfers);
    king_mcp_transfer_state_lock_release(*lock_fd_out);
    *lock_fd_out = -1;
    return FAILURE;
}

static void king_mcp_transfer_state_transaction_end(HashTable *transfers, int lock_fd)
{
    if (transfers != NULL) {
        zend_hash_destroy(transfers);
    }

    king_mcp_transfer_state_lock_release(lock_fd);
}

static zend_result king_mcp_transfer_state_persist_locked(
    king_mcp_state *state,
    HashTable *transfers
)
{
    const char *state_path = king_mcp_transfer_state_path();
    char tmp_path[1024];
    FILE *stream = NULL;
    zend_string *key;
    zval *payload_zv;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (transfers == NULL) {
        return FAILURE;
    }

    if (zend_hash_num_elements(transfers) == 0) {
        if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime refused the configured local transfer state path."
            );
            return FAILURE;
        }

        if (unlink(state_path) != 0 && errno != ENOENT) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not clean up the local transfer state file."
            );
            return FAILURE;
        }

        return SUCCESS;
    }

    if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime refused the configured local transfer state path."
        );
        return FAILURE;
    }

    if (king_mcp_build_transfer_state_tmp_template(state_path, tmp_path, sizeof(tmp_path)) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not build the local transfer state staging path."
        );
        return FAILURE;
    }

    {
        int fd = mkstemp(tmp_path);
        if (fd < 0) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not create the local transfer state staging file."
            );
            return FAILURE;
        }
        if (fchmod(fd, 0600) != 0) {
            close(fd);
            unlink(tmp_path);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not secure the local transfer state staging file."
            );
            return FAILURE;
        }
        stream = fdopen(fd, "wb");
        if (stream == NULL) {
            close(fd);
            unlink(tmp_path);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not open the local transfer state staging stream."
            );
            return FAILURE;
        }
    }

    fprintf(stream, "version\t%d\n", KING_MCP_TRANSFER_STATE_VERSION);

    ZEND_HASH_FOREACH_STR_KEY_VAL(transfers, key, payload_zv) {
        zend_string *encoded_payload;

        if (key == NULL || Z_TYPE_P(payload_zv) != IS_STRING) {
            continue;
        }

        encoded_payload = php_base64_encode(
            (const unsigned char *) Z_STRVAL_P(payload_zv),
            Z_STRLEN_P(payload_zv)
        );
        if (encoded_payload == NULL) {
            fclose(stream);
            unlink(tmp_path);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not encode the persisted transfer payload."
            );
            return FAILURE;
        }

        fprintf(stream, "transfer\t%s\t%s\n", ZSTR_VAL(key), ZSTR_VAL(encoded_payload));
        zend_string_release(encoded_payload);
    } ZEND_HASH_FOREACH_END();

    if (fclose(stream) != 0) {
        unlink(tmp_path);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not flush the local transfer state staging file."
        );
        return FAILURE;
    }

    if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
        unlink(tmp_path);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime refused the configured local transfer state path."
        );
        return FAILURE;
    }

    if (rename(tmp_path, state_path) != 0) {
        unlink(tmp_path);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not publish the local transfer state snapshot."
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_mcp_transfer_state_store_payload(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    zend_string *payload
)
{
    HashTable transfers;
    int lock_fd = -1;
    zend_string *key;
    zval payload_zv;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (payload == NULL) {
        return FAILURE;
    }

    key = king_mcp_transfer_key_create(service, service_len, method, method_len, id, id_len);
    if (key == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not materialize the persisted transfer key."
        );
        return FAILURE;
    }

    if (king_mcp_transfer_state_transaction_begin(state, &transfers, &lock_fd) != SUCCESS) {
        zend_string_release(key);
        return FAILURE;
    }

    ZVAL_STR_COPY(&payload_zv, payload);
    zend_hash_update(&transfers, key, &payload_zv);
    zend_string_release(key);

    if (king_mcp_transfer_state_persist_locked(state, &transfers) != SUCCESS) {
        king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
        return FAILURE;
    }

    king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
    return SUCCESS;
}

static zend_string *king_mcp_transfer_state_find_payload(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len
)
{
    HashTable transfers;
    int lock_fd = -1;
    zend_string *key;
    zend_string *payload = NULL;
    zval *payload_zv;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return NULL;
    }

    key = king_mcp_transfer_key_create(service, service_len, method, method_len, id, id_len);
    if (key == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not materialize the persisted transfer key."
        );
        return NULL;
    }

    if (king_mcp_transfer_state_transaction_begin(state, &transfers, &lock_fd) != SUCCESS) {
        zend_string_release(key);
        return NULL;
    }

    payload_zv = zend_hash_find(&transfers, key);
    if (payload_zv != NULL && Z_TYPE_P(payload_zv) == IS_STRING) {
        payload = zend_string_copy(Z_STR_P(payload_zv));
    }

    king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
    zend_string_release(key);

    return payload;
}

static zend_result king_mcp_transfer_state_delete_payload(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len
)
{
    HashTable transfers;
    int lock_fd = -1;
    zend_string *key;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    key = king_mcp_transfer_key_create(service, service_len, method, method_len, id, id_len);
    if (key == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not materialize the persisted transfer key."
        );
        return FAILURE;
    }

    if (king_mcp_transfer_state_transaction_begin(state, &transfers, &lock_fd) != SUCCESS) {
        zend_string_release(key);
        return FAILURE;
    }

    (void) zend_hash_del(&transfers, key);
    zend_string_release(key);

    if (king_mcp_transfer_state_persist_locked(state, &transfers) != SUCCESS) {
        king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
        return FAILURE;
    }

    king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
    return SUCCESS;
}

static void king_mcp_mark_transport_closed(king_mcp_state *state)
{
    if (state == NULL || state->transport_stream == NULL) {
        return;
    }

    php_stream_close(state->transport_stream);
    state->transport_stream = NULL;
}

static zend_result king_mcp_begin_operation(
    king_mcp_state *state,
    const char *operation_name
)
{
    if (state == NULL || state->closed) {
        return FAILURE;
    }

    if (state->operation_active) {
        king_mcp_set_errorf(
            "%s cannot start while another MCP operation is already active on this connection.",
            operation_name
        );
        return FAILURE;
    }

    king_mcp_state_clear_error_kind(state);
    state->operation_active = true;
    return SUCCESS;
}

static void king_mcp_end_operation(king_mcp_state *state)
{
    if (state != NULL) {
        state->operation_active = false;
    }
}

static zend_string *king_mcp_build_transport_target(king_mcp_state *state)
{
    const char *host;
    size_t host_len;
    bool needs_brackets;

    if (state == NULL || state->host == NULL) {
        return NULL;
    }

    host = ZSTR_VAL(state->host);
    host_len = ZSTR_LEN(state->host);
    needs_brackets =
        memchr(host, ':', host_len) != NULL
        && !(host_len >= 2 && host[0] == '[' && host[host_len - 1] == ']');

    if (needs_brackets) {
        return strpprintf(0, "tcp://[%s]:%ld", host, state->port);
    }

    return strpprintf(0, "tcp://%s:%ld", host, state->port);
}

static zend_long king_mcp_default_transport_timeout_ms(void)
{
    if (king_mcp_orchestrator_config.mcp_default_request_timeout_ms > 0) {
        return king_mcp_orchestrator_config.mcp_default_request_timeout_ms;
    }

    return 30000;
}

static size_t king_mcp_remote_line_limit(void)
{
    size_t max_payload_bytes = 4194304;

    if (king_mcp_orchestrator_config.mcp_max_message_size_bytes > 0) {
        max_payload_bytes = (size_t) king_mcp_orchestrator_config.mcp_max_message_size_bytes;
    }

    if (max_payload_bytes > (((size_t) -1) / 2)) {
        max_payload_bytes = ((size_t) -1) / 2;
    }

    return (max_payload_bytes * 2) + KING_MCP_REMOTE_LINE_OVERHEAD;
}

static uint64_t king_mcp_monotonic_time_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return ((uint64_t) ts.tv_sec * 1000ULL) + ((uint64_t) ts.tv_nsec / 1000000ULL);
}

static zend_long king_mcp_control_timeout_budget_ms(const king_mcp_runtime_control_t *control)
{
    uint64_t elapsed_ms;
    uint64_t remaining_ms;

    if (control == NULL || control->timeout_ms <= 0) {
        return 0;
    }

    elapsed_ms = king_mcp_monotonic_time_ms() - control->started_at_ms;
    if (elapsed_ms >= (uint64_t) control->timeout_ms) {
        return 0;
    }

    remaining_ms = (uint64_t) control->timeout_ms - elapsed_ms;
    if (remaining_ms > (uint64_t) ZEND_LONG_MAX) {
        remaining_ms = (uint64_t) ZEND_LONG_MAX;
    }

    return (zend_long) remaining_ms;
}

static zend_long king_mcp_control_deadline_budget_ms(const king_mcp_runtime_control_t *control)
{
    uint64_t now_ms;
    uint64_t remaining_ms;

    if (control == NULL || control->deadline_ms == 0) {
        return 0;
    }

    now_ms = king_mcp_monotonic_time_ms();
    if (now_ms >= control->deadline_ms) {
        return 0;
    }

    remaining_ms = control->deadline_ms - now_ms;
    if (remaining_ms > (uint64_t) ZEND_LONG_MAX) {
        remaining_ms = (uint64_t) ZEND_LONG_MAX;
    }

    return (zend_long) remaining_ms;
}

static zend_long king_mcp_control_effective_budget_ms(const king_mcp_runtime_control_t *control)
{
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;

    if (control == NULL) {
        return king_mcp_default_transport_timeout_ms();
    }

    if (king_transport_cancel_token_is_cancelled(control->cancel_token)) {
        return 0;
    }

    timeout_budget_ms = king_mcp_control_timeout_budget_ms(control);
    deadline_budget_ms = king_mcp_control_deadline_budget_ms(control);

    if (timeout_budget_ms > 0 && deadline_budget_ms > 0) {
        return timeout_budget_ms < deadline_budget_ms
            ? timeout_budget_ms
            : deadline_budget_ms;
    }

    if (timeout_budget_ms > 0) {
        return timeout_budget_ms;
    }

    if (deadline_budget_ms > 0) {
        return deadline_budget_ms;
    }

    return king_mcp_default_transport_timeout_ms();
}

static zend_long king_mcp_control_transport_budget_ms(const king_mcp_runtime_control_t *control)
{
    zend_long budget_ms = king_mcp_control_effective_budget_ms(control);

    if (control == NULL) {
        return budget_ms;
    }

    if (king_transport_cancel_token_is_cancelled(control->cancel_token)) {
        return 0;
    }

    if (budget_ms <= 0) {
        if (control->timeout_ms > 0 || control->deadline_ms > 0) {
            return KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }
        return 0;
    }

    if (control->timeout_ms > 0 || control->deadline_ms > 0) {
        if (budget_ms <= ZEND_LONG_MAX - KING_TRANSPORT_INTERRUPT_SLICE_MS) {
            budget_ms += KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }
    }

    return budget_ms;
}

static zend_result king_mcp_prepare_stream_transport(
    king_mcp_state *state,
    zend_long timeout_ms
)
{
    struct timeval timeout;
    php_socket_t socketd = -1;

    if (state == NULL || state->transport_stream == NULL) {
        return FAILURE;
    }

    if (timeout_ms <= 0) {
        timeout_ms = king_mcp_default_transport_timeout_ms();
    }

    timeout.tv_sec = timeout_ms / 1000;
    timeout.tv_usec = (timeout_ms % 1000) * 1000;

    php_stream_set_option(state->transport_stream, PHP_STREAM_OPTION_BLOCKING, 0, NULL);
    php_stream_set_option(
        state->transport_stream,
        PHP_STREAM_OPTION_READ_TIMEOUT,
        0,
        &timeout
    );

    if (php_stream_cast(
            state->transport_stream,
            PHP_STREAM_AS_SOCKETD,
            (void *) &socketd,
            1
        ) == SUCCESS) {
        php_set_sock_blocking(socketd, false);
    }

    return SUCCESS;
}

static zend_result king_mcp_remote_wait_socket(
    king_mcp_state *state,
    int events,
    king_mcp_runtime_control_t *control,
    const char *operation_name,
    const char *phase
)
{
    php_socket_t socketd = -1;
    zend_long timeout_ms;
    uint64_t deadline_ms;

    if (state == NULL || state->transport_stream == NULL) {
        return FAILURE;
    }

    if (php_stream_cast(
            state->transport_stream,
            PHP_STREAM_AS_SOCKETD,
            (void *) &socketd,
            1
        ) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_TRANSPORT,
            "%s could not access the active remote MCP transport socket.",
            operation_name
        );
        return FAILURE;
    }

    timeout_ms = king_mcp_control_transport_budget_ms(control);
    if (timeout_ms <= 0) {
        king_mcp_mark_transport_closed(state);
        return FAILURE;
    }

    deadline_ms = king_mcp_monotonic_time_ms() + (uint64_t) timeout_ms;

    for (;;) {
        uint64_t now_ms;
        zend_long remaining_ms;
        zend_long wait_timeout_ms;
        int poll_result;

        king_process_pending_interrupts();

        if (control != NULL && king_transport_cancel_token_is_cancelled(control->cancel_token)) {
            king_mcp_mark_transport_closed(state);
            return FAILURE;
        }

        now_ms = king_mcp_monotonic_time_ms();
        if (now_ms >= deadline_ms) {
            king_mcp_mark_transport_closed(state);
            if (king_get_error()[0] == '\0') {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_TRANSPORT,
                    "%s timed out while waiting on the remote MCP peer socket during the %s phase.",
                    operation_name,
                    phase
                );
            }
            return FAILURE;
        }

        remaining_ms = (zend_long) (deadline_ms - now_ms);
        wait_timeout_ms = remaining_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS
            ? KING_TRANSPORT_INTERRUPT_SLICE_MS
            : remaining_ms;

        poll_result = php_pollfd_for_ms(socketd, events, (int) wait_timeout_ms);
        if (poll_result == 0) {
            continue;
        }

        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }

            king_mcp_mark_transport_closed(state);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s failed while waiting on the remote MCP peer socket during the %s phase (errno %d).",
                operation_name,
                phase,
                errno
            );
            return FAILURE;
        }

        if ((poll_result & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            king_mcp_mark_transport_closed(state);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s detected a remote MCP peer socket error during the %s phase.",
                operation_name,
                phase
            );
            return FAILURE;
        }

        if ((poll_result & events) == 0) {
            king_mcp_mark_transport_closed(state);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s did not observe the expected remote MCP socket readiness during the %s phase.",
                operation_name,
                phase
            );
            return FAILURE;
        }

        return SUCCESS;
    }
}

static zend_result king_mcp_remote_connect(
    king_mcp_state *state,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    zend_string *target = NULL;
    zend_string *transport_error = NULL;
    php_stream *stream = NULL;
    struct timeval timeout;
    int transport_error_code = 0;
    zend_long timeout_ms;

    if (state == NULL || state->closed) {
        return FAILURE;
    }

    if (state->transport_stream != NULL) {
        if (!php_stream_eof(state->transport_stream)) {
            return SUCCESS;
        }

        king_mcp_mark_transport_closed(state);
    }

    target = king_mcp_build_transport_target(state);
    if (target == NULL) {
        king_mcp_state_set_error_message(
            state,
            KING_MCP_ERROR_TRANSPORT,
            "MCP runtime could not build the remote transport target."
        );
        return FAILURE;
    }

    timeout_ms = king_mcp_control_effective_budget_ms(control);
    if (timeout_ms <= 0) {
        king_set_error("");
        zend_string_release(target);
        return FAILURE;
    }
    timeout.tv_sec = timeout_ms / 1000;
    timeout.tv_usec = (timeout_ms % 1000) * 1000;

    stream = php_stream_xport_create(
        ZSTR_VAL(target),
        ZSTR_LEN(target),
        0,
        STREAM_XPORT_CLIENT | STREAM_XPORT_CONNECT,
        NULL,
        &timeout,
        NULL,
        &transport_error,
        &transport_error_code
    );
    zend_string_release(target);

    if (stream == NULL) {
        if (transport_error != NULL) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s failed to connect to the remote MCP peer: %s",
                operation_name,
                ZSTR_VAL(transport_error)
            );
            zend_string_release(transport_error);
        } else {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s failed to connect to the remote MCP peer (code %d).",
                operation_name,
                transport_error_code
            );
        }
        return FAILURE;
    }

    state->transport_stream = stream;
    if (king_mcp_prepare_stream_transport(state, timeout_ms) != SUCCESS) {
        king_mcp_mark_transport_closed(state);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_TRANSPORT,
            "%s could not configure the active MCP transport socket.",
            operation_name
        );
        return FAILURE;
    }
    king_set_error("");
    return SUCCESS;
}

static zend_result king_mcp_remote_write_all(
    king_mcp_state *state,
    const char *buffer,
    size_t buffer_len,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    size_t written = 0;

    while (written < buffer_len) {
        int chunk;

        if (king_mcp_remote_wait_socket(
                state,
                POLLOUT,
                control,
                operation_name,
                "write"
            ) != SUCCESS) {
            return FAILURE;
        }

        chunk = php_stream_xport_sendto(
            state->transport_stream,
            buffer + written,
            buffer_len - written,
            0,
            NULL,
            0
        );

        if (chunk <= 0) {
            king_mcp_mark_transport_closed(state);
            if (king_get_error()[0] == '\0') {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_TRANSPORT,
                    "%s failed while writing the remote MCP command to the active peer socket.",
                    operation_name
                );
            }
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_string *king_mcp_remote_read_line(
    king_mcp_state *state,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    char *buffer;
    size_t buffer_len;
    size_t used = 0;
    zend_string *result;

    buffer_len = king_mcp_remote_line_limit();
    buffer = emalloc(buffer_len);
    buffer[0] = '\0';

    while (used + 1 < buffer_len) {
        char *newline;
        int chunk;
        size_t line_len;

        if (king_mcp_remote_wait_socket(
                state,
                POLLIN,
                control,
                operation_name,
                "read"
            ) != SUCCESS) {
            efree(buffer);
            return NULL;
        }

        chunk = php_stream_xport_recvfrom(
            state->transport_stream,
            buffer + used,
            (buffer_len - used) - 1,
            0,
            NULL,
            NULL,
            NULL
        );
        if (chunk <= 0) {
            efree(buffer);
            king_mcp_mark_transport_closed(state);
            if (king_get_error()[0] == '\0') {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_TRANSPORT,
                    "%s did not receive a complete response line from the remote MCP peer.",
                    operation_name
                );
            }
            return NULL;
        }

        used += (size_t) chunk;
        buffer[used] = '\0';
        newline = memchr(buffer, '\n', used);
        if (newline == NULL) {
            continue;
        }

        line_len = (size_t) (newline - buffer);
        if (line_len > 0 && buffer[line_len - 1] == '\r') {
            line_len--;
        }

        result = zend_string_init(buffer, line_len, 0);
        efree(buffer);
        return result;
    }

    efree(buffer);
    king_mcp_mark_transport_closed(state);
    king_mcp_state_set_errorf(
        state,
        KING_MCP_ERROR_TRANSPORT,
        "%s received an oversized response line from the remote MCP peer.",
        operation_name
    );
    return NULL;
}

static zend_string *king_mcp_base64_decode_field(
    king_mcp_state *state,
    const char *encoded,
    size_t encoded_len,
    const char *operation_name
)
{
    zend_string *decoded;

    decoded = php_base64_decode(
        (const unsigned char *) encoded,
        encoded_len
    );
    if (decoded == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_PROTOCOL,
            "%s received invalid base64 payload data from the remote MCP peer.",
            operation_name
        );
        return NULL;
    }

    return decoded;
}

static zend_result king_mcp_remote_send_command(
    king_mcp_state *state,
    const char *operation_name,
    const char *opcode,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *identifier,
    size_t identifier_len,
    zend_string *payload,
    king_mcp_runtime_control_t *control
)
{
    zend_string *encoded_service = NULL;
    zend_string *encoded_method = NULL;
    zend_string *encoded_identifier = NULL;
    zend_string *encoded_payload = NULL;
    smart_str command = {0};
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;
    zend_result status = FAILURE;

    encoded_service = php_base64_encode(
        (const unsigned char *) service,
        service_len
    );
    encoded_method = php_base64_encode(
        (const unsigned char *) method,
        method_len
    );
    if (identifier != NULL) {
        encoded_identifier = php_base64_encode(
            (const unsigned char *) identifier,
            identifier_len
        );
    }
    if (payload != NULL) {
        encoded_payload = php_base64_encode(
            (const unsigned char *) ZSTR_VAL(payload),
            ZSTR_LEN(payload)
        );
    }

    if (encoded_service == NULL || encoded_method == NULL
        || (identifier != NULL && encoded_identifier == NULL)
        || (payload != NULL && encoded_payload == NULL)) {
        king_mcp_set_errorf("%s failed while encoding the remote MCP command.", operation_name);
        goto cleanup;
    }

    smart_str_appends(&command, opcode);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_service);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_method);

    if (encoded_identifier != NULL) {
        smart_str_appendc(&command, '\t');
        smart_str_append(&command, encoded_identifier);
    }

    if (encoded_payload != NULL) {
        smart_str_appendc(&command, '\t');
        smart_str_append(&command, encoded_payload);
    }

    timeout_budget_ms = king_mcp_control_timeout_budget_ms(control);
    deadline_budget_ms = king_mcp_control_deadline_budget_ms(control);
    smart_str_append_printf(&command, "\t%ld\t%ld", timeout_budget_ms, deadline_budget_ms);
    smart_str_appendc(&command, '\n');
    smart_str_0(&command);

    if (command.s == NULL) {
        king_mcp_set_errorf("%s failed while materializing the remote MCP command.", operation_name);
        goto cleanup;
    }

    status = king_mcp_remote_write_all(
        state,
        ZSTR_VAL(command.s),
        ZSTR_LEN(command.s),
        operation_name,
        control
    );

cleanup:
    if (encoded_service != NULL) {
        zend_string_release(encoded_service);
    }
    if (encoded_method != NULL) {
        zend_string_release(encoded_method);
    }
    if (encoded_identifier != NULL) {
        zend_string_release(encoded_identifier);
    }
    if (encoded_payload != NULL) {
        zend_string_release(encoded_payload);
    }
    smart_str_free(&command);

    return status;
}

static zend_result king_mcp_remote_expect_ok(
    king_mcp_state *state,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    zend_string *line;
    char *tab;
    zend_string *decoded_error;

    line = king_mcp_remote_read_line(state, operation_name, control);
    if (line == NULL) {
        return FAILURE;
    }

    if (zend_string_equals_literal(line, "OK")) {
        zend_string_release(line);
        return SUCCESS;
    }

    tab = strchr(ZSTR_VAL(line), '\t');
    if (tab != NULL) {
        *tab = '\0';
        if (strcmp(ZSTR_VAL(line), "ERR") == 0) {
            decoded_error = king_mcp_base64_decode_field(
                state,
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded_error == NULL) {
                return FAILURE;
            }

            king_mcp_state_set_error_message(
                state,
                KING_MCP_ERROR_PROTOCOL,
                ZSTR_VAL(decoded_error)
            );
            zend_string_release(decoded_error);
            return FAILURE;
        }
    }

    king_mcp_state_set_errorf(
        state,
        KING_MCP_ERROR_PROTOCOL,
        "%s received an invalid acknowledgement from the remote MCP peer.",
        operation_name
    );
    zend_string_release(line);
    return FAILURE;
}

static zend_result king_mcp_remote_expect_payload(
    king_mcp_state *state,
    const char *operation_name,
    zend_string **payload_out,
    bool *missing_out,
    king_mcp_runtime_control_t *control
)
{
    zend_string *line;
    char *tab;
    zend_string *decoded;

    if (payload_out == NULL) {
        return FAILURE;
    }

    *payload_out = NULL;
    if (missing_out != NULL) {
        *missing_out = false;
    }

    line = king_mcp_remote_read_line(state, operation_name, control);
    if (line == NULL) {
        return FAILURE;
    }

    if (zend_string_equals_literal(line, "MISS")) {
        if (missing_out != NULL) {
            *missing_out = true;
            zend_string_release(line);
            king_set_error("");
            return SUCCESS;
        }

        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_PROTOCOL,
            "%s received an unexpected missing-payload response from the remote MCP peer.",
            operation_name
        );
        zend_string_release(line);
        return FAILURE;
    }

    tab = strchr(ZSTR_VAL(line), '\t');
    if (tab != NULL) {
        *tab = '\0';
        if (strcmp(ZSTR_VAL(line), "OK") == 0) {
            decoded = king_mcp_base64_decode_field(
                state,
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded == NULL) {
                return FAILURE;
            }

            *payload_out = decoded;
            king_set_error("");
            return SUCCESS;
        }

        if (strcmp(ZSTR_VAL(line), "ERR") == 0) {
            decoded = king_mcp_base64_decode_field(
                state,
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded == NULL) {
                return FAILURE;
            }

            king_mcp_state_set_error_message(
                state,
                KING_MCP_ERROR_PROTOCOL,
                ZSTR_VAL(decoded)
            );
            zend_string_release(decoded);
            return FAILURE;
        }
    }

    king_mcp_state_set_errorf(
        state,
        KING_MCP_ERROR_PROTOCOL,
        "%s received an invalid payload response from the remote MCP peer.",
        operation_name
    );
    zend_string_release(line);
    return FAILURE;
}

king_mcp_state *king_mcp_state_create(
    const char *host,
    size_t host_len,
    zend_long port,
    zval *config)
{
    zend_string *normalized_host = NULL;
    king_mcp_state *state = ecalloc(1, sizeof(*state));

    if (king_mcp_validate_peer_target(host, host_len, port, &normalized_host) != SUCCESS) {
        efree(state);
        return NULL;
    }

    state->host = normalized_host;
    state->port = port;
    ZVAL_UNDEF(&state->config);
    state->transport_stream = NULL;
    if (config != NULL && Z_TYPE_P(config) != IS_NULL) {
        ZVAL_COPY(&state->config, config);
    }
    state->closed = false;
    state->operation_active = false;
    state->last_error_kind = KING_MCP_ERROR_NONE;

    return state;
}

void king_mcp_state_close(king_mcp_state *state)
{
    if (state == NULL) {
        return;
    }

    state->closed = true;
    state->operation_active = false;
    state->last_error_kind = KING_MCP_ERROR_NONE;
    king_mcp_mark_transport_closed(state);
}

void king_mcp_state_free(king_mcp_state *state)
{
    if (state == NULL) {
        return;
    }

    if (state->host != NULL) {
        zend_string_release(state->host);
    }
    zval_ptr_dtor(&state->config);
    king_mcp_state_close(state);
    efree(state);
}

int king_mcp_transfer_store(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    zend_string *payload,
    king_mcp_runtime_control_t *control)
{
    int status = FAILURE;

    if (!state || state->closed || payload == NULL) {
        return FAILURE;
    }

    if (king_mcp_begin_operation(state, "MCP upload") != SUCCESS) {
        return FAILURE;
    }

    if (king_mcp_remote_connect(state, "MCP upload", control) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_send_command(
            state,
            "MCP upload",
            KING_MCP_REMOTE_OP_UPLOAD,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len,
            payload,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

    status = king_mcp_remote_expect_ok(state, "MCP upload", control);
    if (
        status == SUCCESS
        && king_mcp_transfer_state_store_payload(
            state,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len,
            payload
        ) != SUCCESS
    ) {
        status = FAILURE;
    }

cleanup:
    king_mcp_end_operation(state);
    return status;
}

zend_string *king_mcp_transfer_find(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    king_mcp_runtime_control_t *control)
{
    zend_string *payload = NULL;
    bool missing = false;

    if (!state || state->closed) {
        return NULL;
    }

    if (king_mcp_begin_operation(state, "MCP download") != SUCCESS) {
        return NULL;
    }

    if (king_mcp_remote_connect(state, "MCP download", control) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_send_command(
            state,
            "MCP download",
            KING_MCP_REMOTE_OP_DOWNLOAD,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len,
            NULL,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_expect_payload(
            state,
            "MCP download",
            &payload,
            &missing,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

cleanup:
    king_mcp_end_operation(state);
    if (missing) {
        if (payload != NULL) {
            zend_string_release(payload);
        }
        return king_mcp_transfer_state_find_payload(
            state,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len
        );
    }

    return payload;
}

int king_mcp_transfer_acknowledge(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len)
{
    if (state == NULL || state->closed) {
        return FAILURE;
    }

    return king_mcp_transfer_state_delete_payload(
        state,
        service,
        service_len,
        method,
        method_len,
        id,
        id_len
    );
}

int king_mcp_request(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    zend_string *payload,
    zend_string **response_out,
    king_mcp_runtime_control_t *control)
{
    int status = FAILURE;

    if (!state || state->closed || payload == NULL || response_out == NULL) {
        return FAILURE;
    }

    *response_out = NULL;

    if (king_mcp_begin_operation(state, "MCP request") != SUCCESS) {
        return FAILURE;
    }

    if (king_mcp_remote_connect(state, "MCP request", control) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_send_command(
            state,
            "MCP request",
            KING_MCP_REMOTE_OP_REQUEST,
            service,
            service_len,
            method,
            method_len,
            NULL,
            0,
            payload,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

    status = king_mcp_remote_expect_payload(
        state,
        "MCP request",
        response_out,
        NULL,
        control
    );
    if (status == SUCCESS && *response_out == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_PROTOCOL,
            "MCP request completed without a response payload from the remote MCP peer."
        );
        status = FAILURE;
    }

cleanup:
    king_mcp_end_operation(state);
    return status;
}
