import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';
import tsconfigPaths from 'vite-tsconfig-paths';

function resolveAssetBase (value: string | undefined): string {
  const raw = (value ?? '').trim();
  if (raw === '' || raw === '/') {
    return '/';
  }
  return raw.replace(/\/?$/, '/');
}

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const base = resolveAssetBase(env.VITE_ASSET_BASE || process.env.VITE_ASSET_BASE);

  return {
    plugins: [
      react(),
      tsconfigPaths(),
    ],
    base,
    server: {
      proxy: {
        '/api': 'http://127.0.0.1:8787',
        '/health': 'http://127.0.0.1:8787',
      },
    },
    build: {
      assetsInlineLimit: 0,
      rollupOptions: {
        output: {
          manualChunks (id) {
            if (id.includes('node_modules')) {
              // if (id.includes('@heroui/')) {
              //   return 'heroui';
              // }
              if (id.includes('react-dom')) {
                return 'react-dom';
              }
              if (id.includes('react-router-dom')) {
                return 'react-router-dom';
              }
              if (id.includes('react-hot-toast')) {
                return 'react-hot-toast';
              }
            }
          },
        },
      },
    },
  };
});
