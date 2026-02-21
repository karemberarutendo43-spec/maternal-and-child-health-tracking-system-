import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icons/icon.svg'],
      manifest: {
        name: 'MCH System',
        short_name: 'MCH',
        start_url: '/mch_system/public/pwa/',
        scope: '/mch_system/public/pwa/',
        display: 'standalone',
        background_color: '#ffffff',
        theme_color: '#0f172a',
        icons: [
          { src: '/mch_system/public/pwa/icons/icon.svg', sizes: 'any', type: 'image/svg+xml' }
        ]
      }
    })
  ],
  base: '/mch_system/public/pwa/',
  server: {
    // In dev, proxy API calls to Apache/PHP on the same machine.
    // Adjust target if your XAMPP runs on a different host/port.
    proxy: {
      '/mch_system/api': {
        target: 'http://localhost',
        changeOrigin: true
      }
    }
  }
});
