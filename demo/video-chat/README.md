# King IIBIN WebSocket Stress Test Demo

A high-performance WebSocket stress testing application built with Vue.js 3 and the King IIBIN (Intelligent Indexed Binary) protocol, demonstrating real-time communication capabilities and protocol efficiency.

## 🚀 Features

### **WebSocket Stress Testing**
- **Configurable Load Testing**: Adjust duration, messages/second, and message size
- **Real-time Progress Monitoring**: Live progress bars and statistics
- **Comprehensive Results**: Detailed performance metrics including P95/P99 latencies
- **Error Tracking**: Monitor connection errors and message failures

### **IIBIN Binary Protocol**
- **Ultra-Efficient Encoding**: Up to 60% smaller than JSON
- **Type-Safe Messaging**: Strongly typed binary message structures
- **High-Performance Serialization**: Optimized for speed and bandwidth
- **Protocol Comparison**: Real-time efficiency comparison with JSON

### **Real-time Chat Interface**
- **Binary Message Transport**: All messages use IIBIN protocol
- **Live Performance Metrics**: Connection stats, latency, throughput
- **Protocol Efficiency Visualization**: Visual comparison charts
- **Connection Management**: Auto-reconnection and health monitoring

## 🛠 Technology Stack

- **Frontend**: Vue.js 3 with Composition API + TypeScript
- **Protocol**: IIBIN (Intelligent Indexed Binary) - Custom binary protocol
- **WebSocket**: King WebSocket extension with binary transport
- **Build Tool**: Vite for fast development and building
- **Styling**: Modern CSS with gradients and glassmorphism effects

## 📊 Performance Capabilities

### **Stress Test Metrics**
- **Throughput**: Up to 1000+ messages/second
- **Message Sizes**: 1 byte to 64KB per message
- **Duration**: Configurable test duration (1-300 seconds)
- **Latency Tracking**: Average, P95, P99 latency measurements
- **Error Monitoring**: Real-time error rate tracking

### **Protocol Efficiency**
- **Binary Encoding**: Compact message representation
- **Header Optimization**: Minimal overhead (24-byte header)
- **Type-Specific Serialization**: Optimized for different data types
- **Compression Ready**: Built-in support for payload compression

## 🚀 Getting Started

### Prerequisites

- Node.js 18+
- King WebSocket server with IIBIN support
- Modern web browser

### Installation

1. **Clone and install dependencies:**
   ```bash
   cd king/demo/video-chat
   npm install
   ```

2. **Start development server:**
   ```bash
   npm run dev
   ```

3. **Open browser:**
   Navigate to `http://localhost:5173`

### Configuration

Update the WebSocket URL to point to your King server:

```typescript
const websocketUrl = ref('ws://localhost:8080')
```

## 🧪 Running Stress Tests

### **Basic Stress Test**
1. Connect to WebSocket server
2. Configure test parameters:
   - **Duration**: 30 seconds (recommended)
   - **Messages/Second**: 100 (start conservative)
   - **Message Size**: 1024 bytes (1KB)
3. Click "Start Stress Test"
4. Monitor real-time progress and results

### **Advanced Testing Scenarios**

**High Throughput Test:**
- Duration: 60 seconds
- Messages/Second: 500
- Message Size: 512 bytes

**Large Message Test:**
- Duration: 30 seconds  
- Messages/Second: 50
- Message Size: 32KB

**Endurance Test:**
- Duration: 300 seconds (5 minutes)
- Messages/Second: 200
- Message Size: 2KB

## 📈 Understanding Results

### **Key Metrics**

- **Messages/Second**: Actual throughput achieved
- **Bytes/Second**: Bandwidth utilization
- **Average Latency**: Mean round-trip time
- **P95 Latency**: 95th percentile latency (most users experience)
- **P99 Latency**: 99th percentile latency (worst case)
- **Error Rate**: Percentage of failed messages

### **Performance Targets**

- **Good**: >100 msg/sec, <50ms avg latency, <1% errors
- **Excellent**: >500 msg/sec, <20ms avg latency, <0.1% errors
- **Outstanding**: >1000 msg/sec, <10ms avg latency, <0.01% errors

## 🔬 IIBIN Protocol Details

### **Message Structure**
```
Header (24 bytes):
- Magic: "IIB" + Version (4 bytes)
- Message Type (1 byte)
- Flags (1 byte)
- Reserved (1 byte)
- Payload Length (4 bytes)
- Timestamp (8 bytes)
- Message ID (8 bytes)

Payload (variable):
- Type-specific binary data
```

### **Supported Message Types**
- `TEXT_MESSAGE`: Chat messages
- `STRESS_TEST_DATA`: Load testing payloads
- `PING/PONG`: Connection health checks
- `USER_STATUS`: Presence information
- `MEDIA_MESSAGE`: Binary media content

### **Efficiency Comparison**

| Data Type | JSON Size | IIBIN Size | Savings |
|-----------|-----------|------------|---------|
| Text Message | 156 bytes | 89 bytes | 43% |
| User Status | 98 bytes | 52 bytes | 47% |
| Media Info | 234 bytes | 127 bytes | 46% |

## 🎯 Use Cases

### **Development & Testing**
- **WebSocket Server Testing**: Validate server performance
- **Protocol Benchmarking**: Compare different message formats
- **Load Testing**: Simulate high-traffic scenarios
- **Latency Analysis**: Identify performance bottlenecks

### **Production Monitoring**
- **Real-time Metrics**: Monitor live application performance
- **Capacity Planning**: Determine server scaling requirements
- **Protocol Optimization**: Validate binary protocol benefits
- **Quality Assurance**: Continuous performance validation

## 🔧 Development

### **Project Structure**
```
src/
├── lib/
│   ├── iibin-protocol.ts    # Core IIBIN protocol implementation
│   └── iibin.ts             # WebSocket client with stress testing
├── App.vue                  # Main application component
└── main.ts                  # Application entry point
```

### **Building for Production**
```bash
npm run build
```

### **Type Checking**
```bash
npm run type-check
```

## 🌐 Browser Support

- **Chrome 88+**: Full support
- **Firefox 85+**: Full support  
- **Safari 14+**: Full support
- **Edge 88+**: Full support

## 📝 License

This demo is part of the King WebSocket extension project and follows the same license terms.

---

**🚀 Ready to stress test your WebSocket server with the power of binary protocols!**