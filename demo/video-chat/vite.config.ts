import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

function getVendorChunkName(id: string) {
  const groups: Array<[string, string[]]> = [
    ['vue-vendor', ['vue', 'vue-router', 'pinia']],
    ['ui-vendor', ['@vueuse/core', '@vueuse/components']],
    ['media-vendor', ['webrtc-adapter', 'recordrtc', 'wavesurfer.js', 'socket.io-client']],
    ['chart-vendor', ['chart.js', 'vue-chartjs']],
    ['utils-vendor', ['date-fns', 'uuid', 'crypto-js', 'file-saver']],
  ]

  if (!id.includes('node_modules/')) {
    return undefined
  }

  for (const [chunkName, packages] of groups) {
    if (packages.some((pkg) => id.includes(`/node_modules/${pkg}/`) || id.includes(`/node_modules/${pkg}.`))) {
      return chunkName
    }
  }

  return undefined
}

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  server: {
    port: 3000,
    host: true,
    cors: true,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
        secure: false,
      },
      '/ws': {
        target: 'ws://localhost:8080',
        ws: true,
        changeOrigin: true,
      }
    }
  },
  build: {
    target: 'esnext',
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: true,
    rollupOptions: {
      output: {
        manualChunks(id) {
          return getVendorChunkName(id)
        }
      }
    },
    chunkSizeWarningLimit: 1000
  },
  optimizeDeps: {
    include: [
      'vue',
      'vue-router',
      'pinia',
      '@vueuse/core',
      'socket.io-client',
      'webrtc-adapter',
      'recordrtc',
      'wavesurfer.js',
      'chart.js',
      'vue-chartjs',
      'date-fns',
      'uuid',
      'crypto-js',
      'file-saver'
    ]
  },
  define: {
    __VUE_OPTIONS_API__: true,
    __VUE_PROD_DEVTOOLS__: false
  }
})
