import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Built into the Laravel public folder so the server serves the console from the company's own origin.
export default defineConfig({
  base: '/admin/',
  plugins: [react()],
  build: { outDir: '../backend/public/admin', emptyOutDir: true, sourcemap: false },
  server: {
    proxy: { '/api': 'http://127.0.0.1:8000', '/sanctum': 'http://127.0.0.1:8000' },
  },
  test: { environment: 'jsdom', globals: true, setupFiles: ['./src/test-setup.ts'] },
});
