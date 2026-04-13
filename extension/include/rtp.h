/*
 * king_rtp — RTP/ICE-lite/DTLS-SRTP SFU layer for the King extension.
 *
 * PHP surface:
 *   king_rtp_bind(host, port)                         → rtp_socket resource
 *   king_rtp_ice_credentials(socket)                  → ['ufrag'=>…, 'pwd'=>…]
 *   king_rtp_dtls_fingerprint(socket)                 → 'SHA-256 XX:XX:…'
 *   king_rtp_dtls_accept(socket, ip, port, timeout_ms)→ bool
 *   king_rtp_recv(socket, timeout_ms)                 → array|false
 *   king_rtp_send(socket, host, port, data)           → bool
 *   king_rtp_close(socket)                            → void
 */

#ifndef KING_MEDIA_RTP_H
#define KING_MEDIA_RTP_H

#include <stdint.h>
#include <stddef.h>
#include <sys/socket.h>
#include <netinet/in.h>

#include <openssl/ssl.h>
#include <openssl/evp.h>
#include <openssl/x509.h>

#ifdef KING_HAVE_SRTP
#  include <srtp2/srtp.h>
#endif

/* ── Constants ─────────────────────────────────────────────────────────── */

#define KING_RTP_MTU            1500
#define KING_RTP_HEADER_MIN     12
#define KING_STUN_MAGIC         0x2112A442u
#define KING_STUN_BINDING_REQ   0x0001u
#define KING_STUN_BINDING_RESP  0x0101u
#define KING_STUN_ATTR_USERNAME 0x0006u
#define KING_STUN_ATTR_MI       0x0008u
#define KING_STUN_ATTR_XMA      0x0020u
#define KING_STUN_ATTR_FP       0x8028u

#define KING_ICE_UFRAG_LEN      8
#define KING_ICE_PWD_LEN        24
#define KING_DTLS_FINGERPRINT_LEN 96   /* "SHA-256 XX:XX:…\0" */

/*
 * DTLS-SRTP key material layout (RFC 5764 §4.2, AES-128-CM-SHA1-80):
 *   client_write_key  [0..15]   16 bytes
 *   server_write_key  [16..31]  16 bytes
 *   client_write_salt [32..43]  14 bytes
 *   server_write_salt [44..57]  14 bytes
 */
#define KING_SRTP_KEY_LEN    16
#define KING_SRTP_SALT_LEN   14
#define KING_SRTP_MATERIAL   (2 * (KING_SRTP_KEY_LEN + KING_SRTP_SALT_LEN))

/* ── Structs ────────────────────────────────────────────────────────────── */

typedef struct king_rtp_peer_s {
    struct sockaddr_storage addr;
    socklen_t               addr_len;
    uint32_t                ssrc;
    int                     ice_consented;

    /* Per-peer connected UDP socket + DTLS session */
    int                     peer_fd;     /* connected UDP socket; -1 until DTLS */
    SSL                    *ssl;
    int                     dtls_done;

#ifdef KING_HAVE_SRTP
    srtp_t   srtp_recv;
    srtp_t   srtp_send;
    int      srtp_ready;
#endif

    struct king_rtp_peer_s *next;
} king_rtp_peer_t;

typedef struct {
    int              fd;
    char             ufrag[KING_ICE_UFRAG_LEN + 1];
    char             pwd[KING_ICE_PWD_LEN   + 1];
    SSL_CTX         *ssl_ctx;
    char             fingerprint[KING_DTLS_FINGERPRINT_LEN];
    king_rtp_peer_t *peers;
} king_rtp_socket_t;

typedef struct {
    uint8_t  payload_type;
    uint8_t  marker;
    uint16_t seq;
    uint32_t timestamp;
    uint32_t ssrc;
    uint8_t  payload[KING_RTP_MTU];
    size_t   payload_len;
    struct sockaddr_storage from;
    socklen_t               from_len;
} king_rtp_packet_t;

/* ── Internal API ───────────────────────────────────────────────────────── */

king_rtp_socket_t *king_rtp_socket_create(const char *host, int port,
                                           char *errbuf, size_t errbuf_len);
void               king_rtp_socket_free(king_rtp_socket_t *sock);

int  king_rtp_handle_stun(king_rtp_socket_t *sock,
                          const uint8_t *buf, size_t len,
                          const struct sockaddr *from, socklen_t from_len);

int  king_rtp_parse(const uint8_t *buf, size_t len, king_rtp_packet_t *out);

king_rtp_peer_t *king_rtp_peer_get(king_rtp_socket_t *sock,
                                   const struct sockaddr *addr, socklen_t addr_len);

/* DTLS handshake for a specific peer.  Returns 0 on success. */
int  king_rtp_dtls_do_accept(king_rtp_socket_t *sock,
                             const char *peer_ip, int peer_port,
                             int timeout_ms, char *errbuf, size_t errbuf_len);

/* ── PHP resource type ──────────────────────────────────────────────────── */
extern int le_king_rtp_socket;
void king_rtp_minit(int module_number);

#ifdef ZEND_ENGINE_3
PHP_FUNCTION(king_rtp_bind);
PHP_FUNCTION(king_rtp_ice_credentials);
PHP_FUNCTION(king_rtp_dtls_fingerprint);
PHP_FUNCTION(king_rtp_dtls_accept);
PHP_FUNCTION(king_rtp_recv);
PHP_FUNCTION(king_rtp_send);
PHP_FUNCTION(king_rtp_close);
#endif

#endif /* KING_MEDIA_RTP_H */
