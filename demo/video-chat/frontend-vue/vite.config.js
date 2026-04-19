import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  server: {
    host: process.env.VIDEOCHAT_VUE_HOST || '127.0.0.1',
    port: Number.parseInt(process.env.VIDEOCHAT_VUE_PORT || '5176', 10),
  },
  preview: {
    host: process.env.VIDEOCHAT_VUE_HOST || '127.0.0.1',
    port: Number.parseInt(process.env.VIDEOCHAT_VUE_PORT || '5176', 10),
  },
});
