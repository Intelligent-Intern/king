import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'

import App from './App.vue'
import ChatView from './views/ChatView.vue'
import CallView from './views/CallView.vue'
import SettingsView from './views/SettingsView.vue'
import StressTestView from './views/StressTestView.vue'
import PerformanceView from './views/PerformanceView.vue'

import './style.css'

// Router configuration
const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/chat' },
    { path: '/chat', component: ChatView, name: 'chat' },
    { path: '/call/:roomId?', component: CallView, name: 'call', props: true },
    { path: '/settings', component: SettingsView, name: 'settings' },
    { path: '/stress-test', component: StressTestView, name: 'stress-test' },
    { path: '/performance', component: PerformanceView, name: 'performance' }
  ]
})

// Create Vue app
const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Global error handler
app.config.errorHandler = (err, instance, info) => {
  console.error('Vue Error:', err, info)
  // In production, send to error tracking service
}

// Performance monitoring
if (import.meta.env.DEV) {
  app.config.performance = true
}

app.mount('#app')

// Service Worker registration for PWA features
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  navigator.serviceWorker.register('/sw.js')
    .then(registration => {
      console.log('SW registered:', registration)
    })
    .catch(error => {
      console.log('SW registration failed:', error)
    })
}

// WebRTC adapter for cross-browser compatibility
import 'webrtc-adapter'

console.log('🚀 King Video Chat System initialized!')
console.log('📱 Features: Real-time messaging, Video calls, File sharing')
console.log('⚡ Powered by IIBIN binary protocol')