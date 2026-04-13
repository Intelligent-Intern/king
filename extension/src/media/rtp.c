/*
 * king_rtp.c — RTP socket management, ICE-lite STUN consent, packet I/O.
 *
 * ICE-lite (RFC 8445 §16): the server never gathers candidates or performs
 * connectivity checks.  It just responds to STUN Binding Requests with a
 * Binding Success Response carrying MESSAGE-INTEGRITY (HMAC-SHA1 over the
 * ICE password) and a FINGERPRINT (CRC-32c).  Once a STUN exchange succeeds
 * for a peer address, that peer is marked ice_consented = 1 and RTP packets
 * from it are forwarded normally.
 */

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"

#include <stdlib.h>
#include <string.h>
#include <errno.h>
#include <unistd.h>
#include <fcntl.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <sys/select.h>

#ifdef __APPLE__
#  include <CommonCrypto/CommonHMAC.h>
#else
#  include <openssl/hmac.h>
#  include <openssl/sha.h>
#endif

#include "rtp.h"

/* ── Resource type ─────────────────────────────────────────────────────── */

int le_king_rtp_socket;

static void king_rtp_socket_rsrc_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_rtp_socket_free((king_rtp_socket_t *)rsrc->ptr);
        rsrc->ptr = NULL;
    }
}

/* Call from MINIT to register the resource type. */
void king_rtp_minit(int module_number)
{
    le_king_rtp_socket = zend_register_list_destructors_ex(
        king_rtp_socket_rsrc_dtor, NULL, "King\\RtpSocket", module_number
    );
}

/* ── Random helpers ─────────────────────────────────────────────────────── */

static void rand_alphanum(char *buf, size_t n)
{
    static const char alpha[] =
        "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    for (size_t i = 0; i < n; i++) {
        buf[i] = alpha[rand() % (sizeof(alpha) - 1)];
    }
    buf[n] = '\0';
}

/* ── HMAC-SHA1 (for STUN MESSAGE-INTEGRITY) ─────────────────────────────── */

static void hmac_sha1(const uint8_t *key, size_t key_len,
                      const uint8_t *data, size_t data_len,
                      uint8_t out[20])
{
#ifdef __APPLE__
    CCHmac(kCCHmacAlgSHA1, key, key_len, data, data_len, out);
#else
    unsigned int out_len = 20;
    HMAC(EVP_sha1(), key, (int)key_len, data, data_len, out, &out_len);
#endif
}

/* ── CRC-32c for STUN FINGERPRINT ──────────────────────────────────────── */

static uint32_t crc32c_table[256];
static int crc32c_initialized = 0;

static void crc32c_init(void)
{
    for (uint32_t i = 0; i < 256; i++) {
        uint32_t c = i;
        for (int j = 0; j < 8; j++) {
            c = (c & 1) ? (0x82F63B78u ^ (c >> 1)) : (c >> 1);
        }
        crc32c_table[i] = c;
    }
    crc32c_initialized = 1;
}

static uint32_t crc32c(const uint8_t *buf, size_t len)
{
    if (!crc32c_initialized) crc32c_init();
    uint32_t crc = 0xFFFFFFFFu;
    for (size_t i = 0; i < len; i++) {
        crc = crc32c_table[(crc ^ buf[i]) & 0xFF] ^ (crc >> 8);
    }
    return crc ^ 0xFFFFFFFFu;
}

/* ── Socket creation ────────────────────────────────────────────────────── */

king_rtp_socket_t *king_rtp_socket_create(const char *host, int port,
                                           char *errbuf, size_t errbuf_len)
{
    struct addrinfo hints = {0}, *result = NULL, *cursor;
    char port_str[8];
    int fd = -1;

    snprintf(port_str, sizeof(port_str), "%d", port);
    hints.ai_family   = AF_UNSPEC;
    hints.ai_socktype = SOCK_DGRAM;
    hints.ai_flags    = AI_PASSIVE;

    int rc = getaddrinfo(host, port_str, &hints, &result);
    if (rc != 0) {
        snprintf(errbuf, errbuf_len, "getaddrinfo: %s", gai_strerror(rc));
        return NULL;
    }

    for (cursor = result; cursor; cursor = cursor->ai_next) {
        int optval = 1;
        fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (fd < 0) continue;
        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &optval, sizeof(optval));
        if (bind(fd, cursor->ai_addr, cursor->ai_addrlen) == 0) break;
        close(fd);
        fd = -1;
    }
    freeaddrinfo(result);

    if (fd < 0) {
        snprintf(errbuf, errbuf_len, "bind failed: %s", strerror(errno));
        return NULL;
    }

    /* Non-blocking for poll-based recv */
    fcntl(fd, F_SETFL, fcntl(fd, F_GETFL, 0) | O_NONBLOCK);

    king_rtp_socket_t *sock = ecalloc(1, sizeof(king_rtp_socket_t));
    sock->fd = fd;
    rand_alphanum(sock->ufrag, KING_ICE_UFRAG_LEN);
    rand_alphanum(sock->pwd,   KING_ICE_PWD_LEN);

    return sock;
}

