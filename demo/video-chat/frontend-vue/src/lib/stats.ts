/**
 * WebRTC Statistics Monitor
 * Tracks bitrate, framerate, latency, packet loss, and quality metrics
 */

export interface RTCStats {
  timestamp: number
  bitrate: {
    upload: number
    download: number
    target: number
    actual: number
  }
  framerate: {
    sent: number
    received: number
    dropped: number
  }
  latency: {
    rtt: number
    jitter: number
  }
  packetLoss: {
    upload: number
    download: number
  }
  quality: {
    score: number
    mos: number
  }
  resolution: {
    width: number
    height: number
  }
  codecs: {
    video: string
    audio: string
  }
  connection: {
    state: RTCPeerConnectionState
    localCandidate: string
    remoteCandidate: string
  }
}

export interface RTCStatsSummary {
  current: RTCStats
  average: Partial<RTCStats>
  peak: Partial<RTCStats>
  duration: number
}

export class RTCStatsMonitor {
  private peerConnection: RTCPeerConnection | null = null
  private statsHistory: RTCStats[] = []
  private lastStats: RTCStats | null = null
  private lastTimestamp: number = 0
  private lastBytesSent: number = 0
  private lastBytesReceived: number = 0
  private startTime: number = Date.now()
  private intervalId: number | null = null
  private onStatsCallback: ((stats: RTCStatsSummary) => void) | null = null

  constructor() {
    this.statsHistory = []
  }

  attach(pc: RTCPeerConnection): void {
    this.peerConnection = pc
    this.startTime = Date.now()
  }

  detach(): void {
    this.peerConnection = null
    this.stop()
  }

  start(intervalMs = 1000, callback?: (stats: RTCStatsSummary) => void): void {
    if (this.intervalId !== null) {
      this.stop()
    }

    this.onStatsCallback = callback || null
    this.intervalId = window.setInterval(() => {
      this.collectStats()
    }, intervalMs)
  }

  stop(): void {
    if (this.intervalId !== null) {
      clearInterval(this.intervalId)
      this.intervalId = null
    }
  }

