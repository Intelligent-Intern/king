/*
 * king_rtp.c — UDP/ICE-lite/DTLS-SRTP SFU primitives for the King PHP extension.
 *
 * Each king_rtp_socket_t holds:
 *   • One shared UDP listener (ICE STUN + initial packet demux)
 *   • An SSL_CTX with an ephemeral self-signed cert (DTLS server)
 *   • A linked list of king_rtp_peer_t, one per remote endpoint
 *
 * Per-peer DTLS is performed on a separate connected UDP socket so that
 * OpenSSL's DTLS state machine sees a clean, single-peer socket.  After the
 * handshake the keying material is extracted (RFC 5764) and libsrtp2 contexts
 * are initialised (when KING_HAVE_SRTP is defined).
 *
 * king_rtp_recv() transparently:
 *   1. Discards STUN packets (handled inline, responds to Binding Requests)
 *   2. SRTP-decrypts the RTP payload when srtp_ready = 1
 *   3. Returns the plaintext payload + metadata to PHP
 *
 * king_rtp_send() SRTP-encrypts when srtp_ready = 1.
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
#include <sys/time.h>

#include <openssl/ssl.h>
#include <openssl/err.h>
#include <openssl/evp.h>
#include <openssl/rsa.h>
#include <openssl/x509.h>
#include <openssl/x509v3.h>
#include <openssl/bn.h>
#include <openssl/bio.h>
#include <openssl/rand.h>

#ifdef __APPLE__
#  include <CommonCrypto/CommonHMAC.h>
#else
#  include <openssl/hmac.h>
#endif

#ifdef KING_HAVE_SRTP
#  include <srtp2/srtp.h>
static int srtp_initialized = 0;
#endif

#include "rtp.h"

/* ── Resource registration ──────────────────────────────────────────────── */

int le_king_rtp_socket;

static void king_rtp_socket_rsrc_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_rtp_socket_free((king_rtp_socket_t *)rsrc->ptr);
        rsrc->ptr = NULL;
    }
}

void king_rtp_minit(int module_number)
{
#ifdef KING_HAVE_SRTP
    if (!srtp_initialized) {
        srtp_init();
        srtp_initialized = 1;
    }
#endif
    le_king_rtp_socket = zend_register_list_destructors_ex(
        king_rtp_socket_rsrc_dtor, NULL, "King\\RtpSocket", module_number
    );
}

/* ── Misc helpers ───────────────────────────────────────────────────────── */

static void rand_alphanum(char *buf, size_t n)
{
    static const char alpha[] =
        "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    RAND_bytes((unsigned char *)buf, (int)n);
    for (size_t i = 0; i < n; i++)
        buf[i] = alpha[(unsigned char)buf[i] % (sizeof(alpha) - 1)];
    buf[n] = '\0';
}

/* ── HMAC-SHA1 (STUN MESSAGE-INTEGRITY) ─────────────────────────────────── */

static void hmac_sha1(const uint8_t *key, size_t klen,
                      const uint8_t *data, size_t dlen,
                      uint8_t out[20])
{
#ifdef __APPLE__
    CCHmac(kCCHmacAlgSHA1, key, klen, data, dlen, out);
#else
    unsigned int olen = 20;
    HMAC(EVP_sha1(), key, (int)klen, data, dlen, out, &olen);
#endif
}

/* ── CRC-32c (STUN FINGERPRINT) ─────────────────────────────────────────── */

static uint32_t crc32c_table[256];
static int crc32c_ready = 0;

static void crc32c_init(void)
{
    for (uint32_t i = 0; i < 256; i++) {
        uint32_t c = i;
        for (int j = 0; j < 8; j++)
            c = (c & 1) ? (0x82F63B78u ^ (c >> 1)) : (c >> 1);
        crc32c_table[i] = c;
    }
    crc32c_ready = 1;
}

static uint32_t crc32c(const uint8_t *buf, size_t len)
{
    if (!crc32c_ready) crc32c_init();
    uint32_t crc = 0xFFFFFFFFu;
    for (size_t i = 0; i < len; i++)
        crc = crc32c_table[(crc ^ buf[i]) & 0xFF] ^ (crc >> 8);
    return crc ^ 0xFFFFFFFFu;
}