void king_rtp_socket_free(king_rtp_socket_t *sock)
{
    if (!sock) return;

    king_rtp_peer_t *p = sock->peers;
    while (p) {
        king_rtp_peer_t *next = p->next;
#ifdef KING_HAVE_SRTP
        if (p->srtp_ready) {
            srtp_dealloc(p->srtp_recv);
            srtp_dealloc(p->srtp_send);
        }
#endif
        efree(p);
        p = next;
    }

    close(sock->fd);
    efree(sock);
}

/* ── Peer management ────────────────────────────────────────────────────── */

king_rtp_peer_t *king_rtp_peer_get(king_rtp_socket_t *sock,
                                    const struct sockaddr *addr, socklen_t addr_len)
{
    for (king_rtp_peer_t *p = sock->peers; p; p = p->next) {
        if (p->addr_len == addr_len &&
            memcmp(&p->addr, addr, addr_len) == 0) {
            return p;
        }
    }

    /* New peer */
    king_rtp_peer_t *p = ecalloc(1, sizeof(king_rtp_peer_t));
    memcpy(&p->addr, addr, addr_len);
    p->addr_len = addr_len;
    p->next     = sock->peers;
    sock->peers = p;
    return p;
}

/* ── STUN helpers ───────────────────────────────────────────────────────── */

static void stun_write_u16(uint8_t *p, uint16_t v) { p[0]=v>>8; p[1]=v&0xFF; }
static void stun_write_u32(uint8_t *p, uint32_t v) {
    p[0]=v>>24; p[1]=(v>>16)&0xFF; p[2]=(v>>8)&0xFF; p[3]=v&0xFF;
}
static uint16_t stun_read_u16(const uint8_t *p) { return ((uint16_t)p[0]<<8)|p[1]; }
static uint32_t stun_read_u32(const uint8_t *p) {
    return ((uint32_t)p[0]<<24)|((uint32_t)p[1]<<16)|((uint32_t)p[2]<<8)|p[3];
}

