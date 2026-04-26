// SPDX-License-Identifier: MIT
// Fast binary transport implementation (FILE backend only)

#include "voltron_transport.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/mman.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <arpa/inet.h>
#include <sys/socket.h>
#include <netdb.h>

// Simple CRC32C implementation (poly 0x1EDC6F41)
static uint32_t crc32c_table[256];
static void crc32c_init(void) {
    const uint32_t poly = 0x1EDC6F41u;
    for (uint32_t i = 0; i < 256; ++i) {
        uint32_t crc = i;
        for (int j = 0; j < 8; ++j) {
            crc = (crc & 1) ? (crc >> 1) ^ poly : (crc >> 1);
        }
        crc32c_table[i] = crc;
    }
}
static uint32_t crc32c_compute(const void *data, size_t size) {
    const uint8_t *p = (const uint8_t *)data;
    uint32_t crc = 0xFFFFFFFFu;
    for (size_t i = 0; i < size; ++i) {
        crc = crc32c_table[(crc ^ p[i]) & 0xFF] ^ (crc >> 8);
    }
    return crc ^ 0xFFFFFFFFu;
}

struct voltron_transport {
    enum voltron_backend backend;
    // FILE backend
    FILE *file;
    // TCP backend
    int sock;
    int conn;
    // SHM backend
    int shm_fd;
    void *shm_addr;
    size_t shm_size;
    char shm_path[256];
};

static void ensure_crc_table(void) {
    static int initialized = 0;
    if (!initialized) {
        crc32c_init();
        initialized = 1;
    }
}

voltron_transport* voltron_transport_init(enum voltron_backend backend) {
    voltron_transport *t = (voltron_transport *)calloc(1, sizeof(*t));
    if (!t) return NULL;
    t->backend = backend;
    t->file = NULL;
    t->sock = -1;
    t->conn = -1;
    t->shm_fd = -1;
    t->shm_addr = NULL;
    t->shm_size = 0;
    memset(t->shm_path, 0, sizeof(t->shm_path));
    return t;
}

void voltron_transport_destroy(voltron_transport* t) {
    if (!t) return;
    if (t->backend == VOLTRON_BACKEND_FILE && t->file) {
        fclose(t->file);
    }
    if (t->backend == VOLTRON_BACKEND_TCP) {
        if (t->conn != -1) close(t->conn);
        if (t->sock != -1) close(t->sock);
    }
    if (t->backend == VOLTRON_BACKEND_SHM) {
        if (t->shm_addr) munmap(t->shm_addr, t->shm_size);
        if (t->shm_fd != -1) close(t->shm_fd);
        if (t->shm_path[0]) shm_unlink(t->shm_path);
    }
    free(t);
}

static int write_full(FILE *f, const void *buf, size_t size) {
    const uint8_t *p = (const uint8_t *)buf;
    size_t written = 0;
    while (written < size) {
        size_t n = fwrite(p + written, 1, size - written, f);
        if (n == 0) return -1;
        written += n;
    }
    return 0;
}

static int read_full(FILE *f, void *buf, size_t size) {
    uint8_t *p = (uint8_t *)buf;
    size_t read = 0;
    while (read < size) {
        size_t n = fread(p + read, 1, size - read, f);
        if (n == 0) return -1;
        read += n;
    }
    return 0;
}

int voltron_send(voltron_transport* t, enum voltron_msg_type type, const void* data, size_t size) {
    if (!t) return -1;
    ensure_crc_table();
    voltron_frame_header hdr = {
        .magic = VOLTRON_MAGIC,
        .version = VOLTRON_VERSION,
        .msg_type = type,
        .payload_size = (uint32_t)size,
        .checksum = crc32c_compute(data, size),
        .magic_end = VOLTRON_MAGIC_END
    };
    if (t->backend == VOLTRON_BACKEND_FILE) {
        if (!t->file) return -1;
        if (write_full(t->file, &hdr, sizeof(hdr)) != 0) return -1;
        if (size > 0 && write_full(t->file, data, size) != 0) return -1;
        fflush(t->file);
        return 0;
    }
    // TODO: TCP and SHM backends not implemented yet
    return -1;
}

int voltron_recv(voltron_transport* t, enum voltron_msg_type* type, void** data, size_t* size) {
    if (!t) return -1;
    ensure_crc_table();
    if (t->backend == VOLTRON_BACKEND_FILE) {
        if (!t->file) return -1;
        voltron_frame_header hdr;
        if (read_full(t->file, &hdr, sizeof(hdr)) != 0) return -1;
        if (hdr.magic != VOLTRON_MAGIC || hdr.magic_end != VOLTRON_MAGIC_END) return -1;
        if (hdr.version != VOLTRON_VERSION) return -1;
        *type = (enum voltron_msg_type)hdr.msg_type;
        *size = hdr.payload_size;
        if (hdr.payload_size > 0) {
            *data = malloc(hdr.payload_size);
            if (!*data) return -1;
            if (read_full(t->file, *data, hdr.payload_size) != 0) {
                free(*data);
                return -1;
            }
            uint32_t chk = crc32c_compute(*data, hdr.payload_size);
            if (chk != hdr.checksum) {
                free(*data);
                return -1;
            }
        } else {
            *data = NULL;
        }
        return 0;
    }
    // TODO: TCP and SHM backends not implemented yet
    return -1;
}