static void stun_write_u16(uint8_t *p, uint16_t v) { p[0]=v>>8; p[1]=v&0xFF; }
static void stun_write_u32(uint8_t *p, uint32_t v) {
    p[0]=v>>24; p[1]=(v>>16)&0xFF; p[2]=(v>>8)&0xFF; p[3]=v&0xFF;
}
static uint16_t stun_read_u16(const uint8_t *p) { return ((uint16_t)p[0]<<8)|p[1]; }
static uint32_t stun_read_u32(const uint8_t *p) {
    return ((uint32_t)p[0]<<24)|((uint32_t)p[1]<<16)|((uint32_t)p[2]<<8)|p[3];
}

/* ── DTLS / certificate generation ──────────────────────────────────────── */

static SSL_CTX *king_dtls_ctx_create(char *fp_out, size_t fp_len)
{
    SSL_CTX *ctx = SSL_CTX_new(DTLS_server_method());
    if (!ctx) return NULL;

    /* Generate 2048-bit RSA key */
    EVP_PKEY *pkey = EVP_PKEY_new();
    BIGNUM   *bn   = BN_new();
    RSA      *rsa  = RSA_new();
    BN_set_word(bn, RSA_F4);
    RSA_generate_key_ex(rsa, 2048, bn, NULL);
    EVP_PKEY_assign_RSA(pkey, rsa);
    BN_free(bn);

    /* Self-signed certificate */
    X509 *cert = X509_new();
    X509_set_version(cert, 2);
    ASN1_INTEGER_set(X509_get_serialNumber(cert), 1);
    X509_gmtime_adj(X509_get_notBefore(cert), 0);
    X509_gmtime_adj(X509_get_notAfter(cert),  60 * 60 * 24 * 365);

    X509_NAME *name = X509_get_subject_name(cert);
    X509_NAME_add_entry_by_txt(name, "CN", MBSTRING_ASC,
                               (const unsigned char *)"king-rtp", -1, -1, 0);
    X509_set_issuer_name(cert, name);
    X509_set_pubkey(cert, pkey);
    X509_sign(cert, pkey, EVP_sha256());

    SSL_CTX_use_certificate(ctx, cert);
    SSL_CTX_use_PrivateKey(ctx, pkey);

    /* SHA-256 fingerprint for SDP a=fingerprint */
    unsigned char fp[EVP_MAX_MD_SIZE];
    unsigned int  fp_real_len = 0;
    X509_digest(cert, EVP_sha256(), fp, &fp_real_len);

    char *wp = fp_out;
    size_t rem = fp_len;
    int n = snprintf(wp, rem, "SHA-256 ");
    wp += n; rem -= (size_t)n;
    for (unsigned int i = 0; i < fp_real_len && rem > 3; i++) {
        n = snprintf(wp, rem, "%s%02X", (i ? ":" : ""), fp[i]);
        wp += n; rem -= (size_t)n;
    }

    /* Negotiate DTLS-SRTP profile (RFC 5764) */
    SSL_CTX_set_tlsext_use_srtp(ctx, "SRTP_AES128_CM_SHA1_80");

    /* Require client certificate for mutual DTLS auth */
    SSL_CTX_set_verify(ctx, SSL_VERIFY_PEER | SSL_VERIFY_FAIL_IF_NO_PEER_CERT,
                       NULL);
    /* Accept any client cert (fingerprint verified out-of-band via SDP) */
    SSL_CTX_set_verify_depth(ctx, 0);
    SSL_CTX_set_cert_verify_callback(ctx, NULL, NULL);

    X509_free(cert);
    EVP_PKEY_free(pkey);

    return ctx;
}

