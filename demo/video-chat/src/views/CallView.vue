<template>
  <div class="call-view">
    <div class="call-header">
      <h2>📹 King Video Call</h2>
      <div class="call-status" :class="callStatus">
        {{ getStatusText() }}
      </div>
    </div>
    
    <div class="video-container">
      <div class="remote-video-wrapper">
        <video 
          ref="remoteVideo" 
          class="remote-video" 
          autoplay 
          playsinline
          :class="{ hidden: !isCallActive }"
        ></video>
        <div v-if="!isCallActive" class="video-placeholder">
          <div class="placeholder-content">
            <div class="avatar">👤</div>
            <div class="name">Remote User</div>
          </div>
        </div>
      </div>
      
      <div class="local-video-wrapper">
        <video 
          ref="localVideo" 
          class="local-video" 
          autoplay 
          playsinline 
          muted
        ></video>
      </div>
    </div>
    
    <div class="call-controls">
      <button 
        @click="toggleVideo" 
        class="control-button"
        :class="{ active: isVideoEnabled }"
      >
        {{ isVideoEnabled ? '📹' : '📹❌' }}
      </button>
      
      <button 
        @click="toggleAudio" 
        class="control-button"
        :class="{ active: isAudioEnabled }"
      >
        {{ isAudioEnabled ? '🎤' : '🎤❌' }}
      </button>
      
      <button 
        @click="isCallActive ? endCall() : startCall()" 
        class="control-button call-button"
        :class="{ 'end-call': isCallActive }"
      >
        {{ isCallActive ? '📞❌' : '📞' }}
      </button>
      
      <button @click="shareScreen" class="control-button">
        🖥️
      </button>
    </div>
    
    <div class="call-info">
      <div class="info-item">
        <span class="label">Duration:</span>
        <span class="value">{{ formatDuration(callDuration) }}</span>
      </div>
      <div class="info-item">
        <span class="label">Quality:</span>
        <span class="value">{{ connectionQuality }}</span>
      </div>
      <div class="info-item">
        <span class="label">Protocol:</span>
        <span class="value">IIBIN + WebRTC</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

const localVideo = ref<HTMLVideoElement>()
const remoteVideo = ref<HTMLVideoElement>()
const isCallActive = ref(false)
const isVideoEnabled = ref(true)
const isAudioEnabled = ref(true)
const callStatus = ref('idle')
const callDuration = ref(0)
const connectionQuality = ref('Excellent')

let localStream: MediaStream | null = null
let peerConnection: RTCPeerConnection | null = null
let callTimer: ReturnType<typeof setInterval> | null = null

const getStatusText = () => {
  switch (callStatus.value) {
    case 'idle': return '⚪ Ready to call'
    case 'connecting': return '🟡 Connecting...'
    case 'connected': return '🟢 Connected'
    case 'ended': return '🔴 Call ended'
    default: return '⚪ Ready'
  }
}

const formatDuration = (seconds: number) => {
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

const startCall = async () => {
  try {
    callStatus.value = 'connecting'
    
    // Get user media
    localStream = await navigator.mediaDevices.getUserMedia({
      video: isVideoEnabled.value,
      audio: isAudioEnabled.value
    })
    
    if (localVideo.value) {
      localVideo.value.srcObject = localStream
    }
    
    // Create peer connection
    peerConnection = new RTCPeerConnection({
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' }
      ]
    })
    
    // Add local stream to peer connection
    localStream.getTracks().forEach(track => {
      if (peerConnection && localStream) {
        peerConnection.addTrack(track, localStream)
      }
    })
    
    // Handle remote stream
    peerConnection.ontrack = (event) => {
      if (remoteVideo.value) {
        remoteVideo.value.srcObject = event.streams[0]
      }
    }
    
    // Simulate call connection
    setTimeout(() => {
      isCallActive.value = true
      callStatus.value = 'connected'
      startCallTimer()
    }, 2000)
    
  } catch (error) {
    console.error('Error starting call:', error)
    callStatus.value = 'idle'
  }
}

