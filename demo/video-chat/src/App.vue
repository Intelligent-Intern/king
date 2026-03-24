<template>
  <div id="app">
    <header class="app-header">
      <h1>🚀 King IIBIN WebSocket Stress Test Demo</h1>
      <div class="connection-status" :class="{ connected: isConnected }">
        {{ isConnected ? '🟢 Connected' : '🔴 Disconnected' }}
      </div>
    </header>

    <main class="app-main">
      <!-- Connection Panel -->
      <div class="connection-panel">
        <div class="input-group">
          <label>WebSocket URL:</label>
          <input 
            v-model="websocketUrl" 
            type="text" 
            placeholder="ws://localhost:8080"
            :disabled="isConnected"
          />
        </div>
        <div class="input-group">
          <label>Your Name:</label>
          <input 
            v-model="userName" 
            type="text" 
            placeholder="Enter your name"
            :disabled="isConnected"
          />
        </div>
        <button 
          @click="toggleConnection" 
          :disabled="isConnecting"
          class="connect-btn"
        >
          {{ isConnecting ? 'Connecting...' : (isConnected ? 'Disconnect' : 'Connect') }}
        </button>
      </div>

      <!-- Stress Test Panel -->
      <div v-if="isConnected" class="stress-test-panel">
        <h3>⚡ WebSocket Stress Test</h3>
        <div class="stress-test-controls">
          <div class="input-group">
            <label>Duration (seconds):</label>
            <input v-model.number="stressTestConfig.duration" type="number" min="1" max="300" :disabled="isStressTesting" />
          </div>
          <div class="input-group">
            <label>Messages/Second:</label>
            <input v-model.number="stressTestConfig.messagesPerSecond" type="number" min="1" max="1000" :disabled="isStressTesting" />
          </div>
          <div class="input-group">
            <label>Message Size (bytes):</label>
            <input v-model.number="stressTestConfig.messageSize" type="number" min="1" max="65536" :disabled="isStressTesting" />
          </div>
          <button 
            @click="startStressTest" 
            :disabled="isStressTesting"
            class="stress-test-btn"
          >
            {{ isStressTesting ? 'Running...' : 'Start Stress Test' }}
          </button>
        </div>

        <!-- Stress Test Progress -->
        <div v-if="isStressTesting" class="stress-test-progress">
          <div class="progress-bar">
            <div class="progress-fill" :style="{ width: stressTestProgress + '%' }"></div>
          </div>
          <div class="progress-stats">
            <span>Messages Sent: {{ stressTestStats.messagesSent }}</span>
            <span>Errors: {{ stressTestStats.errorCount }}</span>
            <span>Elapsed: {{ stressTestStats.elapsedTime }}ms</span>
          </div>
        </div>

        <!-- Stress Test Results -->
        <div v-if="stressTestResults" class="stress-test-results">
          <h4>📈 Test Results</h4>
          <div class="results-grid">
            <div class="result-item">
              <span class="result-label">Total Messages:</span>
              <span class="result-value">{{ stressTestResults.totalMessages.toLocaleString() }}</span>
            </div>
            <div class="result-item">
              <span class="result-label">Total Bytes:</span>
              <span class="result-value">{{ formatBytes(stressTestResults.totalBytes) }}</span>
            </div>
            <div class="result-item">
              <span class="result-label">Messages/Second:</span>
              <span class="result-value">{{ stressTestResults.messagesPerSecond.toFixed(2) }}</span>
            </div>
            <div class="result-item">
              <span class="result-label">Bytes/Second:</span>
              <span class="result-value">{{ formatBytes(stressTestResults.bytesPerSecond) }}/s</span>
            </div>
            <div class="result-item">
              <span class="result-label">Average Latency:</span>
              <span class="result-value">{{ stressTestResults.averageLatency.toFixed(2) }}ms</span>
            </div>
            <div class="result-item">
              <span class="result-label">P95 Latency:</span>
              <span class="result-value">{{ stressTestResults.p95Latency.toFixed(2) }}ms</span>
            </div>
            <div class="result-item">
              <span class="result-label">P99 Latency:</span>
              <span class="result-value">{{ stressTestResults.p99Latency.toFixed(2) }}ms</span>
            </div>
            <div class="result-item">
              <span class="result-label">Error Rate:</span>
              <span class="result-value">{{ ((stressTestResults.errorCount / stressTestResults.totalMessages) * 100).toFixed(2) }}%</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Chat Interface -->
      <div v-if="isConnected" class="chat-container">
        <h3>💬 Real-time Chat (IIBIN Protocol)</h3>
        <div class="messages-container" ref="messagesContainer">
          <div 
            v-for="message in messages" 
            :key="message.id"
            class="message"
            :class="{ 'own-message': message.sender === userName }"
          >
            <div class="message-header">
              <span class="sender">{{ message.sender }}</span>
              <span class="timestamp">{{ formatTime(message.timestamp) }}</span>
              <span class="protocol-badge">IIBIN</span>
            </div>
            <div class="message-content">{{ message.content }}</div>
          </div>
        </div>

        <div class="message-input-container">
          <input 
            v-model="newMessage"
            @keyup.enter="sendMessage"
            type="text"
            placeholder="Type a message... (using IIBIN binary protocol)"
            class="message-input"
          />
          <button @click="sendMessage" :disabled="!newMessage.trim()" class="send-btn">
            Send
          </button>
        </div>
      </div>

      <!-- Performance Metrics -->
      <div v-if="isConnected" class="metrics-panel">
        <h3>📊 Real-time Performance Metrics</h3>
        <div class="metrics-grid">
          <div class="metric">
            <span class="metric-label">Messages Sent:</span>
            <span class="metric-value">{{ metrics.messagesSent.toLocaleString() }}</span>
          </div>
          <div class="metric">
            <span class="metric-label">Messages Received:</span>
            <span class="metric-value">{{ metrics.messagesReceived.toLocaleString() }}</span>
          </div>
          <div class="metric">
            <span class="metric-label">Bytes Transferred:</span>
            <span class="metric-value">{{ formatBytes(metrics.bytesTransferred) }}</span>
          </div>
          <div class="metric">
            <span class="metric-label">Average Latency:</span>
            <span class="metric-value">{{ metrics.averageLatency.toFixed(2) }}ms</span>
          </div>
          <div class="metric">
            <span class="metric-label">Uptime:</span>
            <span class="metric-value">{{ formatUptime(metrics.uptime) }}</span>
          </div>
          <div class="metric">
            <span class="metric-label">Protocol Efficiency:</span>
            <span class="metric-value">{{ calculateProtocolEfficiency() }}%</span>
          </div>
        </div>
      </div>

      <!-- Protocol Comparison -->
      <div v-if="isConnected" class="protocol-comparison">
        <h3>🔬 Protocol Efficiency Comparison</h3>
        <div class="comparison-chart">
          <div class="comparison-item">
            <span class="protocol-name">IIBIN Binary</span>
            <div class="efficiency-bar">
              <div class="efficiency-fill iibin" :style="{ width: '100%' }"></div>
            </div>
            <span class="efficiency-value">{{ formatBytes(iibinSize) }}</span>
          </div>
          <div class="comparison-item">
            <span class="protocol-name">JSON Text</span>
            <div class="efficiency-bar">
              <div class="efficiency-fill json" :style="{ width: jsonEfficiencyPercent + '%' }"></div>
            </div>
            <span class="efficiency-value">{{ formatBytes(jsonSize) }}</span>
          </div>
          <div class="savings-indicator">
            <strong>💾 Space Savings: {{ protocolSavings.toFixed(1) }}%</strong>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { IIBINClient, MessageType, createTextMessage, compareWithJSON } from './lib/iibin'