/* ── Socket creation / teardown ─────────────────────────────────────────── */

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

    if (getaddrinfo(host, port_str, &hints, &result) != 0 || !result) {
        snprintf(errbuf, errbuf_len, "getaddrinfo failed: %s", strerror(errno));
        return NULL;
    }

    for (cursor = result; cursor; cursor = cursor->ai_next) {
        int opt = 1;
        fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (fd < 0) continue;
        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));
        if (bind(fd, cursor->ai_addr, cursor->ai_addrlen) == 0) break;
        close(fd); fd = -1;
    }
    freeaddrinfo(result);

    if (fd < 0) {
        snprintf(errbuf, errbuf_len, "bind failed: %s", strerror(errno));
        return NULL;
    }
    fcntl(fd, F_SETFL, fcntl(fd, F_GETFL, 0) | O_NONBLOCK);

    king_rtp_socket_t *sock = ecalloc(1, sizeof(king_rtp_socket_t));
    sock->fd = fd;
    rand_alphanum(sock->ufrag, KING_ICE_UFRAG_LEN);
    rand_alphanum(sock->pwd,   KING_ICE_PWD_LEN);

    sock->ssl_ctx = king_dtls_ctx_create(sock->fingerprint,
                                          sizeof(sock->fingerprint));
    if (!sock->ssl_ctx) {
        snprintf(errbuf, errbuf_len, "DTLS context creation failed");
        close(fd);
        efree(sock);
        return NULL;
    }

    return sock;
}

void king_rtp_socket_free(king_rtp_socket_t *sock)
{
    if (!sock) return;
    king_rtp_peer_t *p = sock->peers;
    while (p) {
        king_rtp_peer_t *next = p->next;
        if (p->ssl)     { SSL_free(p->ssl); }
        if (p->peer_fd >= 0) { close(p->peer_fd); }
#ifdef KING_HAVE_SRTP
        if (p->srtp_ready) {
            srtp_dealloc(p->srtp_recv);
            srtp_dealloc(p->srtp_send);
        }
#endif
        efree(p);
        p = next;
    }
    if (sock->ssl_ctx) SSL_CTX_free(sock->ssl_ctx);
    close(sock->fd);
    efree(sock);
}

/* ── Peer management ────────────────────────────────────────────────────── */

king_rtp_peer_t *king_rtp_peer_get(king_rtp_socket_t *sock,
                                    const struct sockaddr *addr, socklen_t alen)
{
    for (king_rtp_peer_t *p = sock->peers; p; p = p->next)
        if (p->addr_len == alen && memcmp(&p->addr, addr, alen) == 0)
            return p;

    king_rtp_peer_t *p = ecalloc(1, sizeof(king_rtp_peer_t));
    memcpy(&p->addr, addr, alen);
    p->addr_len = alen;
    p->peer_fd  = -1;
    p->next     = sock->peers;
    sock->peers = p;
    return p;
}

static king_rtp_peer_t *king_rtp_peer_by_addr_str(king_rtp_socket_t *sock,
                                                    const char *ip, int port)
{
    for (king_rtp_peer_t *p = sock->peers; p; p = p->next) {
        char buf[INET6_ADDRSTRLEN] = {0};
        int  pport = 0;
        if (p->addr.ss_family == AF_INET) {
            struct sockaddr_in *s4 = (struct sockaddr_in *)&p->addr;
            inet_ntop(AF_INET, &s4->sin_addr, buf, sizeof(buf));
            pport = ntohs(s4->sin_port);
        } else {
            struct sockaddr_in6 *s6 = (struct sockaddr_in6 *)&p->addr;
            inet_ntop(AF_INET6, &s6->sin6_addr, buf, sizeof(buf));
            pport = ntohs(s6->sin6_port);
        }
        if (pport == port && strcmp(buf, ip) == 0) return p;
    }
    return NULL;
}

/* ── ICE-lite STUN ──────────────────────────────────────────────────────── */