const endCall = () => {
  isCallActive.value = false
  callStatus.value = 'ended'
  
  if (localStream) {
    localStream.getTracks().forEach(track => track.stop())
    localStream = null
  }
  
  if (peerConnection) {
    peerConnection.close()
    peerConnection = null
  }
  
  if (callTimer) {
    clearInterval(callTimer)
    callTimer = null
  }
  
  callDuration.value = 0
  
  setTimeout(() => {
    callStatus.value = 'idle'
  }, 2000)
}

const toggleVideo = async () => {
  if (localStream) {
    const videoTrack = localStream.getVideoTracks()[0]
    if (videoTrack) {
      videoTrack.enabled = !videoTrack.enabled
      isVideoEnabled.value = videoTrack.enabled
    }
  }
}

const toggleAudio = async () => {
  if (localStream) {
    const audioTrack = localStream.getAudioTracks()[0]
    if (audioTrack) {
      audioTrack.enabled = !audioTrack.enabled
      isAudioEnabled.value = audioTrack.enabled
    }
  }
}

const shareScreen = async () => {
  try {
    const screenStream = await navigator.mediaDevices.getDisplayMedia({
      video: true,
      audio: true
    })
    
    if (localVideo.value) {
      localVideo.value.srcObject = screenStream
    }
    
    // Replace video track in peer connection
    if (peerConnection && localStream) {
      const videoTrack = screenStream.getVideoTracks()[0]
      const sender = peerConnection.getSenders().find(s => 
        s.track && s.track.kind === 'video'
      )
      
      if (sender) {
        await sender.replaceTrack(videoTrack)
      }
    }
    
  } catch (error) {
    console.error('Error sharing screen:', error)
  }
}

const startCallTimer = () => {
  callTimer = setInterval(() => {
    callDuration.value++
  }, 1000)
}

onMounted(async () => {
  // Initialize local video preview
  try {
    localStream = await navigator.mediaDevices.getUserMedia({
      video: true,
      audio: false
    })
    
    if (localVideo.value) {
      localVideo.value.srcObject = localStream
    }
  } catch (error) {
    console.error('Error accessing camera:', error)
  }
})

onUnmounted(() => {
  if (localStream) {
    localStream.getTracks().forEach(track => track.stop())
  }
  
  if (peerConnection) {
    peerConnection.close()
  }
  
  if (callTimer) {
    clearInterval(callTimer)
  }
})
</script>

<style scoped>
.call-view {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #1a1a1a;
  color: white;
}

.call-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background: rgba(0,0,0,0.5);
}

.call-status {
  padding: 0.5rem 1rem;
  border-radius: 20px;
  background: rgba(255,255,255,0.1);
  font-size: 0.9rem;
}

.video-container {
  flex: 1;
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
}

.remote-video-wrapper {
  width: 100%;
  height: 100%;
  position: relative;
}

.remote-video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.remote-video.hidden {
  display: none;
}

.video-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  justify-content: center;
  align-items: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.placeholder-content {
  text-align: center;
}

.avatar {
  font-size: 4rem;
  margin-bottom: 1rem;
}

.name {
  font-size: 1.5rem;
  font-weight: 500;
}

.local-video-wrapper {
  position: absolute;
  top: 20px;
  right: 20px;
  width: 200px;
  height: 150px;
  border-radius: 10px;
  overflow: hidden;
  border: 2px solid rgba(255,255,255,0.3);
}

.local-video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.call-controls {
  display: flex;
  justify-content: center;
  gap: 1rem;
  padding: 2rem;
  background: rgba(0,0,0,0.5);
}

.control-button {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  border: none;
  background: rgba(255,255,255,0.2);
  color: white;
  font-size: 1.5rem;
  cursor: pointer;
  transition: all 0.3s ease;
}

.control-button:hover {
  background: rgba(255,255,255,0.3);
  transform: scale(1.1);
}

.control-button.active {
  background: #007bff;
}

.call-button {
  background: #28a745;
}

.call-button.end-call {
  background: #dc3545;
}

.call-info {
  display: flex;
  justify-content: space-around;
  padding: 1rem;
  background: rgba(0,0,0,0.3);
  font-size: 0.9rem;
}

.info-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
}

.label {
  opacity: 0.7;
}

.value {
  font-weight: 500;
}
</style>
