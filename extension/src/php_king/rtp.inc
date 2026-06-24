/*
 * King\RTP\Socket userland surface. This is a resource-backed OO facade over
 * the same native RTP/ICE-lite/DTLS-SRTP socket runtime used by king_rtp_*.
 */

#include "rtp.h"

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_RTP_Socket___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_RTP_Socket_iceCredentials, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_RTP_Socket_dtlsFingerprint, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_RTP_Socket_acceptDtls, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, ip, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "5000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_RTP_Socket_receive, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_RTP_Socket_send, 0, 3, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_RTP_Socket_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_RTP_Socket_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

static king_rtp_socket_t *king_rtp_socket_object_fetch(
    zval *object,
    const char *function_name
)
{
    king_rtp_socket_object *intern;
    king_rtp_socket_t *socket;

    if (object == NULL || Z_TYPE_P(object) != IS_OBJECT) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() requires a live King\\RTP\\Socket object.",
            function_name
        );
        return NULL;
    }

    intern = php_king_rtp_socket_obj_from_zend(Z_OBJ_P(object));
    if (intern->closed || Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() cannot use a closed King\\RTP\\Socket.",
            function_name
        );
        return NULL;
    }

    socket = (king_rtp_socket_t *) zend_fetch_resource(
        Z_RES(intern->resource),
        "King\\RtpSocket",
        le_king_rtp_socket
    );
    if (socket == NULL) {
        if (!EG(exception)) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s() could not fetch the native RTP socket resource.",
                function_name
            );
        }
        return NULL;
    }

    return socket;
}

static void king_rtp_receive_result_to_array(
    zval *return_value,
    const king_rtp_receive_result_t *packet
)
{
    array_init(return_value);
    add_assoc_long(return_value, "ssrc", (zend_long) packet->ssrc);
    add_assoc_long(return_value, "payload_type", packet->payload_type);
    add_assoc_long(return_value, "seq", packet->seq);
    add_assoc_long(return_value, "timestamp", (zend_long) packet->timestamp);
    add_assoc_bool(return_value, "marker", packet->marker);
    add_assoc_stringl(return_value, "data", (char *) packet->data, packet->data_len);
    add_assoc_string(return_value, "from_ip", packet->from_ip);
    add_assoc_long(return_value, "from_port", packet->from_port);
    add_assoc_bool(return_value, "ice_consented", packet->ice_consented);
    add_assoc_bool(return_value, "srtp", packet->srtp);
}

PHP_METHOD(King_RTP_Socket, __construct)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    char errbuf[256] = {0};
    king_rtp_socket_t *socket;
    king_rtp_socket_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
    ZEND_PARSE_PARAMETERS_END();
    (void) host_len;

    socket = king_rtp_socket_create(host, (int) port, errbuf, sizeof(errbuf));
    if (socket == NULL) {
        king_set_error(errbuf);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King\\RTP\\Socket could not bind %s:%" ZEND_LONG_FMT ": %s",
            host,
            port,
            errbuf[0] != '\0' ? errbuf : "unknown RTP bind failure"
        );
        RETURN_THROWS();
    }

    intern = php_king_rtp_socket_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(socket, le_king_rtp_socket));
    intern->closed = false;
    king_set_error("");
}

PHP_METHOD(King_RTP_Socket, iceCredentials)
{
    king_rtp_socket_t *socket;

    ZEND_PARSE_PARAMETERS_NONE();

    socket = king_rtp_socket_object_fetch(ZEND_THIS, "King\\RTP\\Socket::iceCredentials");
    if (socket == NULL) {
        RETURN_THROWS();
    }

    array_init(return_value);
    add_assoc_string(return_value, "ufrag", socket->ufrag);
    add_assoc_string(return_value, "pwd", socket->pwd);
}

PHP_METHOD(King_RTP_Socket, dtlsFingerprint)
{
    king_rtp_socket_t *socket;

    ZEND_PARSE_PARAMETERS_NONE();

    socket = king_rtp_socket_object_fetch(ZEND_THIS, "King\\RTP\\Socket::dtlsFingerprint");
    if (socket == NULL) {
        RETURN_THROWS();
    }

    RETURN_STRING(socket->fingerprint);
}

PHP_METHOD(King_RTP_Socket, acceptDtls)
{
    char *ip = NULL;
    size_t ip_len = 0;
    zend_long port;
    zend_long timeout_ms = 5000;
    char errbuf[256] = {0};
    king_rtp_socket_t *socket;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(ip, ip_len)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();
    (void) ip_len;

    socket = king_rtp_socket_object_fetch(ZEND_THIS, "King\\RTP\\Socket::acceptDtls");
    if (socket == NULL) {
        RETURN_THROWS();
    }

    if (king_rtp_dtls_do_accept(socket, ip, (int) port, (int) timeout_ms, errbuf, sizeof(errbuf)) != 0) {
        king_set_error(errbuf);
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_METHOD(King_RTP_Socket, receive)
{
    zend_long timeout_ms = 0;
    king_rtp_socket_t *socket;
    king_rtp_receive_result_t packet;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    socket = king_rtp_socket_object_fetch(ZEND_THIS, "King\\RTP\\Socket::receive");
    if (socket == NULL) {
        RETURN_THROWS();
    }

    if (!king_rtp_socket_recv_packet(socket, (int) timeout_ms, &packet)) {
        RETURN_FALSE;
    }

    king_rtp_receive_result_to_array(return_value, &packet);
}

PHP_METHOD(King_RTP_Socket, send)
{
    char *host = NULL;
    char *data = NULL;
    size_t host_len = 0;
    size_t data_len = 0;
    zend_long port;
    king_rtp_socket_t *socket;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_STRING(data, data_len)
    ZEND_PARSE_PARAMETERS_END();
    (void) host_len;

    socket = king_rtp_socket_object_fetch(ZEND_THIS, "King\\RTP\\Socket::send");
    if (socket == NULL) {
        RETURN_THROWS();
    }

    RETURN_BOOL(king_rtp_socket_send_packet(socket, host, (int) port, data, data_len));
}

PHP_METHOD(King_RTP_Socket, close)
{
    king_rtp_socket_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_rtp_socket_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!intern->closed && !Z_ISUNDEF(intern->resource) && Z_TYPE(intern->resource) == IS_RESOURCE) {
        zend_list_close(Z_RES(intern->resource));
        intern->closed = true;
    }
}

PHP_METHOD(King_RTP_Socket, isClosed)
{
    king_rtp_socket_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_rtp_socket_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_BOOL(intern->closed);
}

const zend_function_entry king_rtp_socket_class_methods[] = {
    PHP_ME(King_RTP_Socket, __construct, arginfo_class_King_RTP_Socket___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_RTP_Socket, iceCredentials, arginfo_class_King_RTP_Socket_iceCredentials, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, dtlsFingerprint, arginfo_class_King_RTP_Socket_dtlsFingerprint, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, acceptDtls, arginfo_class_King_RTP_Socket_acceptDtls, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, receive, arginfo_class_King_RTP_Socket_receive, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, send, arginfo_class_King_RTP_Socket_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, close, arginfo_class_King_RTP_Socket_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_RTP_Socket, isClosed, arginfo_class_King_RTP_Socket_isClosed, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