int king_rtp_handle_stun(king_rtp_socket_t *sock,
                          const uint8_t *buf, size_t len,
                          const struct sockaddr *from, socklen_t from_len)
{
    if (len < 20 || (buf[0] & 0xC0) != 0x00) return 0;

    uint16_t msg_type = stun_read_u16(buf);
    uint16_t msg_len  = stun_read_u16(buf + 2);
    uint32_t magic    = stun_read_u32(buf + 4);
    const uint8_t *txid = buf + 8;

    if (magic != KING_STUN_MAGIC || msg_type != KING_STUN_BINDING_REQ) return 0;
    if ((size_t)(20 + msg_len) > len) return 0;

    const uint8_t *attr = buf + 20;
    const uint8_t *end  = buf + 20 + msg_len;
    int username_ok = 0;

    while (attr + 4 <= end) {
        size_t remain = (size_t)(end - attr);
        uint16_t atype = stun_read_u16(attr);
        uint16_t alen  = stun_read_u16(attr + 2);
        size_t pad_len = (size_t)((4 - (alen & 3)) & 3);
        size_t attr_len = 4 + (size_t)alen + pad_len;

        if (remain < 4 + (size_t)alen || remain < attr_len) {
            break;
        }

        if (atype == KING_STUN_ATTR_USERNAME) {
            size_t ul = strlen(sock->ufrag);
            if (alen >= ul && memcmp(attr + 4, sock->ufrag, ul) == 0 &&
                (alen == ul || attr[4 + ul] == ':'))
                username_ok = 1;
        }
        attr += attr_len;
    }
    if (!username_ok) return 1;

    /* Build response */
    uint8_t resp[512]; size_t rpos = 20;
    int is6 = (from->sa_family == AF_INET6);

    stun_write_u16(resp + rpos, KING_STUN_ATTR_XMA); rpos += 2;
    stun_write_u16(resp + rpos, is6 ? 20 : 8);       rpos += 2;
    resp[rpos++] = 0x00;
    resp[rpos++] = is6 ? 0x02 : 0x01;

    if (!is6) {
        const struct sockaddr_in *s4 = (const struct sockaddr_in *)from;
        stun_write_u16(resp + rpos, ntohs(s4->sin_port) ^ (KING_STUN_MAGIC >> 16)); rpos += 2;
        stun_write_u32(resp + rpos, ntohl(s4->sin_addr.s_addr) ^ KING_STUN_MAGIC);  rpos += 4;
    } else {
        const struct sockaddr_in6 *s6 = (const struct sockaddr_in6 *)from;
        stun_write_u16(resp + rpos, ntohs(s6->sin6_port) ^ (KING_STUN_MAGIC >> 16)); rpos += 2;
        for (int i = 0; i < 4; i++) {
            uint32_t w; memcpy(&w, s6->sin6_addr.s6_addr + i*4, 4);
            uint32_t x = (i == 0) ? KING_STUN_MAGIC : stun_read_u32(txid + (i-1)*4);
            stun_write_u32(resp + rpos, ntohl(w) ^ x); rpos += 4;
        }
    }

    /* MESSAGE-INTEGRITY */
    stun_write_u16(resp,     KING_STUN_BINDING_RESP);
    stun_write_u16(resp + 2, (uint16_t)(rpos - 20 + 24));
    stun_write_u32(resp + 4, KING_STUN_MAGIC);
    memcpy(resp + 8, txid, 12);

    uint8_t hmac[20];
    hmac_sha1((const uint8_t *)sock->pwd, strlen(sock->pwd), resp, rpos, hmac);
    stun_write_u16(resp + rpos, KING_STUN_ATTR_MI); rpos += 2;
    stun_write_u16(resp + rpos, 20);                rpos += 2;
    memcpy(resp + rpos, hmac, 20);                  rpos += 20;

    /* FINGERPRINT */
    stun_write_u16(resp + 2, (uint16_t)(rpos - 20 + 8));
    uint32_t fp = crc32c(resp, rpos) ^ 0x5354554Eu;
    stun_write_u16(resp + rpos, KING_STUN_ATTR_FP); rpos += 2;
    stun_write_u16(resp + rpos, 4);                 rpos += 2;
    stun_write_u32(resp + rpos, fp);                rpos += 4;
    stun_write_u16(resp + 2, (uint16_t)(rpos - 20));

    sendto(sock->fd, resp, rpos, 0, from, from_len);

    king_rtp_peer_get(sock, from, from_len)->ice_consented = 1;
    return 1;
}

