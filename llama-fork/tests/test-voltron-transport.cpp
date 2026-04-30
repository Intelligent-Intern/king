#include "voltron_transport.h"
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <iostream>
#include <unistd.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>

// Helper function to test TCP transport
int test_tcp_transport() {
    const char *host = "127.0.0.1";
    const uint16_t port = 12345;
    
    // Start listener in a separate process (simplified for test)
    pid_t pid = fork();
    if (pid == -1) {
        std::cerr << "Fork failed" << std::endl;
        return -1;
    }
    
    if (pid == 0) {
        // Child process - listener
        voltron_transport *listener = voltron_transport_init(VOLTRON_BACKEND_TCP);
        if (!listener) {
            std::cerr << "Failed to init listener transport" << std::endl;
            exit(1);
        }
        if (voltron_tcp_listen(listener, host, port) != 0) {
            std::cerr << "Failed to listen on TCP" << std::endl;
            voltron_transport_destroy(listener);
            exit(1);
        }
        if (voltron_tcp_accept(listener) != 0) {
            std::cerr << "Failed to accept TCP connection" << std::endl;
            voltron_transport_destroy(listener);
            exit(1);
        }
        
        // Receive message
        enum voltron_msg_type type;
        void *data = nullptr;
        size_t size = 0;
        if (voltron_recv(listener, &type, &data, &size) != 0) {
            std::cerr << "TCP recv failed" << std::endl;
            voltron_transport_destroy(listener);
            exit(1);
        }
        
        if (type != VOLTRON_MSG_KV_CACHE) {
            std::cerr << "Unexpected message type" << std::endl;
            std::free(data);
            voltron_transport_destroy(listener);
            exit(1);
        }
        
        const char *expected_msg = "hello world tcp";
        size_t expected_len = std::strlen(expected_msg);
        if (size != expected_len || std::memcmp(data, expected_msg, size) != 0) {
            std::cerr << "TCP data mismatch" << std::endl;
            std::free(data);
            voltron_transport_destroy(listener);
            exit(1);
        }
        
        std::free(data);
        voltron_transport_destroy(listener);
        exit(0);
    } else {
        // Parent process - sender
        sleep(1); // Give child time to set up listener
        
        voltron_transport *sender = voltron_transport_init(VOLTRON_BACKEND_TCP);
        if (!sender) {
            std::cerr << "Failed to init sender transport" << std::endl;
            return -1;
        }
        if (voltron_tcp_connect(sender, host, port) != 0) {
            std::cerr << "Failed to connect to TCP" << std::endl;
            voltron_transport_destroy(sender);
            return -1;
        }
        
        const char *msg = "hello world tcp";
        size_t msg_len = std::strlen(msg);
        if (voltron_send(sender, VOLTRON_MSG_KV_CACHE, msg, msg_len) != 0) {
            std::cerr << "TCP send failed" << std::endl;
            voltron_transport_destroy(sender);
            return -1;
        }
        
        voltron_transport_destroy(sender);
        
        // Wait for child to complete
        int status;
        waitpid(pid, &status, 0);
        if (WIFEXITED(status) && WEXITSTATUS(status) == 0) {
            return 0;
        } else {
            std::cerr << "TCP test child process failed" << std::endl;
            return -1;
        }
    }
}

// Helper function to test SHM transport
int test_shm_transport() {
    // For simplicity in this test, we'll just test the SHM creation/attachment functions
    // A full SHM transport test would require more complex synchronization
    
    voltron_transport *t = voltron_transport_init(VOLTRON_BACKEND_SHM);
    if (!t) {
        std::cerr << "Failed to init SHM transport" << std::endl;
        return -1;
    }
    
    voltron_shm_info info;
    if (voltron_shm_create(t, 1024, &info) != 0) {
        std::cerr << "Failed to create SHM" << std::endl;
        voltron_transport_destroy(t);
        return -1;
    }
    
    void *addr = nullptr;
    if (voltron_shm_attach(t, &info, &addr) != 0) {
        std::cerr << "Failed to attach to SHM" << std::endl;
        voltron_transport_destroy(t);
        return -1;
    }
    
    // Write some test data
    const char *test_data = "hello world shm";
    std::memcpy(addr, test_data, std::strlen(test_data) + 1);
    
    voltron_shm_detach(t, addr, &info);
    voltron_transport_destroy(t);
    
    return 0;
}

int main() {
    // Test FILE backend (existing test)
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
        std::free(data);
        voltron_transport_destroy(r);
        return 1;
    }
    std::free(data);
    voltron_transport_destroy(r);
    std::remove(path);
    
    // Test TCP backend
    if (test_tcp_transport() != 0) {
        std::cerr << "TCP transport test failed" << std::endl;
        return 1;
    }
    
    // Test SHM backend
    if (test_shm_transport() != 0) {
        std::cerr << "SHM transport test failed" << std::endl;
        return 1;
    }
    
    std::cout << "All transport tests passed!" << std::endl;
    return 0;
}