int king_rtp_handle_stun(king_rtp_socket_t *sock,
                          const uint8_t *buf, size_t len,
                          const struct sockaddr *from, socklen_t from_len)
{
    /* STUN packets: top 2 bits = 00, length >= 20 bytes */
    if (len < 20 || (buf[0] & 0xC0) != 0x00) return 0;

    uint16_t msg_type   = stun_read_u16(buf);
    uint16_t msg_len    = stun_read_u16(buf + 2);
    uint32_t magic      = stun_read_u32(buf + 4);
    const uint8_t *txid = buf + 8;  /* 12 bytes */

    if (magic != KING_STUN_MAGIC) return 0;
    if (msg_type != KING_STUN_BINDING_REQ) return 0;
    if ((size_t)(20 + msg_len) > len) return 0;

    /* Scan attributes for USERNAME — must start with our ufrag */
    const uint8_t *attr = buf + 20;
    const uint8_t *end  = buf + 20 + msg_len;
    int username_ok = 0;

    while (attr + 4 <= end) {
        uint16_t atype = stun_read_u16(attr);
        uint16_t alen  = stun_read_u16(attr + 2);
        const uint8_t *aval = attr + 4;

        if (atype == KING_STUN_ATTR_USERNAME) {
            size_t ufrag_len = strlen(sock->ufrag);
            if (alen >= ufrag_len &&
                memcmp(aval, sock->ufrag, ufrag_len) == 0 &&
                (alen == ufrag_len || aval[ufrag_len] == ':')) {
                username_ok = 1;
            }
        }

        attr += 4 + alen + ((4 - (alen & 3)) & 3);  /* 4-byte aligned */
    }

    if (!username_ok) return 1;  /* Handled (rejected) */

    /* Build Binding Success Response */
    uint8_t resp[512];
    size_t  rpos = 20;  /* header filled in last */

    /* XOR-MAPPED-ADDRESS */
    int is_v6 = (from->sa_family == AF_INET6);
    uint16_t xma_len = is_v6 ? 20 : 8;
    stun_write_u16(resp + rpos,     KING_STUN_ATTR_XMA); rpos += 2;
    stun_write_u16(resp + rpos,     xma_len);            rpos += 2;
    resp[rpos++] = 0x00;
    resp[rpos++] = is_v6 ? 0x02 : 0x01;  /* family */

    if (!is_v6) {
        const struct sockaddr_in *s4 = (const struct sockaddr_in *)from;
        uint16_t port_xor = ntohs(s4->sin_port)  ^ (KING_STUN_MAGIC >> 16);
        uint32_t addr_xor = ntohl(s4->sin_addr.s_addr) ^ KING_STUN_MAGIC;
        stun_write_u16(resp + rpos, port_xor); rpos += 2;
        stun_write_u32(resp + rpos, addr_xor); rpos += 4;
    } else {
        const struct sockaddr_in6 *s6 = (const struct sockaddr_in6 *)from;
        uint16_t port_xor = ntohs(s6->sin6_port) ^ (KING_STUN_MAGIC >> 16);
        stun_write_u16(resp + rpos, port_xor); rpos += 2;
        /* XOR each 32-bit word with magic+txid */
        for (int i = 0; i < 4; i++) {
            uint32_t word;
            memcpy(&word, s6->sin6_addr.s6_addr + i*4, 4);
            uint32_t xor_key = (i == 0) ? KING_STUN_MAGIC
                                         : stun_read_u32(txid + (i-1)*4);
            stun_write_u32(resp + rpos, ntohl(word) ^ xor_key);
            rpos += 4;
        }
    }

    /* MESSAGE-INTEGRITY — HMAC-SHA1 over header+attrs so far */
    /* Update length field to include MI attr (but not FP) */
    size_t mi_msg_len = rpos - 20 + 4 + 20;
    stun_write_u16(resp,     KING_STUN_BINDING_RESP);
    stun_write_u16(resp + 2, (uint16_t)mi_msg_len);
    stun_write_u32(resp + 4, KING_STUN_MAGIC);
    memcpy(resp + 8, txid, 12);

    uint8_t hmac[20];
    hmac_sha1((const uint8_t *)sock->pwd, strlen(sock->pwd), resp, rpos, hmac);
    stun_write_u16(resp + rpos, KING_STUN_ATTR_MI); rpos += 2;
    stun_write_u16(resp + rpos, 20);                rpos += 2;
    memcpy(resp + rpos, hmac, 20);                  rpos += 20;

    /* FINGERPRINT — CRC-32c XOR 0x5354554E, over message so far */
    size_t fp_msg_len = rpos - 20 + 4 + 4;
    stun_write_u16(resp + 2, (uint16_t)fp_msg_len);
    uint32_t fp = crc32c(resp, rpos) ^ 0x5354554Eu;
    stun_write_u16(resp + rpos, KING_STUN_ATTR_FP); rpos += 2;
    stun_write_u16(resp + rpos, 4);                 rpos += 2;
    stun_write_u32(resp + rpos, fp);                rpos += 4;

    /* Final header length */
    stun_write_u16(resp + 2, (uint16_t)(rpos - 20));

    sendto(sock->fd, resp, rpos, 0, from, from_len);

    /* Mark peer as ICE consented */
    king_rtp_peer_t *peer = king_rtp_peer_get(sock, from, from_len);
    peer->ice_consented = 1;

    return 1;
}

/* ── RTP packet parser ──────────────────────────────────────────────────── */

