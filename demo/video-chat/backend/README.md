# King Video Chat Backend

Production-ready video chat backend with JWT authentication, wavelet compression, and Kalman filtering support.

## Quick Start

### 1. Install Dependencies

```bash
cd demo/video-chat/backend
npm install
```

### 2. Configure Environment

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```env
PORT=8080
NODE_ENV=development
JWT_SECRET=your-super-secret-key-change-in-production
JWT_EXPIRES_IN=7d
JWT_REFRESH_EXPIRES_IN=30d
DATABASE_PATH=./data/king.db
SSL_ENABLED=false
CORS_ORIGIN=http://localhost:5173
```

### 3. Run Development Server

```bash
npm run dev
```

The server will start on `http://localhost:8080`

### 4. Build for Production

```bash
npm run build
npm start
```

## Docker Deployment

### Simple Deployment

```bash
cd demo/video-chat
docker compose up --build
```

### With TURN Server (Production)

```bash
TURN_REALM=your-domain.com TURN_STATIC_AUTH_SECRET=your-secret docker compose --profile production up --build
```

Ports:
- Frontend: `http://127.0.0.1:5173`
- Backend: `http://127.0.0.1:8080`
- TURN (optional): `3478/udp`, `5349/tls`

## API Endpoints

### Authentication

```bash
# Register new user
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"Password123","displayName":"John"}'

# Login
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"Password123"}'

# Refresh token
curl -X POST http://localhost:8080/api/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{"refreshToken":"<token>"}'

# Logout
curl -X POST http://localhost:8080/api/auth/logout \
  -H "Authorization: Bearer <access_token>"

# Get current user
curl http://localhost:8080/api/auth/me \
  -H "Authorization: Bearer <access_token>"
```

### Rooms

```bash
# List rooms
curl http://localhost:8080/api/rooms

# Create room
curl -X POST http://localhost:8080/api/rooms \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"name":"My Room","description":"A test room"}'

# Get room details
curl http://localhost:8080/api/rooms/:roomId

# Join room
curl -X POST http://localhost:8080/api/rooms/:roomId/join \
  -H "Authorization: Bearer <token>"

# Leave room
curl -X POST http://localhost:8080/api/rooms/:roomId/leave \
  -H "Authorization: Bearer <token>"

# Get room messages
curl "http://localhost:8080/api/rooms/:roomId/messages?limit=50" \
  -H "Authorization: Bearer <token>"

# Send message
curl -X POST http://localhost:8080/api/rooms/:roomId/messages \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"content":"Hello!","type":"text"}'
```

### Users

```bash
# List users
curl http://localhost:8080/api/users \
  -H "Authorization: Bearer <token>"

# Get user
curl http://localhost:8080/api/users/:userId \
  -H "Authorization: Bearer <token>"

# Update profile
curl -X PATCH http://localhost:8080/api/users/me \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"displayName":"New Name","displayColor":"#ff0000"}'
```

### WebSocket Signaling

Connect to `ws://localhost:8080/ws` with query params:

```
ws://localhost:8080/ws?token=<jwt_token>&userId=<user_id>&name=<display_name>&color=<hex_color>&room=<room_id>
```

#### Signaling Messages

```javascript
// Send chat message
ws.send(JSON.stringify({
  type: 'chat/send',
  text: 'Hello!'
}))

// Start typing
ws.send(JSON.stringify({ type: 'typing/start' }))

// Stop typing
ws.send(JSON.stringify({ type: 'typing/stop' }))

// Join video call
ws.send(JSON.stringify({ type: 'call/join' }))

// Leave video call
ws.send(JSON.stringify({ type: 'call/leave' }))

// Send WebRTC offer to specific user
ws.send(JSON.stringify({
  type: 'call/offer',
  targetUserId: 'user-id',
  payload: { sdp: '...', type: 'offer' }
}))

// Send WebRTC answer
ws.send(JSON.stringify({
  type: 'call/answer',
  targetUserId: 'user-id',
  payload: { sdp: '...', type: 'answer' }
}))

// Send ICE candidate
ws.send(JSON.stringify({
  type: 'call/ice',
  targetUserId: 'user-id',
  payload: { candidate: '...' }
}))

// Hangup
ws.send(JSON.stringify({
  type: 'call/hangup',
  targetUserId: 'user-id'
}))
```

## Health Check

```bash
curl http://localhost:8080/health
```

Response:
```json
{
  "ok": true,
  "service": "king-video-chat-backend",
  "version": "1.0.0",
  "timestamp": "2024-01-01T00:00:00.000Z"
}
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PORT` | 8080 | Server port |
| `NODE_ENV` | development | Environment mode |
| `JWT_SECRET` | (required) | JWT signing secret |
| `JWT_EXPIRES_IN` | 7d | Access token expiry |
| `JWT_REFRESH_EXPIRES_IN` | 30d | Refresh token expiry |
| `DATABASE_PATH` | ./data/king.db | SQLite database path |
| `SSL_ENABLED` | false | Enable HTTPS |
| `SSL_CERT_PATH` | ./certs/server.crt | SSL certificate path |
| `SSL_KEY_PATH` | ./certs/server.key | SSL key path |
| `CORS_ORIGIN` | http://localhost:5173 | CORS allowed origin |
| `RATE_LIMIT_WINDOW_MS` | 900000 | Rate limit window |
| `RATE_LIMIT_MAX_REQUESTS` | 100 | Max requests per window |
| `TURN_ENABLED` | false | Enable TURN server |
| `TURN_SERVER` | | TURN server URL |

## Security Features

- JWT authentication with refresh tokens
- bcrypt password hashing (12 rounds)
- Rate limiting (100 requests/15 min)
- Helmet.js security headers
- CORS configuration
- Input validation with Zod
- Session management
- HTTPS support (optional)

## Production Checklist

- [ ] Set strong `JWT_SECRET`
- [ ] Enable `SSL_ENABLED` with valid certificates
- [ ] Configure `CORS_ORIGIN` to your domain
- [ ] Set up TURN server for NAT traversal
- [ ] Configure reverse proxy (nginx)
- [ ] Set up monitoring/logging
- [ ] Configure backup strategy for SQLite