/* ── RTP parser ─────────────────────────────────────────────────────────── */

int king_rtp_parse(const uint8_t *buf, size_t len, king_rtp_packet_t *out)
{
    if (len < KING_RTP_HEADER_MIN || (buf[0] >> 6) != 2) return -1;
    int     cc      = buf[0] & 0x0F;
    int     has_ext = (buf[0] >> 4) & 1;
    size_t  hdr     = (size_t)KING_RTP_HEADER_MIN + (size_t)cc * 4;
    if (has_ext) {
        if (len < hdr + 4) return -1;
        uint16_t el = (uint16_t)(buf[hdr+2] << 8 | buf[hdr+3]);
        hdr += 4 + (size_t)el * 4;
    }
    if (len < hdr) return -1;

    out->payload_type = buf[1] & 0x7F;
    out->marker       = (buf[1] >> 7) & 1;
    out->seq          = (uint16_t)(buf[2] << 8 | buf[3]);
    out->timestamp    = stun_read_u32(buf + 4);
    out->ssrc         = stun_read_u32(buf + 8);

    size_t pl = len - hdr;
    if (pl > sizeof(out->payload)) pl = sizeof(out->payload);
    memcpy(out->payload, buf + hdr, pl);
    out->payload_len = pl;
    return 0;
}

/* ── DTLS accept + SRTP init ────────────────────────────────────────────── */

int king_rtp_dtls_do_accept(king_rtp_socket_t *sock,
                             const char *peer_ip, int peer_port,
                             int timeout_ms, char *errbuf, size_t errbuf_len)
{
    /* Resolve peer address */
    struct addrinfo hints = {0}, *res = NULL;
    char port_str[8];
    snprintf(port_str, sizeof(port_str), "%d", peer_port);
    hints.ai_family   = AF_UNSPEC;
    hints.ai_socktype = SOCK_DGRAM;
    if (getaddrinfo(peer_ip, port_str, &hints, &res) != 0 || !res) {
        snprintf(errbuf, errbuf_len, "getaddrinfo(%s): %s", peer_ip, strerror(errno));
        return -1;
    }

    /* Connected UDP socket for this peer */
    int pfd = socket(res->ai_family, SOCK_DGRAM, 0);
    if (pfd < 0) {
        freeaddrinfo(res);
        snprintf(errbuf, errbuf_len, "socket: %s", strerror(errno));
        return -1;
    }

    /* Bind to same local port so the client sees the same address */
    struct sockaddr_storage local_addr;
    socklen_t local_len = sizeof(local_addr);
    getsockname(sock->fd, (struct sockaddr *)&local_addr, &local_len);
    int opt = 1;
    setsockopt(pfd, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));
    bind(pfd, (struct sockaddr *)&local_addr, local_len);

    if (connect(pfd, res->ai_addr, res->ai_addrlen) != 0) {
        freeaddrinfo(res);
        close(pfd);
        snprintf(errbuf, errbuf_len, "connect: %s", strerror(errno));
        return -1;
    }
    freeaddrinfo(res);

    /* DTLS over the connected socket */
    SSL *ssl = SSL_new(sock->ssl_ctx);
    BIO *bio = BIO_new_dgram(pfd, BIO_NOCLOSE);

    struct timeval tv = { timeout_ms / 1000, (timeout_ms % 1000) * 1000 };
    BIO_ctrl(bio, BIO_CTRL_DGRAM_SET_RECV_TIMEOUT, 0, &tv);
    SSL_set_bio(ssl, bio, bio);
    SSL_set_accept_state(ssl);

    int ret, attempt = 0;
    while ((ret = SSL_accept(ssl)) <= 0 && attempt++ < 100) {
        int err = SSL_get_error(ssl, ret);
        if (err == SSL_ERROR_WANT_READ || err == SSL_ERROR_WANT_WRITE) continue;
        SSL_free(ssl);
        close(pfd);
        snprintf(errbuf, errbuf_len, "SSL_accept failed (err=%d)", err);
        return -1;
    }

    /* Extract DTLS-SRTP keying material (RFC 5764 §4.2) */
    unsigned char material[KING_SRTP_MATERIAL] = {0};
    if (SSL_export_keying_material(ssl, material, sizeof(material),
            "EXTRACTOR-dtls_srtp", 19, NULL, 0, 0) != 1) {
        SSL_free(ssl);
        close(pfd);
        snprintf(errbuf, errbuf_len, "key export failed");
        return -1;
    }

    /*
     * Layout: client_key[16] | server_key[16] | client_salt[14] | server_salt[14]
     * We are the server.  We send with server_key+salt, recv with client_key+salt.
     */
    unsigned char server_key [KING_SRTP_KEY_LEN  + KING_SRTP_SALT_LEN];
    unsigned char client_key [KING_SRTP_KEY_LEN  + KING_SRTP_SALT_LEN];
    memcpy(client_key,                  material,                           KING_SRTP_KEY_LEN);
    memcpy(server_key,                  material + KING_SRTP_KEY_LEN,       KING_SRTP_KEY_LEN);
    memcpy(client_key + KING_SRTP_KEY_LEN, material + 2*KING_SRTP_KEY_LEN, KING_SRTP_SALT_LEN);
    memcpy(server_key + KING_SRTP_KEY_LEN, material + 2*KING_SRTP_KEY_LEN + KING_SRTP_SALT_LEN,
           KING_SRTP_SALT_LEN);

    /* Store on peer entry */
    king_rtp_peer_t *peer = king_rtp_peer_by_addr_str(sock, peer_ip, peer_port);
    if (!peer) {
        /* Peer not yet in list — create entry from connected socket's remote */
        struct sockaddr_storage rem; socklen_t rlen = sizeof(rem);
        getpeername(pfd, (struct sockaddr *)&rem, &rlen);
        peer = king_rtp_peer_get(sock, (struct sockaddr *)&rem, rlen);
    }

    if (peer->peer_fd >= 0) close(peer->peer_fd);
    if (peer->ssl)         SSL_free(peer->ssl);
    peer->peer_fd   = pfd;
    peer->ssl       = ssl;
    peer->dtls_done = 1;
    peer->ice_consented = 1;

