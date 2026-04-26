/*
 * voltron_transport.h - Fast binary transport for KV cache transfer
 * Zero-copy via shared memory, TCP binary frames for network
 */

#ifndef VOLTRON_TRANSPORT_H
#define VOLTRON_TRANSPORT_H

#include <stdint.h>
#include <stddef.h>
#include <stdbool.h>

#ifdef __cplusplus
extern "C" {
#endif

/* Protocol magic for validation */
#define VOLTRON_MAGIC       0x564F4C54  /* "VOLT" */
#define VOLTRON_MAGIC_END    0x53454C46  /* "SELF" */

/* Version */
#define VOLTRON_VERSION     1

/* Message types */
enum voltron_msg_type {
    VOLTRON_MSG_KV_CACHE    = 1,  /* KV cache data */
    VOLTRON_MSG_ACK         = 2,  /* Acknowledgment */
    VOLTRON_MSG_NACK        = 3,  /* Negative acknowledgment */
    VOLTRON_MSG_SHM_ATTACH  = 4,  /* Shared memory attachment request */
    VOLTRON_MSG_SHM_INFO    = 5,  /* Shared memory info (path/size) */
};

/* Transport backend */
enum voltron_backend {
    VOLTRON_BACKEND_FILE    = 0,  /* File-based (slow) */
    VOLTRON_BACKEND_SHM     = 1,  /* Shared memory (zero-copy) */
    VOLTRON_BACKEND_TCP     = 2,  /* TCP binary frames */
};

/* Frame header (16 bytes) */
typedef struct __attribute__((packed)) {
    uint32_t magic;           /* VOLTRON_MAGIC */
    uint16_t version;        /* Protocol version */
    uint16_t msg_type;       /* Message type */
    uint32_t payload_size;    /* Payload size in bytes */
    uint32_t checksum;       /* CRC32C of payload */
    uint32_t magic_end;      /* VOLTRON_MAGIC_END */
} voltron_frame_header;

/* Shared memory info */
typedef struct {
    char shm_path[256];      /* POSIX shared memory path */
    size_t size;             /* Size of shared memory region */
    int fd;                   /* File descriptor (for cleanup) */
} voltron_shm_info;

/* TCP connection info */
typedef struct {
    char host[256];          /* Hostname or IP */
    uint16_t port;          /* Port number */
} voltron_tcp_info;

/*
 * Transport context - opaque handle
 */
typedef struct voltron_transport voltron_transport;

/*
 * Initialize transport
 */
voltron_transport* voltron_transport_init(enum voltron_backend backend);

/*
 * Destroy transport
 */
void voltron_transport_destroy(voltron_transport* t);

/*
 * Send data (file or TCP)
 */
int voltron_send(voltron_transport* t, enum voltron_msg_type type,
                 const void* data, size_t size);

/*
 * Receive data (file or TCP)
 */
int voltron_recv(voltron_transport* t, enum voltron_msg_type* type,
                 void** data, size_t* size);

/*
 * Shared memory: create and send shm info to peer
 */
int voltron_shm_create(voltron_transport* t, size_t size,
                        voltron_shm_info* info);

/*
 * Shared memory: attach to existing shm
 */
int voltron_shm_attach(voltron_transport* t, const voltron_shm_info* info,
                       void** addr);

/*
 * Shared memory: detach and cleanup
 */
int voltron_shm_detach(voltron_transport* t, void* addr, const voltron_shm_info* info);

/*
 * TCP: listen for connections
 */
int voltron_tcp_listen(voltron_transport* t, const char* host, uint16_t port);

/*
 * TCP: accept connection
 */
int voltron_tcp_accept(voltron_transport* t);

/*
 * TCP: connect to peer
 */
int voltron_tcp_connect(voltron_transport* t, const char* host, uint16_t port);

/*
 * File: open for read/write
 */
int voltron_file_open(voltron_transport* t, const char* path);

/*
 * CRC32C checksum (hardware accelerated on modern CPUs)
 */
uint32_t voltron_crc32c(const void* data, size_t size);

#ifdef __cplusplus
}
#endif

#endif /* VOLTRON_TRANSPORT_H */