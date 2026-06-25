import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const phpTarget = env.VITE_PHP_PROXY_TARGET || "http://127.0.0.1:8000";

  return {
    plugins: [
      react(),
      {
        name: "legacy-deleted-log-redirect",
        configureServer(server) {
          server.middlewares.use((req, res, next) => {
            const raw = req.url || "";
            const pathname = raw.split("?")[0] || "";
            if (pathname === "/deleted-log.php" || pathname === "/deleted_log.php") {
              const q = raw.includes("?") ? raw.slice(raw.indexOf("?")) : "";
              res.statusCode = 302;
              res.setHeader("Location", `/deleted-log/3f5cf41e-53c2-45c5-a2c2-92e26352d8a1${q}`);
              res.end();
              return;
            }
            next();
          });
        },
      },
    ],
    base: mode === "production" ? "/frontend/dist/" : "/",
    server: {
      proxy: {
        "/dashboard.php": { target: phpTarget, changeOrigin: true },
        "/member.php": { target: phpTarget, changeOrigin: true },
        "/owner_secondary_password.php": { target: phpTarget, changeOrigin: true },
        "/api": { target: phpTarget, changeOrigin: true },
        "/reset-password.php": { target: phpTarget, changeOrigin: true },
        "/images": { target: phpTarget, changeOrigin: true },
        "/js": { target: phpTarget, changeOrigin: true },
      },
    },
    build: {
      outDir: "dist",
      emptyOutDir: true,
    },
  };
});