  private async collectStats(): Promise<void> {
    if (!this.peerConnection) return

    try {
      const stats = await this.peerConnection.getStats()
      const now = Date.now()
      
      const rtcStats: RTCStats = {
        timestamp: now,
        bitrate: {
          upload: 0,
          download: 0,
          target: 0,
          actual: 0,
        },
        framerate: {
          sent: 0,
          received: 0,
          dropped: 0,
        },
        latency: {
          rtt: 0,
          jitter: 0,
        },
        packetLoss: {
          upload: 0,
          download: 0,
        },
        quality: {
          score: 100,
          mos: 4.5,
        },
        resolution: {
          width: 0,
          height: 0,
        },
        codecs: {
          video: 'unknown',
          audio: 'unknown',
        },
        connection: {
          state: this.peerConnection.connectionState,
          localCandidate: '',
          remoteCandidate: '',
        },
      }

      let currentBytesSent = 0
      let currentBytesReceived = 0
      let currentTimestamp = 0

      stats.forEach((report) => {
        if (report.type === 'outbound-rtp' && report.kind === 'video') {
          if (report.bytesSent && report.timestamp) {
            currentBytesSent = report.bytesSent
            currentTimestamp = report.timestamp
          }
          if (report.frameRate) {
            rtcStats.framerate.sent = report.frameRate
          } else if ((report as any).framesPerSecond) {
            rtcStats.framerate.sent = (report as any).framesPerSecond
          }
          if (report.width && report.height) {
            rtcStats.resolution.width = report.width
            rtcStats.resolution.height = report.height
          }
        }

        if (report.type === 'inbound-rtp' && report.kind === 'video') {
          if (report.bytesReceived && report.timestamp) {
            currentBytesReceived = report.bytesReceived
            if (currentTimestamp > 0) {
              currentTimestamp = report.timestamp
            }
          }
          if (report.frameRate) {
            rtcStats.framerate.received = report.frameRate
          } else if ((report as any).framesPerSecond) {
            rtcStats.framerate.received = (report as any).framesPerSecond
          }
          if (report.framesDropped) {
            rtcStats.framerate.dropped = report.framesDropped
          }
        }

        if (report.type === 'track' && report.kind === 'video') {
          const trackReport = report as any
          if (trackReport.frameWidth && trackReport.frameHeight) {
            rtcStats.resolution.width = trackReport.frameWidth
            rtcStats.resolution.height = trackReport.frameHeight
          }
          if (trackReport.framesReceived) {
            rtcStats.framerate.received = trackReport.framesReceived
          }
        }

        if (report.type === 'candidate-pair' && report.state === 'succeeded') {
          if (report.currentRoundTripTime !== undefined) {
            rtcStats.latency.rtt = report.currentRoundTripTime * 1000
          }
          if (report.jitter !== undefined) {
            rtcStats.latency.jitter = report.jitter * 1000
          }
        }

        if (report.type === 'remote-inbound-rtp' && report.kind === 'video') {
          if (report.roundTripTime !== undefined) {
            rtcStats.latency.rtt = report.roundTripTime * 1000
          }
          if (report.jitter !== undefined) {
            rtcStats.latency.jitter = report.jitter * 1000
          }
        }

        if (report.type === 'outbound-rtp' && report.kind === 'video') {
          if (report.packetsLost !== undefined && report.packetsSent !== undefined) {
            const totalPackets = report.packetsLost + report.packetsSent
            rtcStats.packetLoss.upload = totalPackets > 0 ? (report.packetsLost / totalPackets) * 100 : 0
          }
        }

        if (report.type === 'inbound-rtp' && report.kind === 'video') {
          if (report.packetsLost !== undefined && report.packetsReceived !== undefined) {
            const totalPackets = report.packetsLost + report.packetsReceived
            rtcStats.packetLoss.download = totalPackets > 0 ? (report.packetsLost / totalPackets) * 100 : 0
          }
        }

        if (report.type === 'track' && report.kind === 'video') {
          rtcStats.quality.score = report.qualityLimitationReasons ? 100 - report.qualityLimitationReasons.size * 20 : 100
        }

        if (report.type === 'codec') {
          if (report.mimeType?.includes('video')) {
            rtcStats.codecs.video = report.mimeType
          } else if (report.mimeType?.includes('audio')) {
            rtcStats.codecs.audio = report.mimeType
          }
        }
      })

      if (this.lastTimestamp > 0 && currentTimestamp > 0) {
        const timeDelta = (currentTimestamp - this.lastTimestamp) / 1000
        if (timeDelta > 0) {
          rtcStats.bitrate.upload = ((currentBytesSent - (this.lastBytesSent || 0)) * 8) / timeDelta
          rtcStats.bitrate.download = ((currentBytesReceived - (this.lastBytesReceived || 0)) * 8) / timeDelta
        }
      }
      this.lastBytesSent = currentBytesSent
      this.lastBytesReceived = currentBytesReceived

      rtcStats.quality.mos = this.calculateMOS(rtcStats.latency.rtt, rtcStats.packetLoss.upload)

      this.statsHistory.push(rtcStats)
      if (this.statsHistory.length > 300) {
        this.statsHistory.shift()
      }

      this.lastStats = rtcStats
      this.lastTimestamp = currentTimestamp || now

      const summary = this.getSummary()
      if (this.onStatsCallback) {
        this.onStatsCallback(summary)
      }
    } catch (error) {
      console.error('[RTCStats] Error collecting stats:', error)
    }
  }

  private calculateMOS(rtt: number, packetLoss: number): number {
    const effectiveLatency = rtt / 2 + packetLoss * 10
    let mos = 5
    
    if (effectiveLatency > 400) mos = 1
    else if (effectiveLatency > 300) mos = 2
    else if (effectiveLatency > 200) mos = 3
    else if (effectiveLatency > 100) mos = 4
    
    mos -= packetLoss / 25
    
    return Math.max(1, Math.min(5, mos))
  }

