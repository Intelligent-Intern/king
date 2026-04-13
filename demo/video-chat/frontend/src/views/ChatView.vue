<template>
  <div class="chat-view">
    <div class="chat-header">
      <h2>💬 King Chat</h2>
      <div class="connection-status" :class="{ connected: isConnected }">
        {{ isConnected ? '🟢 Connected' : '🔴 Disconnected' }}
      </div>
    </div>
    
    <div class="messages-container" ref="messagesContainer">
      <div 
        v-for="message in messages" 
        :key="message.id"
        class="message"
        :class="{ 'own-message': message.isOwn }"
      >
        <div class="message-content">
          <div class="message-text">{{ message.text }}</div>
          <div class="message-time">{{ formatTime(message.timestamp) }}</div>
        </div>
      </div>
    </div>
    
    <div class="input-container">
      <input 
        v-model="newMessage"
        @keyup.enter="sendMessage"
        placeholder="Type a message..."
        class="message-input"
      />
      <button @click="sendMessage" :disabled="!newMessage.trim()" class="send-button">
        📤 Send
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import { format } from 'date-fns'

interface Message {
  id: string
  text: string
  timestamp: Date
  isOwn: boolean
}

const messages = ref<Message[]>([])
const newMessage = ref('')
const isConnected = ref(false)
const messagesContainer = ref<HTMLElement>()

const formatTime = (date: Date) => {
  return format(date, 'HH:mm')
}

const sendMessage = async () => {
  if (!newMessage.value.trim()) return
  
  const message: Message = {
    id: Date.now().toString(),
    text: newMessage.value,
    timestamp: new Date(),
    isOwn: true
  }
  
  messages.value.push(message)
  newMessage.value = ''
  
  // Scroll to bottom
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
  
  // Simulate response (in real app, this would come from IIBIN protocol)
  setTimeout(() => {
    const response: Message = {
      id: (Date.now() + 1).toString(),
      text: `Echo: ${message.text}`,
      timestamp: new Date(),
      isOwn: false
    }
    messages.value.push(response)
    
    nextTick(() => {
      if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
      }
    })
  }, 1000)
}

onMounted(() => {
  // Simulate connection
  setTimeout(() => {
    isConnected.value = true
  }, 1000)
  
  // Add welcome message
  messages.value.push({
    id: '0',
    text: 'Welcome to King Chat! This is powered by the IIBIN binary protocol.',
    timestamp: new Date(),
    isOwn: false
  })
})
</script>

<style scoped>
.chat-view {
  display: flex;
  flex-direction: column;
  height: 100vh;
  max-width: 800px;
  margin: 0 auto;
  background: #f5f5f5;
}

.chat-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background: white;
  border-bottom: 1px solid #ddd;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.connection-status {
  padding: 0.5rem 1rem;
  border-radius: 20px;
  background: #ff4444;
  color: white;
  font-size: 0.9rem;
}

.connection-status.connected {
  background: #44ff44;
  color: #333;
}

.messages-container {
  flex: 1;
  overflow-y: auto;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.message {
  display: flex;
  max-width: 70%;
}

.message.own-message {
  align-self: flex-end;
}

.message-content {
  background: white;
  padding: 0.75rem 1rem;
  border-radius: 18px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.own-message .message-content {
  background: #007bff;
  color: white;
}

.message-text {
  margin-bottom: 0.25rem;
}

.message-time {
  font-size: 0.75rem;
  opacity: 0.7;
}

.input-container {
  display: flex;
  padding: 1rem;
  background: white;
  border-top: 1px solid #ddd;
  gap: 0.5rem;
}

.message-input {
  flex: 1;
  padding: 0.75rem 1rem;
  border: 1px solid #ddd;
  border-radius: 25px;
  outline: none;
  font-size: 1rem;
}

.message-input:focus {
  border-color: #007bff;
}

.send-button {
  padding: 0.75rem 1.5rem;
  background: #007bff;
  color: white;
  border: none;
  border-radius: 25px;
  cursor: pointer;
  font-size: 1rem;
}

.send-button:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.send-button:hover:not(:disabled) {
  background: #0056b3;
}
</style>