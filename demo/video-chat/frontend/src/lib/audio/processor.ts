/**
 * Audio Processor
 * Processes audio through compression/filtering
 */

export interface AudioProcessorConfig {
  enabled: boolean
  targetBitrate: number
  enableNoiseReduction: boolean
}

export interface AudioStats {
  framesProcessed: number
  avgProcessTimeMs: number
  compressionRatio: number
}

export class AudioProcessor {
  private config: AudioProcessorConfig
  private audioContext: AudioContext | null = null
  private sourceNode: MediaStreamAudioSourceNode | null = null
  private processorNode: ScriptProcessorNode | AudioWorkletNode | null = null
  private gainNode: GainNode | null = null
  private processedStream: MediaStream | null = null
  private stats: AudioStats = {
    framesProcessed: 0,
    avgProcessTimeMs: 0,
    compressionRatio: 1.0,
  }

  constructor(config: Partial<AudioProcessorConfig> = {}) {
    this.config = {
      enabled: config.enabled ?? true,
      targetBitrate: config.targetBitrate ?? 64000,
      enableNoiseReduction: config.enableNoiseReduction ?? false,
    }
  }

  async processStream(inputStream: MediaStream): Promise<MediaStream> {
    const audioTrack = inputStream.getAudioTracks()[0]
    if (!audioTrack) {
      throw new Error('No audio track in input stream')
    }

    this.audioContext = new AudioContext()
    
    const source = this.audioContext.createMediaStreamSource(inputStream)
    this.sourceNode = source

    // Use ScriptProcessorNode for raw audio processing
    const bufferSize = 4096
    this.processorNode = this.audioContext.createScriptProcessor(bufferSize, 1, 1)
    
    // Simple audio processing - can be extended
    this.processorNode.onaudioprocess = (event) => {
      const inputBuffer = event.inputBuffer
      const outputBuffer = event.outputBuffer
      const inputData = inputBuffer.getChannelData(0)
      const outputData = outputBuffer.getChannelData(0)
      
      const startTime = performance.now()
      
      // Copy with optional processing
      for (let i = 0; i < inputData.length; i++) {
        let sample = inputData[i]
        
        // Simple noise gate (very basic noise reduction)
        if (this.config.enableNoiseReduction) {
          const threshold = 0.01
          if (Math.abs(sample) < threshold) {
            sample = sample * 0.3 // Attenuate quiet signals
          }
        }
        
        // Simple compression (limit dynamic range)
        const ratio = 3
        const threshold = 0.5
        if (Math.abs(sample) > threshold) {
          const excess = Math.abs(sample) - threshold
          sample = Math.sign(sample) * (threshold + excess / ratio)
        }
        
        outputData[i] = sample
      }
      
      const processTime = performance.now() - startTime
      this.stats.framesProcessed++
      this.stats.avgProcessTimeMs = 
        (this.stats.avgProcessTimeMs * (this.stats.framesProcessed - 1) + processTime) / this.stats.framesProcessed
    }

    this.gainNode = this.audioContext.createGain()
    this.gainNode.gain.value = 1.0

    // Create destination for processed audio
    const destStream = this.audioContext.createMediaStreamDestination()
    
    source.connect(this.processorNode)
    this.processorNode.connect(this.gainNode)
    this.gainNode.connect(destStream)

    this.processedStream = destStream.stream
    
    return this.processedStream
  }

  stop(): void {
    if (this.processorNode) {
      this.processorNode.disconnect()
      this.processorNode = null
    }
    if (this.sourceNode) {
      this.sourceNode.disconnect()
      this.sourceNode = null
    }
    if (this.gainNode) {
      this.gainNode.disconnect()
      this.gainNode = null
    }
    if (this.audioContext) {
      this.audioContext.close()
      this.audioContext = null
    }
    if (this.processedStream) {
      this.processedStream.getTracks().forEach(t => t.stop())
      this.processedStream = null
    }
    this.stats = {
      framesProcessed: 0,
      avgProcessTimeMs: 0,
      compressionRatio: 1.0,
    }
  }

  getStats(): AudioStats {
    return { ...this.stats }
  }

  isProcessing(): boolean {
    return this.audioContext !== null
  }

  setNoiseReduction(enabled: boolean): void {
    this.config.enableNoiseReduction = enabled
  }

  setGain(gain: number): void {
    if (this.gainNode) {
      this.gainNode.gain.value = Math.max(0, Math.min(2, gain))
    }
  }
}

export function createAudioProcessor(config?: Partial<AudioProcessorConfig>): AudioProcessor {
  return new AudioProcessor(config)
}
