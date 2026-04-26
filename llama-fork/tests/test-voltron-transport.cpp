#include "voltron_transport.h"
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <iostream>

int main() {
    const char *path = "/tmp/voltron_test.bin";
    // Ensure any previous file is removed
    std::remove(path);

    // Initialize transport for FILE backend
    voltron_transport *t = voltron_transport_init(VOLTRON_BACKEND_FILE);
    if (!t) {
        std::cerr << "Failed to init transport" << std::endl;
        return 1;
    }
    if (voltron_file_open(t, path) != 0) {
        std::cerr << "Failed to open file" << std::endl;
        return 1;
    }

    const char *msg = "hello world";
    size_t msg_len = std::strlen(msg);
    if (voltron_send(t, VOLTRON_MSG_KV_CACHE, msg, msg_len) != 0) {
        std::cerr << "Send failed" << std::endl;
        return 1;
    }
    voltron_transport_destroy(t);

    // Re-open for receiving
    voltron_transport *r = voltron_transport_init(VOLTRON_BACKEND_FILE);
    if (!r) {
        std::cerr << "Failed to init recv transport" << std::endl;
        return 1;
    }
    if (voltron_file_open(r, path) != 0) {
        std::cerr << "Failed to open file for recv" << std::endl;
        return 1;
    }

    enum voltron_msg_type type;
    void *data = nullptr;
    size_t size = 0;
    if (voltron_recv(r, &type, &data, &size) != 0) {
        std::cerr << "Recv failed" << std::endl;
        return 1;
    }
    if (type != VOLTRON_MSG_KV_CACHE) {
        std::cerr << "Unexpected message type" << std::endl;
        return 1;
    }
    if (size != msg_len || std::memcmp(data, msg, size) != 0) {
        std::cerr << "Data mismatch" << std::endl;
        return 1;
    }
    std::free(data);
    voltron_transport_destroy(r);
    std::remove(path);
    return 0;
}
