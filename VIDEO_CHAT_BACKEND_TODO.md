# Video Chat Backend TODO

## Overview
Transform the demo video chat from a proof-of-concept into a production-ready, secure, and performant video chat system with wavelet compression and Kalman filtering.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           VIDEO CHAT SYSTEM                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────────────────────┐ │
│  │   FRONTEND   │     │    BACKEND   │     │     VIDEO PIPELINE          │ │
│  │   (Vue 3)    │◄───►│  (Node.js)   │     │  (Wavelet + Kalman)         │ │
│  └──────────────┘     └──────────────┘     └──────────────────────────────┘ │
│         │                    │                        │                     │
│         │              ┌──────┴──────┐                  │                     │
│         │              │             │                  │                     │
│         ▼              ▼             ▼                  ▼                     │
│  ┌────────────┐ ┌──────────┐ ┌──────────┐       ┌──────────────┐             │
│  │ WebRTC P2P │ │ TLS/HTTPS│ │   JWT    │       │  Wavelet     │             │
│  │  + TURN    │ │ Security │ │   Auth   │       │  Compress    │             │
│  └────────────┘ └──────────┘ └──────────┘       └──────────────┘             │
│                                                  ┌──────────────┐             │
│                                                  │   Kalman      │             │
│                                                  │   Filter     │             │
│                                                  └──────────────┘             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Phase 1: Security & Backend Foundation

### 1.1 JWT Authentication
- [ ] Implement JWT token generation and validation
- [ ] Add token refresh mechanism
- [ ] Create secure token storage
- [ ] Add password hashing (bcrypt/argon2)
- [ ] Implement session management
- [ ] Add rate limiting for auth endpoints

