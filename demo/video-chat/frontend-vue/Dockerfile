FROM node:22-alpine

WORKDIR /app

COPY demo/video-chat/frontend-vue/package.json demo/video-chat/frontend-vue/package-lock.json ./
RUN npm ci

COPY demo/video-chat/frontend-vue/ ./
ARG VITE_VIDEOCHAT_BACKEND_ORIGIN=""
ARG VITE_VIDEOCHAT_BACKEND_PORT="18080"
ENV VITE_VIDEOCHAT_BACKEND_ORIGIN="${VITE_VIDEOCHAT_BACKEND_ORIGIN}"
ENV VITE_VIDEOCHAT_BACKEND_PORT="${VITE_VIDEOCHAT_BACKEND_PORT}"
RUN npm run build

EXPOSE 5174

CMD ["npm", "run", "preview", "--", "--host", "0.0.0.0", "--port", "5174"]