int king_rtp_parse(const uint8_t *buf, size_t len, king_rtp_packet_t *out)
{
    if (len < KING_RTP_HEADER_MIN) return -1;
    if ((buf[0] >> 6) != 2) return -1;  /* version must be 2 */

    int cc      = buf[0] & 0x0F;
    int has_ext = (buf[0] >> 4) & 1;
    size_t hdr  = KING_RTP_HEADER_MIN + cc * 4;

    if (has_ext) {
        if (len < hdr + 4) return -1;
        uint16_t ext_len = (uint16_t)(buf[hdr+2] << 8 | buf[hdr+3]);
        hdr += 4 + ext_len * 4;
    }

    if (len < hdr) return -1;

    out->payload_type = buf[1] & 0x7F;
    out->marker       = (buf[1] >> 7) & 1;
    out->seq          = (uint16_t)(buf[2] << 8 | buf[3]);
    out->timestamp    = stun_read_u32(buf + 4);
    out->ssrc         = stun_read_u32(buf + 8);

    size_t payload_len = len - hdr;
    if (payload_len > sizeof(out->payload)) payload_len = sizeof(out->payload);
    memcpy(out->payload, buf + hdr, payload_len);
    out->payload_len = payload_len;

    return 0;
}

/* ── PHP functions ──────────────────────────────────────────────────────── */

/*
 * king_rtp_bind(string $host, int $port): resource|false
 *
 * Binds a UDP socket on $host:$port, generates ICE credentials, and returns
 * an opaque RtpSocket resource.
 */
PHP_FUNCTION(king_rtp_bind)
{
    char    *host;
    size_t   host_len;
    zend_long port;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
    ZEND_PARSE_PARAMETERS_END();

    char errbuf[256] = {0};
    king_rtp_socket_t *sock = king_rtp_socket_create(host, (int)port, errbuf, sizeof(errbuf));

    if (!sock) {
        php_error_docref(NULL, E_WARNING, "king_rtp_bind: %s", errbuf);
        RETURN_FALSE;
    }

    RETURN_RES(zend_register_resource(sock, le_king_rtp_socket));
}

/*
 * king_rtp_ice_credentials(resource $socket): array
 *
 * Returns ['ufrag' => string, 'pwd' => string] for inclusion in an SDP offer.
 */
PHP_FUNCTION(king_rtp_ice_credentials)
{
    zval     *zsock;
    zend_resource *rsrc;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(zsock)
    ZEND_PARSE_PARAMETERS_END();

    rsrc = Z_RES_P(zsock);
    if (rsrc->type != le_king_rtp_socket) {
        php_error_docref(NULL, E_WARNING, "king_rtp_ice_credentials: invalid resource");
        RETURN_FALSE;
    }

    king_rtp_socket_t *sock = (king_rtp_socket_t *)rsrc->ptr;

    array_init(return_value);
    add_assoc_string(return_value, "ufrag", sock->ufrag);
    add_assoc_string(return_value, "pwd",   sock->pwd);
}

/*
 * king_rtp_recv(resource $socket, int $timeout_ms = 0): array|false
 *
 * Waits up to $timeout_ms for an RTP packet (STUN packets are handled
 * transparently and do not surface to PHP).
 *
 * Returns an array:
 *   'ssrc'         => int
 *   'payload_type' => int
 *   'seq'          => int
 *   'timestamp'    => int
 *   'marker'       => bool
 *   'data'         => string  (raw payload bytes)
 *   'from_ip'      => string
 *   'from_port'    => int
 *   'ice_consented'=> bool    (was this peer ICE-consented?)
 *
 * Returns false on timeout or error.
 */
