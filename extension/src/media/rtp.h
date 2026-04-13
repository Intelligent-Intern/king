/*
 * king_rtp — lightweight RTP/ICE-lite SFU layer for the King extension.
 *
 * Provides raw UDP socket management, ICE-lite STUN consent (RFC 5389 / 8445),
 * and RTP packet forwarding.  SRTP encryption is layered on top via libsrtp2
 * when available (KING_HAVE_SRTP defined at compile time).
 *
 * PHP surface:
 *   king_rtp_bind(host, port)          → rtp_socket resource
 *   king_rtp_ice_credentials(socket)   → [ufrag, password]
 *   king_rtp_recv(socket, timeout_ms)  → array|false
 *   king_rtp_send(socket, host, port, data) → bool
 *   king_rtp_close(socket)             → void
 */

#ifndef KING_MEDIA_RTP_H
#define KING_MEDIA_RTP_H

#include <stdint.h>
#include <stddef.h>
#include <sys/socket.h>
#include <netinet/in.h>

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
#define KING_STUN_ATTR_MI       0x0008u   /* MESSAGE-INTEGRITY */
#define KING_STUN_ATTR_XMA      0x0020u   /* XOR-MAPPED-ADDRESS */
#define KING_STUN_ATTR_FP       0x8028u   /* FINGERPRINT */

#define KING_ICE_UFRAG_LEN      8
#define KING_ICE_PWD_LEN        24

/* ── Structs ────────────────────────────────────────────────────────────── */

/*
 * A connected RTP peer: remote address + optional SRTP contexts.
 */
typedef struct king_rtp_peer_s {
    struct sockaddr_storage addr;
    socklen_t               addr_len;
    uint32_t                ssrc;           /* last observed SSRC from this peer */
    int                     ice_consented;  /* 1 after successful STUN exchange   */

#ifdef KING_HAVE_SRTP
    srtp_t   srtp_recv;
    srtp_t   srtp_send;
    int      srtp_ready;
#endif

    struct king_rtp_peer_s *next;
} king_rtp_peer_t;

/*
 * A bound RTP socket: file descriptor + ICE credentials + peer list.
 */
typedef struct {
    int              fd;
    char             ufrag[KING_ICE_UFRAG_LEN + 1];
    char             pwd[KING_ICE_PWD_LEN   + 1];
    king_rtp_peer_t *peers;
} king_rtp_socket_t;

/*
 * A single received RTP packet, returned to PHP as an associative array.
 */
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

/* ── Internal helpers ───────────────────────────────────────────────────── */

king_rtp_socket_t *king_rtp_socket_create(const char *host, int port, char *errbuf, size_t errbuf_len);
void               king_rtp_socket_free(king_rtp_socket_t *sock);

/* Returns 1 if buf looks like a STUN message and we handled it, 0 otherwise. */
int  king_rtp_handle_stun(king_rtp_socket_t *sock,
                          const uint8_t *buf, size_t len,
                          const struct sockaddr *from, socklen_t from_len);

/* Parse raw bytes into a king_rtp_packet_t.  Returns 0 on success. */
int  king_rtp_parse(const uint8_t *buf, size_t len, king_rtp_packet_t *out);

/* Find or create a peer entry for the given address. */
king_rtp_peer_t *king_rtp_peer_get(king_rtp_socket_t *sock,
                                   const struct sockaddr *addr, socklen_t addr_len);

/* ── PHP resource type id (set in MINIT) ────────────────────────────────── */
extern int le_king_rtp_socket;
void king_rtp_minit(int module_number);

#ifdef ZTS
#  include "TSRM.h"
#endif
#ifdef ZEND_ENGINE_3
#  include "zend.h"
PHP_FUNCTION(king_rtp_bind);
PHP_FUNCTION(king_rtp_ice_credentials);
PHP_FUNCTION(king_rtp_recv);
PHP_FUNCTION(king_rtp_send);
PHP_FUNCTION(king_rtp_close);
#endif

#endif /* KING_MEDIA_RTP_H */
