FROM node:22-alpine AS build
WORKDIR /app

ARG IIBIN_SOURCE=1.0.5-beta

COPY demo/video-chat/frontend/package.json demo/video-chat/frontend/package-lock.json ./
RUN npm pkg set "dependencies.@intelligentintern/iibin=${IIBIN_SOURCE}" \
  && npm install --no-package-lock

COPY demo/video-chat/frontend/ ./
RUN npm run build

FROM node:22-alpine AS runtime
WORKDIR /app

RUN npm install --no-save express http-proxy-middleware
COPY --from=build /app/dist ./dist
COPY demo/video-chat/frontend/server.mjs ./server.mjs

EXPOSE 5173
CMD ["node", "server.mjs"]
