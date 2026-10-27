import { resolve } from 'node:path'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import dts from 'vite-plugin-dts'

// Three entries, built and tree-shaken on their own: a host pays only for what it imports
// (Bit Form never receives the antd adapter). React and antd are the host's: never bundled.
export default defineConfig({
  plugins: [
    react(),
    dts({ include: ['src'], exclude: ['src/**/*.test.*'], outDir: 'dist', entryRoot: 'src' }),
  ],
  build: {
    lib: {
      entry: {
        core: resolve(__dirname, 'src/core/index.ts'),
        components: resolve(__dirname, 'src/components/index.ts'),
        antd: resolve(__dirname, 'src/antd/index.tsx'),
      },
      formats: ['es'],
      fileName: (_format, name) => `${name}.js`,
      cssFileName: 'styles',
    },
    rollupOptions: {
      external: ['react', 'react-dom', 'react/jsx-runtime', 'antd', '@ant-design/icons'],
      output: { preserveModules: false },
    },
    target: 'es2020',
    sourcemap: false,
    minify: 'esbuild',
    cssCodeSplit: false,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['tests/setup.ts'],
    include: ['tests/**/*.test.{ts,tsx}'],
  },
})
