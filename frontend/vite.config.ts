import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // En développement, l'API Laravel est servie sous la même origine que React,
    // condition du mode cookie de Sanctum (CDC 9.5, PC-36).
    proxy: {
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
      '/up': 'http://localhost:8000',
    },
  },
})