### 1.2 TLS/HTTPS Setup
- [ ] Generate SSL certificates (self-signed for dev, Let's Encrypt for prod)
- [ ] Configure HTTPS server
- [ ] Enforce HTTPS in production
- [ ] Set up HSTS headers
- [ ] Configure secure WebSocket (WSS)

### 1.3 TURN Server Configuration
- [ ] Set up Coturn server
- [ ] Configure TURN credentials
- [ ] Add TURN server to WebRTC configuration
- [ ] Implement fallback to TURN on ICE failure
- [ ] Monitor TURN server usage

### 1.4 WebSocket Signaling Server
- [ ] Refactor existing server.js with proper auth middleware
- [ ] Implement room-based signaling
- [ ] Add connection validation
- [ ] Handle reconnection gracefully
- [ ] Implement message queuing for late joiners
- [ ] Add WebSocket ping/pong heartbeat

## Phase 2: Data Persistence & Room Management

### 2.1 Database Schema
- [ ] Users table (id, email, password_hash, created_at, last_login)
- [ ] Rooms table (id, name, created_by, created_at, settings)
- [ ] Room members table (room_id, user_id, role, joined_at)
- [ ] Messages table (id, room_id, user_id, content, created_at)
- [ ] Sessions table (id, user_id, token, expires_at)

### 2.2 Room Management
- [ ] Create room API (POST /api/rooms)
- [ ] Join room API (POST /api/rooms/:id/join)
- [ ] Leave room API (POST /api/rooms/:id/leave)
- [ ] List rooms API (GET /api/rooms)
- [ ] Room settings (max participants, password protection)

### 2.3 Message Persistence
- [ ] Store chat messages in database
- [ ] Implement message pagination
- [ ] Add message search
- [ ] Implement message history retrieval

## Phase 3: Wavelet Compression

### 3.1 Wavelet Transform Implementation
- [ ] Research and select wavelet algorithm (Daubechies, Haar, CDF 9/7)
- [ ] Implement 2D discrete wavelet transform (DWT)
- [ ] Create inverse DWT for reconstruction
- [ ] Optimize for real-time performance
- [ ] Consider WASM for performance-critical code

### 3.2 Compression Pipeline
- [ ] Frame capture from video stream
- [ ] RGB to YUV color space conversion
- [ ] Wavelet decomposition (multiple levels)
- [ ] Coefficient quantization
- [ ] Entropy coding (arithmetic/RLE)
- [ ] Bitstream packaging

### 3.3 Quality Control
- [ ] Configurable compression levels
- [ ] Adaptive quantization based on bandwidth
- [ ] PSNR/SSIM quality metrics
- [ ] Visual quality vs bandwidth tradeoff controls

## Phase 4: Kalman Filter Enhancement

### 4.1 Kalman Filter Core
- [ ] Implement Kalman filter for state estimation
- [ ] Define state vector (position, velocity for each pixel block)
- [ ] Implement prediction step
- [ ] Implement update step
- [ ] Tune process and measurement noise covariances

### 4.2 Video Enhancement Pipeline
- [ ] Block-based motion estimation
- [ ] Temporal prediction using Kalman
- [ ] Residual encoding (difference from prediction)
- [ ] Quality enhancement via filtering
- [ ] Noise reduction

### 4.3 Integration with Wavelet Codec
- [ ] Use Kalman predictions as wavelet coefficient priors
- [ ] Improve compression ratio via prediction
- [ ] Reduce transmitted data to residuals only
- [ ] Reconstruct high-quality frames at receiver

## Phase 5: WebRTC Integration

### 5.1 Custom Video Processing
- [ ] Create VideoEncoder wrapper
- [ ] Implement custom getUserMedia handling
- [ ] Add wavelet pre-processing to video track
- [ ] Create custom VideoDecoder for Kalman post-processing

### 5.2 WebRTC Configuration
- [ ] Configure RTCRtpSender with custom codec
- [ ] Set up appropriate bitrate constraints
- [ ] Implement bandwidth estimation
- [ ] Handle codec negotiation

### 5.3 Performance Optimization
- [ ] Web Workers for encoding/decoding
- [ ] SharedArrayBuffer for frame data
- [ ] OffscreenCanvas for rendering
- [ ] GPU acceleration where available

## Phase 6: Security Hardening

### 6.1 Input Validation
- [ ] Validate all API inputs
- [ ] Sanitize room names and messages
- [ ] Implement CSRF protection
- [ ] Add Content Security Policy headers

### 6.2 WebSocket Security
- [ ] Validate WebSocket messages schema
- [ ] Implement rate limiting per connection
- [ ] Add connection timeout handling
- [ ] Prevent WebSocket flooding attacks

### 6.3 Privacy
- [ ] End-to-end encryption for signaling
- [ ] Room access token rotation
- [ ] Implement recording consent flags
- [ ] GDPR compliance (data export/delete)

## Phase 7: Monitoring & Observability

### 7.1 Metrics
- [ ] Connection success/failure rates
- [ ] Video quality metrics (bitrate, framerate, resolution)
- [ ] Latency measurements
- [ ] Compression ratio statistics
- [ ] Kalman filter accuracy metrics

### 7.2 Logging
- [ ] Structured JSON logging
- [ ] Request/response logging
- [ ] WebSocket event logging
- [ ] Error tracking and reporting

### 7.3 Health Checks
- [ ] Server health endpoint
- [ ] Database connectivity check
- [ ] TURN server availability check
- [ ] WebSocket connection status

## Phase 8: Deployment

### 8.1 Docker Configuration
- [ ] Backend Dockerfile (multi-stage build)
- [ ] Frontend Dockerfile (nginx serving)
- [ ] Docker Compose for full stack
- [ ] Coturn container configuration
- [ ] Environment variable management

### 8.2 Production Setup
- [ ] Reverse proxy configuration (nginx)
- [ ] Load balancing considerations
- [ ] Horizontal scaling strategy
- [ ] Database migration scripts
- [ ] Backup strategy

## Technical Decisions

### Wavelet Algorithm Selection
| Algorithm | Pros | Cons | Use Case |
|-----------|------|------|----------|
| Haar | Fast, simple | Blocky artifacts | Low latency |
| Daubechies D4 | Good compression | Slower | Balanced |
| CDF 9/7 | Excellent quality | Complex | High quality |

### Kalman Filter Parameters
```
State vector: [x, y, vx, vy] per block
Process noise: Q = 0.001
Measurement noise: R = 0.1
Initial covariance: P = 1
```

## File Structure

```
demo/video-chat/
├── backend/
│   ├── src/
│   │   ├── index.ts              # Entry point
│   │   ├── server.ts             # Express + WebSocket
│   │   ├── auth/
│   │   │   ├── jwt.ts            # JWT utilities
│   │   │   ├── middleware.ts     # Auth middleware
│   │   │   └── password.ts       # Password hashing
│   │   ├── signaling/
│   │   │   ├── handler.ts        # WebSocket handlers
│   │   │   └── rooms.ts          # Room management
│   │   ├── api/
│   │   │   ├── routes.ts         # REST API routes
│   │   │   └── controllers/      # Route handlers
│   │   ├── db/
│   │   │   ├── schema.sql        # Database schema
│   │   │   └── client.ts         # Database client
│   │   └── security/
│   │       ├── cors.ts           # CORS config
│   │       ├── rateLimit.ts      # Rate limiting
│   │       └── validation.ts     # Input validation
│   ├── certs/                    # SSL certificates
│   ├── package.json
│   └── tsconfig.json
├── frontend/
│   ├── src/
│   │   ├── lib/
│   │   │   ├── wavelet/          # Wavelet codec
│   │   │   │   ├── dwt.ts        # Discrete wavelet transform
│   │   │   │   ├── quantize.ts   # Quantization
│   │   │   │   └── codec.ts      # Encoder/decoder
│   │   │   └── kalman/           # Kalman filter
│   │   │       ├── filter.ts     # Core filter
│   │   │       ├── predictor.ts  # Motion predictor
│   │   │       └── video.ts      # Video enhancement
│   │   └── ...
│   └── ...
└── infra/
    ├── coturn/                   # TURN server config
    └── docker-compose.yml
```

## API Endpoints

### Authentication
```
POST /api/auth/register    - Create new user
POST /api/auth/login       - Authenticate user
POST /api/auth/refresh     - Refresh JWT token
POST /api/auth/logout      - Invalidate session
```

### Rooms
```
GET    /api/rooms          - List available rooms
POST   /api/rooms           - Create new room
GET    /api/rooms/:id       - Get room details
DELETE /api/rooms/:id       - Delete room
POST   /api/rooms/:id/join  - Join room
POST   /api/rooms/:id/leave - Leave room
```

### Messages
```
GET    /api/rooms/:id/messages     - Get message history
POST   /api/rooms/:id/messages     - Send message
DELETE /api/messages/:id           - Delete message
```

## Environment Variables

```env
# Server
PORT=8080
NODE_ENV=production

# Database
DATABASE_URL=sqlite:./data/king.db

# JWT
JWT_SECRET=<generate-strong-secret>
JWT_EXPIRES_IN=7d
JWT_REFRESH_EXPIRES_IN=30d

# TLS
SSL_CERT_PATH=./certs/server.crt
SSL_KEY_PATH=./certs/server.key

# TURN
TURN_SERVER=turn:your-turn-server.com
TURN_USERNAME=<turn-credentials>
TURN_CREDENTIAL=<turn-credentials>

# Compression
WAVELET_LEVELS=4
KALMAN_PROCESS_NOISE=0.001
KALMAN_MEASUREMENT_NOISE=0.1

# Monitoring
LOG_LEVEL=info
METRICS_ENABLED=true
```

## Testing Strategy

### Unit Tests
- JWT token generation/validation
- Wavelet transform accuracy
- Kalman filter state estimation
- Input validation

### Integration Tests
- Authentication flow
- Room creation/join
- WebSocket signaling
- Message persistence

### E2E Tests
- Full video call flow
- Multi-participant scenarios
- Reconnection handling
- Compression quality assessment

## Performance Targets

| Metric | Target |
|--------|--------|
| Video latency | < 100ms |
| Compression ratio | 10:1 to 50:1 |
| CPU usage (encoding) | < 30% per stream |
| Memory per stream | < 100MB |
| Max concurrent streams | 100+ |