  getSummary(): RTCStatsSummary {
    const current = this.statsHistory[this.statsHistory.length - 1] || this.createEmptyStats()
    
    const avg: Partial<RTCStats> = {}
    const peak: Partial<RTCStats> = {}

    if (this.statsHistory.length > 0) {
      const sum = this.statsHistory.reduce((acc, s) => ({
        bitrate: {
          upload: acc.bitrate.upload + s.bitrate.upload,
          download: acc.bitrate.download + s.bitrate.download,
          target: acc.bitrate.target + s.bitrate.target,
          actual: acc.bitrate.actual + s.bitrate.actual,
        },
        framerate: {
          sent: acc.framerate.sent + s.framerate.sent,
          received: acc.framerate.received + s.framerate.received,
          dropped: acc.framerate.dropped + s.framerate.dropped,
        },
        latency: {
          rtt: acc.latency.rtt + s.latency.rtt,
          jitter: acc.latency.jitter + s.latency.jitter,
        },
        packetLoss: {
          upload: acc.packetLoss.upload + s.packetLoss.upload,
          download: acc.packetLoss.download + s.packetLoss.download,
        },
      }), {
        bitrate: { upload: 0, download: 0, target: 0, actual: 0 },
        framerate: { sent: 0, received: 0, dropped: 0 },
        latency: { rtt: 0, jitter: 0 },
        packetLoss: { upload: 0, download: 0 },
      })

      const count = this.statsHistory.length
      avg.bitrate = {
        upload: sum.bitrate.upload / count,
        download: sum.bitrate.download / count,
        target: sum.bitrate.target / count,
        actual: sum.bitrate.actual / count,
      }
      avg.framerate = {
        sent: sum.framerate.sent / count,
        received: sum.framerate.received / count,
        dropped: sum.framerate.dropped / count,
      }
      avg.latency = {
        rtt: sum.latency.rtt / count,
        jitter: sum.latency.jitter / count,
      }
      avg.packetLoss = {
        upload: sum.packetLoss.upload / count,
        download: sum.packetLoss.download / count,
      }

      peak.bitrate = {
        upload: Math.max(...this.statsHistory.map(s => s.bitrate.upload)),
        download: Math.max(...this.statsHistory.map(s => s.bitrate.download)),
        target: Math.max(...this.statsHistory.map(s => s.bitrate.target)),
        actual: Math.max(...this.statsHistory.map(s => s.bitrate.actual)),
      }
      peak.framerate = {
        sent: Math.max(...this.statsHistory.map(s => s.framerate.sent)),
        received: Math.max(...this.statsHistory.map(s => s.framerate.received)),
        dropped: Math.max(...this.statsHistory.map(s => s.framerate.dropped)),
      }
      peak.latency = {
        rtt: Math.max(...this.statsHistory.map(s => s.latency.rtt)),
        jitter: Math.max(...this.statsHistory.map(s => s.latency.jitter)),
      }
    }

    return {
      current,
      average: avg,
      peak,
      duration: Date.now() - this.startTime,
    }
  }

  private createEmptyStats(): RTCStats {
    return {
      timestamp: Date.now(),
      bitrate: { upload: 0, download: 0, target: 0, actual: 0 },
      framerate: { sent: 0, received: 0, dropped: 0 },
      latency: { rtt: 0, jitter: 0 },
      packetLoss: { upload: 0, download: 0 },
      quality: { score: 100, mos: 4.5 },
      resolution: { width: 0, height: 0 },
      codecs: { video: 'unknown', audio: 'unknown' },
      connection: { state: 'new', localCandidate: '', remoteCandidate: '' },
    }
  }

  reset(): void {
    this.statsHistory = []
    this.lastStats = null
    this.lastTimestamp = 0
    this.lastBytesSent = 0
    this.lastBytesReceived = 0
    this.startTime = Date.now()
  }
}

export function formatBitrate(bps: number): string {
  if (bps < 1000) return `${bps.toFixed(0)} bps`
  if (bps < 1000000) return `${(bps / 1000).toFixed(1)} Kbps`
  return `${(bps / 1000000).toFixed(2)} Mbps`
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function createStatsMonitor(): RTCStatsMonitor {
  return new RTCStatsMonitor()
}