#ifdef KING_HAVE_SRTP
    srtp_policy_t policy_recv = {0}, policy_send = {0};

    srtp_crypto_policy_set_aes_cm_128_hmac_sha1_80(&policy_recv.rtp);
    srtp_crypto_policy_set_aes_cm_128_hmac_sha1_80(&policy_recv.rtcp);
    policy_recv.ssrc.type  = ssrc_any_inbound;
    policy_recv.key        = client_key;
    policy_recv.next       = NULL;

    srtp_crypto_policy_set_aes_cm_128_hmac_sha1_80(&policy_send.rtp);
    srtp_crypto_policy_set_aes_cm_128_hmac_sha1_80(&policy_send.rtcp);
    policy_send.ssrc.type  = ssrc_any_outbound;
    policy_send.key        = server_key;
    policy_send.next       = NULL;

    if (peer->srtp_ready) {
        srtp_dealloc(peer->srtp_recv);
        srtp_dealloc(peer->srtp_send);
    }
    srtp_create(&peer->srtp_recv, &policy_recv);
    srtp_create(&peer->srtp_send, &policy_send);
    peer->srtp_ready = 1;
#endif

    return 0;
}

/* ── PHP functions ──────────────────────────────────────────────────────── */

PHP_FUNCTION(king_rtp_bind)
{
    char *host; size_t hlen; zend_long port;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(host, hlen)
        Z_PARAM_LONG(port)
    ZEND_PARSE_PARAMETERS_END();

    char errbuf[256] = {0};
    king_rtp_socket_t *sock = king_rtp_socket_create(host, (int)port,
                                                      errbuf, sizeof(errbuf));
    if (!sock) {
        php_error_docref(NULL, E_WARNING, "king_rtp_bind: %s", errbuf);
        RETURN_FALSE;
    }
    RETURN_RES(zend_register_resource(sock, le_king_rtp_socket));
}

