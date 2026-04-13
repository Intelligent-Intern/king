FROM node:22-alpine

WORKDIR /app

COPY demo/video-chat/frontend-vue/package.json demo/video-chat/frontend-vue/package-lock.json ./
RUN npm ci

COPY demo/video-chat/frontend-vue/ ./
RUN npm run build

EXPOSE 5174

CMD ["npm", "run", "preview", "--", "--host", "0.0.0.0", "--port", "5174"]