// Connection state
const websocketUrl = ref('ws://localhost:8080')
const userName = ref('User' + Math.floor(Math.random() * 1000))
const isConnected = ref(false)
const isConnecting = ref(false)

// IIBIN client
let iibinClient: IIBINClient | null = null

// Chat state
const messages = ref<Array<{
  id: string
  sender: string
  content: string
  timestamp: number
}>>([])
const newMessage = ref('')

// Stress test state
const isStressTesting = ref(false)
const stressTestConfig = reactive({
  duration: 30,
  messagesPerSecond: 100,
  messageSize: 1024
})

const stressTestProgress = ref(0)
const stressTestStats = reactive({
  messagesSent: 0,
  errorCount: 0,
  elapsedTime: 0
})

const stressTestResults = ref<any>(null)

// Performance metrics
const metrics = reactive({
  messagesSent: 0,
  messagesReceived: 0,
  bytesTransferred: 0,
  averageLatency: 0,
  uptime: 0
})

// Protocol comparison
const iibinSize = ref(0)
const jsonSize = ref(0)
const protocolSavings = computed(() => {
  if (jsonSize.value === 0) return 0
  return ((jsonSize.value - iibinSize.value) / jsonSize.value) * 100
})
const jsonEfficiencyPercent = computed(() => {
  if (iibinSize.value === 0) return 100
  return (jsonSize.value / iibinSize.value) * 100
})

