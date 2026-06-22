import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    // Output goes into the PHP package's public directory so Composer installs
    // include pre-built assets without requiring consumers to run npm.
    outDir: '../Vortos/src/FeatureFlagsAdmin/Public/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        islands: './src/main.tsx',
      },
    },
  },
  esbuild: {
    target: 'es2020',
  },
});
