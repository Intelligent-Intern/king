FROM node:22-bookworm-slim AS builder
WORKDIR /app

ENV NODE_ENV=production
ENV NPM_CONFIGProduction=true

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 make g++ \
    && rm -rf /var/lib/apt/lists/*

COPY demo/video-chat/backend/package*.json ./
RUN npm ci

COPY demo/video-chat/backend/tsconfig.json ./
COPY demo/video-chat/backend/src ./src

RUN npm run build

FROM node:22-bookworm-slim AS production

WORKDIR /app

ENV NODE_ENV=production
ENV PORT=8080

RUN apt-get update \
    && apt-get install -y --no-install-recommends dumb-init \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /app/dist ./dist
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/package*.json ./

RUN mkdir -p /app/data /app/certs \
    && chown -R node:node /app

USER node

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://localhost:8080/health || exit 1

ENTRYPOINT ["dumb-init", "--"]
CMD ["node", "dist/index.js"]