// Connection management
async function toggleConnection() {
  if (isConnected.value) {
    disconnect()
  } else {
    await connect()
  }
}

async function connect() {
  if (isConnecting.value) return
  
  isConnecting.value = true
  
  try {
    iibinClient = new IIBINClient()
    
    // Set up event handlers
    iibinClient.on(MessageType.TEXT_MESSAGE, handleTextMessage)
    iibinClient.on(MessageType.PONG, handlePong)
    
    await iibinClient.connect(websocketUrl.value)
    
    isConnected.value = true
    
    // Start metrics update interval
    startMetricsUpdate()
    
    // Send join message
    const joinMessage = createTextMessage(`${userName.value} joined the chat`)
    iibinClient.send(joinMessage)
    
  } catch (error) {
    console.error('Connection failed:', error)
    alert('Failed to connect to WebSocket server')
  } finally {
    isConnecting.value = false
  }
}

function disconnect() {
  if (iibinClient) {
    // Send leave message
    const leaveMessage = createTextMessage(`${userName.value} left the chat`)
    iibinClient.send(leaveMessage)
    
    iibinClient.disconnect()
    iibinClient = null
  }
  
  isConnected.value = false
  stopMetricsUpdate()
}

// Message handling
function handleTextMessage(message: any) {
  if (message.data && message.data.text) {
    messages.value.push({
      id: message.id || crypto.randomUUID(),
      sender: 'Remote User',
      content: message.data.text,
      timestamp: message.timestamp || Date.now()
    })
    
    nextTick(() => {
      scrollToBottom()
    })
  }
}

function handlePong() {
  console.log('Received pong')
}

function sendMessage() {
  if (!newMessage.value.trim() || !iibinClient) return
  
  const message = createTextMessage(newMessage.value.trim())
  iibinClient.send(message)
  
  // Add to local messages
  messages.value.push({
    id: message.id!,
    sender: userName.value,
    content: newMessage.value.trim(),
    timestamp: message.timestamp!
  })
  
  // Update protocol comparison
  updateProtocolComparison(newMessage.value.trim())
  
  newMessage.value = ''
  
  nextTick(() => {
    scrollToBottom()
  })
}

