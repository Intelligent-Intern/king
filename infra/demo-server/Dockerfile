# syntax=docker/dockerfile:1.7

FROM node:22-bookworm-slim AS build

WORKDIR /app

COPY demo/video-chat/package.json demo/video-chat/package-lock.json ./
RUN npm ci

COPY demo/video-chat/ ./
RUN npm run build

FROM nginx:1.27-alpine

COPY infra/demo-server/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

