import { createApp } from 'vue'
import { createPinia } from 'pinia'
import 'webrtc-adapter'

import App from './App.vue'
import './style.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

app.config.errorHandler = (err, instance, info) => {
  console.error('Vue Error:', err, info)
}

if (import.meta.env.DEV) {
  app.config.performance = true
}

app.mount('#app')

console.log('King video workspace initialized.')
console.log('Features: room collaboration, multi-user chat, and video calling.')
console.log('Powered by IIBIN binary protocol.')