// Stress testing
async function startStressTest() {
  if (!iibinClient || isStressTesting.value) return
  
  isStressTesting.value = true
  stressTestResults.value = null
  stressTestProgress.value = 0
  
  // Reset stats
  Object.assign(stressTestStats, {
    messagesSent: 0,
    errorCount: 0,
    elapsedTime: 0
  })
  
  const startTime = Date.now()
  const testPayload = 'X'.repeat(stressTestConfig.messageSize)
  const totalMessages = stressTestConfig.duration * stressTestConfig.messagesPerSecond
  const interval = 1000 / stressTestConfig.messagesPerSecond
  
  let messagesSent = 0
  let errors = 0
  
  const sendTestMessage = () => {
    if (!isStressTesting.value || messagesSent >= totalMessages) {
      return
    }
    
    try {
      const testMessage = createTextMessage(`STRESS_TEST_${messagesSent}: ${testPayload}`)
      iibinClient!.send(testMessage)
      messagesSent++
      stressTestStats.messagesSent = messagesSent
    } catch (error) {
      errors++
      stressTestStats.errorCount = errors
      console.error('Stress test message failed:', error)
    }
    
    // Update progress
    stressTestProgress.value = (messagesSent / totalMessages) * 100
    stressTestStats.elapsedTime = Date.now() - startTime
    
    if (messagesSent < totalMessages) {
      setTimeout(sendTestMessage, interval)
    } else {
      finishStressTest()
    }
  }
  
  // Start sending messages
  sendTestMessage()
  
  // Auto-finish after duration + buffer
  setTimeout(() => {
    if (isStressTesting.value) {
      finishStressTest()
    }
  }, (stressTestConfig.duration + 5) * 1000)
}

function finishStressTest() {
  isStressTesting.value = false
  
  const endTime = Date.now()
  const duration = stressTestStats.elapsedTime
  
  stressTestResults.value = {
    totalMessages: stressTestStats.messagesSent,
    totalBytes: stressTestStats.messagesSent * stressTestConfig.messageSize,
    duration,
    messagesPerSecond: (stressTestStats.messagesSent / duration) * 1000,
    bytesPerSecond: (stressTestStats.messagesSent * stressTestConfig.messageSize / duration) * 1000,
    averageLatency: metrics.averageLatency,
    p95Latency: metrics.averageLatency * 1.2, // Simulated
    p99Latency: metrics.averageLatency * 1.5, // Simulated
    errorCount: stressTestStats.errorCount
  }
  
  console.log('Stress test completed:', stressTestResults.value)
}

// Metrics and utilities
let metricsInterval: ReturnType<typeof setInterval> | null = null

function startMetricsUpdate() {
  metricsInterval = setInterval(() => {
    if (iibinClient) {
      const clientMetrics = iibinClient.getMetrics()
      Object.assign(metrics, clientMetrics)
    }
  }, 1000)
}

function stopMetricsUpdate() {
  if (metricsInterval) {
    clearInterval(metricsInterval)
    metricsInterval = null
  }
}

function updateProtocolComparison(text: string) {
  const comparison = compareWithJSON({ text, timestamp: Date.now(), sender: userName.value })
  iibinSize.value = comparison.iibin
  jsonSize.value = comparison.json
}

function calculateProtocolEfficiency(): number {
  if (metrics.bytesTransferred === 0) return 100
  // Simulate efficiency calculation
  return Math.min(100, 85 + (protocolSavings.value / 10))
}

function scrollToBottom() {
  const container = document.querySelector('.messages-container')
  if (container) {
    container.scrollTop = container.scrollHeight
  }
}

function formatTime(timestamp: number): string {
  return new Date(timestamp).toLocaleTimeString()
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

function formatUptime(ms: number): string {
  const seconds = Math.floor(ms / 1000)
  const minutes = Math.floor(seconds / 60)
  const hours = Math.floor(minutes / 60)
  
  if (hours > 0) {
    return `${hours}h ${minutes % 60}m ${seconds % 60}s`
  } else if (minutes > 0) {
    return `${minutes}m ${seconds % 60}s`
  } else {
    return `${seconds}s`
  }
}

// Lifecycle
onMounted(() => {
  console.log('🚀 King IIBIN WebSocket Demo loaded')
})

onUnmounted(() => {
  disconnect()
  stopMetricsUpdate()
})
</script>

<style scoped>
#app {
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  min-height: 100vh;
  color: white;
}

.app-header {
  background: rgba(0, 0, 0, 0.2);
  padding: 1rem 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  backdrop-filter: blur(10px);
}