PHP_FUNCTION(king_rtp_recv)
{
    zval      *zsock;
    zend_long  timeout_ms = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_RESOURCE(zsock)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    zend_resource *rsrc = Z_RES_P(zsock);
    if (rsrc->type != le_king_rtp_socket) {
        php_error_docref(NULL, E_WARNING, "king_rtp_recv: invalid resource");
        RETURN_FALSE;
    }
    king_rtp_socket_t *sock = (king_rtp_socket_t *)rsrc->ptr;

    uint8_t  buf[KING_RTP_MTU];
    struct sockaddr_storage from;
    socklen_t from_len = sizeof(from);

    /* Poll loop: re-enter on STUN packets until we get RTP or timeout */
    struct timeval deadline;
    gettimeofday(&deadline, NULL);
    deadline.tv_usec += (timeout_ms % 1000) * 1000;
    deadline.tv_sec  += timeout_ms / 1000 + deadline.tv_usec / 1000000;
    deadline.tv_usec %= 1000000;

    for (;;) {
        struct timeval now, rem = {0, 0};
        gettimeofday(&now, NULL);
        long rem_us = (deadline.tv_sec  - now.tv_sec)  * 1000000
                    + (deadline.tv_usec - now.tv_usec);
        if (rem_us <= 0) RETURN_FALSE;
        rem.tv_sec  = rem_us / 1000000;
        rem.tv_usec = rem_us % 1000000;

        fd_set fds;
        FD_ZERO(&fds);
        FD_SET(sock->fd, &fds);
        int r = select(sock->fd + 1, &fds, NULL, NULL, &rem);
        if (r <= 0) RETURN_FALSE;

        from_len = sizeof(from);
        ssize_t n = recvfrom(sock->fd, buf, sizeof(buf), 0,
                             (struct sockaddr *)&from, &from_len);
        if (n < 0) RETURN_FALSE;

        /* STUN? handle and loop */
        if (king_rtp_handle_stun(sock, buf, (size_t)n,
                                 (struct sockaddr *)&from, from_len)) {
            continue;
        }

        /* RTP */
        king_rtp_packet_t pkt;
        if (king_rtp_parse(buf, (size_t)n, &pkt) != 0) continue;

        king_rtp_peer_t *peer = king_rtp_peer_get(sock,
                                    (struct sockaddr *)&from, from_len);
        peer->ssrc = pkt.ssrc;

        /* Stringify remote address */
        char ip[INET6_ADDRSTRLEN] = {0};
        int  peer_port = 0;
        if (from.ss_family == AF_INET) {
            struct sockaddr_in *s4 = (struct sockaddr_in *)&from;
            inet_ntop(AF_INET, &s4->sin_addr, ip, sizeof(ip));
            peer_port = ntohs(s4->sin_port);
        } else {
            struct sockaddr_in6 *s6 = (struct sockaddr_in6 *)&from;
            inet_ntop(AF_INET6, &s6->sin6_addr, ip, sizeof(ip));
            peer_port = ntohs(s6->sin6_port);
        }

        array_init(return_value);
        add_assoc_long(  return_value, "ssrc",          (zend_long)pkt.ssrc);
        add_assoc_long(  return_value, "payload_type",  pkt.payload_type);
        add_assoc_long(  return_value, "seq",           pkt.seq);
        add_assoc_long(  return_value, "timestamp",     (zend_long)pkt.timestamp);
        add_assoc_bool(  return_value, "marker",        pkt.marker);
        add_assoc_stringl(return_value, "data",
                          (char *)pkt.payload, pkt.payload_len);
        add_assoc_string(return_value, "from_ip",       ip);
        add_assoc_long(  return_value, "from_port",     peer_port);
        add_assoc_bool(  return_value, "ice_consented", peer->ice_consented);
        return;
    }
}

/*
 * king_rtp_send(resource $socket, string $host, int $port, string $data): bool
 *
 * Sends raw bytes (an RTP packet) to $host:$port.  The caller is responsible
 * for constructing the RTP header.  Returns true on success.
 */
PHP_FUNCTION(king_rtp_send)
{
    zval      *zsock;
    char      *host, *data;
    size_t     host_len, data_len;
    zend_long  port;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_RESOURCE(zsock)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_STRING(data, data_len)
    ZEND_PARSE_PARAMETERS_END();

    zend_resource *rsrc = Z_RES_P(zsock);
    if (rsrc->type != le_king_rtp_socket) {
        php_error_docref(NULL, E_WARNING, "king_rtp_send: invalid resource");
        RETURN_FALSE;
    }
    king_rtp_socket_t *sock = (king_rtp_socket_t *)rsrc->ptr;

    struct addrinfo hints = {0}, *result = NULL;
    char port_str[8];
    snprintf(port_str, sizeof(port_str), "%ld", (long)port);
    hints.ai_family   = AF_UNSPEC;
    hints.ai_socktype = SOCK_DGRAM;

    if (getaddrinfo(host, port_str, &hints, &result) != 0 || !result) {
        RETURN_FALSE;
    }

    ssize_t sent = sendto(sock->fd, data, data_len, 0,
                          result->ai_addr, result->ai_addrlen);
    freeaddrinfo(result);

    RETURN_BOOL(sent == (ssize_t)data_len);
}

/*
 * king_rtp_close(resource $socket): void
 *
 * Releases the socket and all peer state.
 */
PHP_FUNCTION(king_rtp_close)
{
    zval *zsock;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(zsock)
    ZEND_PARSE_PARAMETERS_END();

    zend_list_close(Z_RES_P(zsock));
}