int voltron_shm_create(voltron_transport* t, size_t size, voltron_shm_info* info) {
    if (!t || !info) return -1;
    if (t->backend != VOLTRON_BACKEND_SHM) return -1;
    // generate unique name
    snprintf(info->shm_path, sizeof(info->shm_path), "/voltron_%d_%ld", getpid(), random());
    int fd = shm_open(info->shm_path, O_CREAT | O_EXCL | O_RDWR, 0600);
    if (fd == -1) return -1;
    if (ftruncate(fd, size) != 0) { close(fd); shm_unlink(info->shm_path); return -1; }
    void *addr = mmap(NULL, size, PROT_READ | PROT_WRITE, MAP_SHARED, fd, 0);
    if (addr == MAP_FAILED) { close(fd); shm_unlink(info->shm_path); return -1; }
    t->shm_fd = fd;
    t->shm_addr = addr;
    t->shm_size = size;
    memcpy(info->shm_path, info->shm_path, sizeof(info->shm_path));
    info->size = size;
    info->fd = fd;
    return 0;
}

int voltron_shm_attach(voltron_transport* t, const voltron_shm_info* info, void** addr) {
    if (!t || !info || !addr) return -1;
    if (t->backend != VOLTRON_BACKEND_SHM) return -1;
    int fd = shm_open(info->shm_path, O_RDWR, 0);
    if (fd == -1) return -1;
    void *ptr = mmap(NULL, info->size, PROT_READ | PROT_WRITE, MAP_SHARED, fd, 0);
    if (ptr == MAP_FAILED) { close(fd); return -1; }
    t->shm_fd = fd;
    t->shm_addr = ptr;
    t->shm_size = info->size;
    *addr = ptr;
    return 0;
}

int voltron_shm_detach(voltron_transport* t, void* addr, const voltron_shm_info* info) {
    if (!t || !addr || !info) return -1;
    if (t->backend != VOLTRON_BACKEND_SHM) return -1;
    munmap(addr, info->size);
    close(t->shm_fd);
    shm_unlink(info->shm_path);
    t->shm_addr = NULL;
    t->shm_fd = -1;
    return 0;
}

int voltron_tcp_listen(voltron_transport* t, const char* host, uint16_t port) {
    if (!t) return -1;
    if (t->backend != VOLTRON_BACKEND_TCP) return -1;
    struct addrinfo hints = {0}, *res;
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    hints.ai_flags = AI_PASSIVE;
    char port_str[16];
    snprintf(port_str, sizeof(port_str), "%u", port);
    if (getaddrinfo(host, port_str, &hints, &res) != 0) return -1;
    int sock = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
    if (sock == -1) { freeaddrinfo(res); return -1; }
    int opt = 1;
    setsockopt(sock, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));
    if (bind(sock, res->ai_addr, res->ai_addrlen) != 0) { close(sock); freeaddrinfo(res); return -1; }
    freeaddrinfo(res);
    if (listen(sock, 1) != 0) { close(sock); return -1; }
    t->sock = sock;
    return 0;
}

int voltron_tcp_accept(voltron_transport* t) {
    if (!t) return -1;
    if (t->backend != VOLTRON_BACKEND_TCP) return -1;
    t->conn = accept(t->sock, NULL, NULL);
    return (t->conn != -1) ? 0 : -1;
}

int voltron_tcp_connect(voltron_transport* t, const char* host, uint16_t port) {
    if (!t) return -1;
    if (t->backend != VOLTRON_BACKEND_TCP) return -1;
    struct addrinfo hints = {0}, *res;
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    char port_str[16];
    snprintf(port_str, sizeof(port_str), "%u", port);
    if (getaddrinfo(host, port_str, &hints, &res) != 0) return -1;
    int sock = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
    if (sock == -1) { freeaddrinfo(res); return -1; }
    if (connect(sock, res->ai_addr, res->ai_addrlen) != 0) { close(sock); freeaddrinfo(res); return -1; }
    freeaddrinfo(res);
    t->conn = sock;
    return 0;
}

int voltron_file_open(voltron_transport* t, const char* path) {
    if (!t) return -1;
    if (t->backend != VOLTRON_BACKEND_FILE) return -1;
    FILE *f = fopen(path, "r+b");
    if (!f) f = fopen(path, "w+b");
    if (!f) return -1;
    t->file = f;
    return 0;
}

uint32_t voltron_crc32c(const void* data, size_t size) {
    ensure_crc_table();
    return crc32c_compute(data, size);
}