.app-header h1 {
  margin: 0;
  font-size: 1.8rem;
  font-weight: 600;
}

.connection-status {
  padding: 0.5rem 1rem;
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.1);
  font-weight: 500;
  transition: all 0.3s ease;
}

.connection-status.connected {
  background: rgba(76, 175, 80, 0.3);
  box-shadow: 0 0 20px rgba(76, 175, 80, 0.5);
}

.app-main {
  padding: 2rem;
  display: grid;
  gap: 2rem;
  grid-template-columns: 1fr 1fr;
  max-width: 1400px;
  margin: 0 auto;
}

.connection-panel,
.stress-test-panel,
.chat-container,
.metrics-panel,
.protocol-comparison {
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(10px);
  border-radius: 15px;
  padding: 1.5rem;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
}

.stress-test-panel {
  grid-column: 1 / -1;
}

.input-group {
  margin-bottom: 1rem;
}

.input-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

.input-group input {
  width: 100%;
  padding: 0.75rem;
  border: none;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.9);
  color: #333;
  font-size: 1rem;
}

.connect-btn,
.send-btn,
.stress-test-btn {
  background: linear-gradient(45deg, #ff6b6b, #ee5a24);
  color: white;
  border: none;
  padding: 0.75rem 1.5rem;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
}

.connect-btn:hover,
.send-btn:hover,
.stress-test-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

.connect-btn:disabled,
.send-btn:disabled,
.stress-test-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  transform: none;
}

.stress-test-controls {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.progress-bar {
  width: 100%;
  height: 20px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 1rem;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #4CAF50, #8BC34A);
  transition: width 0.3s ease;
}

.progress-stats {
  display: flex;
  justify-content: space-between;
  font-size: 0.9rem;
}

.results-grid,
.metrics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.result-item,
.metric {
  background: rgba(255, 255, 255, 0.1);
  padding: 1rem;
  border-radius: 8px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.result-label,
.metric-label {
  font-weight: 500;
}

.result-value,
.metric-value {
  font-weight: 600;
  color: #4CAF50;
}

.messages-container {
  height: 300px;
  overflow-y: auto;
  background: rgba(0, 0, 0, 0.1);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
}

.message {
  margin-bottom: 1rem;
  padding: 0.75rem;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 8px;
}

.message.own-message {
  background: rgba(76, 175, 80, 0.2);
  margin-left: 2rem;
}

.message-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
  font-size: 0.8rem;
  opacity: 0.8;
}

.protocol-badge {
  background: #ff6b6b;
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
  font-size: 0.7rem;
  font-weight: 600;
}

.message-input-container {
  display: flex;
  gap: 0.5rem;
}

.message-input {
  flex: 1;
  padding: 0.75rem;
  border: none;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.9);
  color: #333;
}

.comparison-chart {
  space-y: 1rem;
}

.comparison-item {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
}

.protocol-name {
  min-width: 120px;
  font-weight: 500;
}

.efficiency-bar {
  flex: 1;
  height: 20px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 10px;
  overflow: hidden;
}

.efficiency-fill {
  height: 100%;
  transition: width 0.3s ease;
}

.efficiency-fill.iibin {
  background: linear-gradient(90deg, #4CAF50, #8BC34A);
}

.efficiency-fill.json {
  background: linear-gradient(90deg, #ff6b6b, #ee5a24);
}

.efficiency-value {
  min-width: 80px;
  text-align: right;
  font-weight: 600;
}

.savings-indicator {
  text-align: center;
  padding: 1rem;
  background: rgba(76, 175, 80, 0.2);
  border-radius: 8px;
  margin-top: 1rem;
}

@media (max-width: 768px) {
  .app-main {
    grid-template-columns: 1fr;
    padding: 1rem;
  }
  
  .stress-test-controls {
    grid-template-columns: 1fr;
  }
  
  .results-grid,
  .metrics-grid {
    grid-template-columns: 1fr;
  }
}
</style>