PHP_FUNCTION(king_rtp_ice_credentials)
{
    zval *z; ZEND_PARSE_PARAMETERS_START(1,1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    king_rtp_socket_t *s = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                               "King\\RtpSocket", le_king_rtp_socket);
    if (!s) RETURN_FALSE;
    array_init(return_value);
    add_assoc_string(return_value, "ufrag", s->ufrag);
    add_assoc_string(return_value, "pwd",   s->pwd);
}

/*
 * king_rtp_dtls_fingerprint(resource $socket): string
 * Returns "SHA-256 XX:XX:…" for the SDP a=fingerprint attribute.
 */
PHP_FUNCTION(king_rtp_dtls_fingerprint)
{
    zval *z; ZEND_PARSE_PARAMETERS_START(1,1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    king_rtp_socket_t *s = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                               "King\\RtpSocket", le_king_rtp_socket);
    if (!s) RETURN_FALSE;
    RETURN_STRING(s->fingerprint);
}

/*
 * king_rtp_dtls_accept(resource $socket, string $peer_ip, int $peer_port,
 *                      int $timeout_ms = 5000): bool
 *
 * Performs the DTLS handshake with the named peer, extracts SRTP keying
 * material and initialises libsrtp2 send/recv contexts.
 * After this call king_rtp_recv() / king_rtp_send() transparently en/decrypt.
 */
PHP_FUNCTION(king_rtp_dtls_accept)
{
    zval *z; char *ip; size_t iplen; zend_long port; zend_long tms = 5000;
    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_RESOURCE(z)
        Z_PARAM_STRING(ip, iplen)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(tms)
    ZEND_PARSE_PARAMETERS_END();

    king_rtp_socket_t *s = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                               "King\\RtpSocket", le_king_rtp_socket);
    if (!s) RETURN_FALSE;

    char errbuf[256] = {0};
    int rc = king_rtp_dtls_do_accept(s, ip, (int)port, (int)tms,
                                      errbuf, sizeof(errbuf));
    if (rc != 0) {
        php_error_docref(NULL, E_WARNING, "king_rtp_dtls_accept: %s", errbuf);
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

PHP_FUNCTION(king_rtp_recv)
{
    zval *z; zend_long tms = 0;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_RESOURCE(z)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(tms)
    ZEND_PARSE_PARAMETERS_END();

    king_rtp_socket_t *sock = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                                  "King\\RtpSocket", le_king_rtp_socket);
    if (!sock) RETURN_FALSE;

    uint8_t buf[KING_RTP_MTU + 16]; /* +16 for SRTP auth tag */
    struct sockaddr_storage from; socklen_t flen = sizeof(from);

    struct timeval deadline; gettimeofday(&deadline, NULL);
    deadline.tv_usec += (tms % 1000) * 1000;
    deadline.tv_sec  += tms / 1000 + deadline.tv_usec / 1000000;
    deadline.tv_usec %= 1000000;

    for (;;) {
        struct timeval now; gettimeofday(&now, NULL);
        long rem = (deadline.tv_sec - now.tv_sec) * 1000000
                 + (deadline.tv_usec - now.tv_usec);
        if (rem <= 0) RETURN_FALSE;

        fd_set fds; FD_ZERO(&fds); FD_SET(sock->fd, &fds);
        struct timeval tv = { rem / 1000000, rem % 1000000 };
        if (select(sock->fd + 1, &fds, NULL, NULL, &tv) <= 0) RETURN_FALSE;

        flen = sizeof(from);
        ssize_t n = recvfrom(sock->fd, buf, sizeof(buf), 0,
                             (struct sockaddr *)&from, &flen);
        if (n < 0) RETURN_FALSE;

        if (king_rtp_handle_stun(sock, buf, (size_t)n,
                                 (struct sockaddr *)&from, flen)) continue;

#ifdef KING_HAVE_SRTP
        king_rtp_peer_t *peer = king_rtp_peer_get(sock,
                                    (struct sockaddr *)&from, flen);
        if (peer->srtp_ready) {
            int pkt_len = (int)n;
            srtp_err_status_t st = srtp_unprotect(peer->srtp_recv, buf, &pkt_len);
            if (st != srtp_err_status_ok) continue; /* drop bad packet */
            n = (ssize_t)pkt_len;
        }
#endif

        king_rtp_packet_t pkt;
        if (king_rtp_parse(buf, (size_t)n, &pkt) != 0) continue;

        king_rtp_peer_t *peer2 = king_rtp_peer_get(sock,
                                     (struct sockaddr *)&from, flen);
        peer2->ssrc = pkt.ssrc;

        char ip[INET6_ADDRSTRLEN] = {0}; int rport = 0;
        if (from.ss_family == AF_INET) {
            struct sockaddr_in *s4 = (struct sockaddr_in *)&from;
            inet_ntop(AF_INET, &s4->sin_addr, ip, sizeof(ip));
            rport = ntohs(s4->sin_port);
        } else {
            struct sockaddr_in6 *s6 = (struct sockaddr_in6 *)&from;
            inet_ntop(AF_INET6, &s6->sin6_addr, ip, sizeof(ip));
            rport = ntohs(s6->sin6_port);
        }

        array_init(return_value);
        add_assoc_long(   return_value, "ssrc",          (zend_long)pkt.ssrc);
        add_assoc_long(   return_value, "payload_type",  pkt.payload_type);
        add_assoc_long(   return_value, "seq",           pkt.seq);
        add_assoc_long(   return_value, "timestamp",     (zend_long)pkt.timestamp);
        add_assoc_bool(   return_value, "marker",        pkt.marker);
        add_assoc_stringl(return_value, "data",
                          (char *)pkt.payload, pkt.payload_len);
        add_assoc_string( return_value, "from_ip",       ip);
        add_assoc_long(   return_value, "from_port",     rport);
        add_assoc_bool(   return_value, "ice_consented", peer2->ice_consented);
        add_assoc_bool(   return_value, "srtp",          peer2->dtls_done);
        return;
    }
}

