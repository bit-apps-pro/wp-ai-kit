import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

/**
 * The playground: `pnpm playground` from frontend/. Serves playground/index.html
 * and imports the package sources straight from ../src, so edits reload live.
 */
export default defineConfig({
  root: 'playground',
  plugins: [react()],
  server: { port: 5178, strictPort: false, open: false },
  build: { outDir: '../dist-playground', emptyOutDir: true },
})
