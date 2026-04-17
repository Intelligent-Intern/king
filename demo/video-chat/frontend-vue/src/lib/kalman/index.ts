/**
 * Kalman Filter Library
 * Exports all Kalman filtering components
 */

export { KalmanFilter2D, VideoKalmanFilter, createKalmanFilter } from './filter.js'
export type { KalmanConfig, MotionVector, BlockState } from './filter.js'
export { VideoEnhancer, createVideoEnhancer } from './video.js'
export type { VideoEnhancerConfig, EnhancedFrame, QualityMetrics } from './video.js'
export { WebRTCVideoProcessor, createVideoProcessor, estimateBandwidth, selectOptimalQuality } from './processor.js'
export type { WebRTCVideoProcessorConfig } from './processor.js'