PHP_FUNCTION(king_rtp_send)
{
    zval *z; char *host, *data; size_t hlen, dlen; zend_long port;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_RESOURCE(z)
        Z_PARAM_STRING(host, hlen)
        Z_PARAM_LONG(port)
        Z_PARAM_STRING(data, dlen)
    ZEND_PARSE_PARAMETERS_END();

    king_rtp_socket_t *sock = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                                  "King\\RtpSocket", le_king_rtp_socket);
    if (!sock) RETURN_FALSE;

    /* Find peer entry for optional SRTP */
    king_rtp_peer_t *peer = king_rtp_peer_by_addr_str(sock, host, (int)port);

    uint8_t buf[KING_RTP_MTU + 256];
    if (dlen > KING_RTP_MTU) dlen = KING_RTP_MTU;
    memcpy(buf, data, dlen);
    size_t out_len = dlen;

#ifdef KING_HAVE_SRTP
    if (peer && peer->srtp_ready) {
        int l = (int)out_len;
        srtp_err_status_t st = srtp_protect(peer->srtp_send, buf, &l);
        if (st != srtp_err_status_ok) RETURN_FALSE;
        out_len = (size_t)l;
    }
#endif

    struct addrinfo hints = {0}, *res = NULL;
    char ps[8]; snprintf(ps, sizeof(ps), "%ld", (long)port);
    hints.ai_family = AF_UNSPEC; hints.ai_socktype = SOCK_DGRAM;
    if (getaddrinfo(host, ps, &hints, &res) != 0 || !res) RETURN_FALSE;

    ssize_t sent = sendto(sock->fd, buf, out_len, 0,
                          res->ai_addr, res->ai_addrlen);
    freeaddrinfo(res);
    RETURN_BOOL(sent == (ssize_t)out_len);
}

PHP_FUNCTION(king_rtp_close)
{
    zval *z; ZEND_PARSE_PARAMETERS_START(1,1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    zend_list_close(Z_RES_P(z));
}
