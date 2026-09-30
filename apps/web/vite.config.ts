import { fileURLToPath, URL } from "node:url";

import tailwindcss from "@tailwindcss/vite";
import vue from "@vitejs/plugin-vue";
import { defineConfig } from "vitest/config";
import { marketingPlugin } from "./build/marketingPlugin.ts";

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss(), marketingPlugin()],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  server: {
    port: 5175,
    strictPort: true,
  },
  // Mapas de origen sin anunciarlos al navegador: la imagen de producción los saca
  // de lo público y la API los usa para traducir los errores (ADR 0082).
  build: {
    sourcemap: "hidden",
  },
  test: {
    environment: "jsdom",
    globals: true,
  },
});
