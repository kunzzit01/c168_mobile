import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const phpTarget = process.env.VITE_PHP_PROXY_TARGET || "http://127.0.0.1/c168_mobile";

export default defineConfig(({ mode }) => ({
  plugins: [react()],
  base: mode === "production" ? "/c168_mobile/frontend/dist/" : "/",
  server: {
    port: 5174,
    strictPort: true,
    proxy: {
      "/api": { target: phpTarget, changeOrigin: true },
      "/images": { target: phpTarget, changeOrigin: true },
    },
  },
  build: {
    outDir: "dist",
    emptyOutDir: true,
  },
}));
