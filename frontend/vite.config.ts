import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";
import path from "node:path";
import { fileURLToPath } from "node:url";
const rootDir = path.dirname(fileURLToPath(import.meta.url));
export default defineConfig({ plugins: [react(), tailwindcss(), VitePWA({ registerType: "autoUpdate", manifest: { name: "Laboratory and Facility Management System", short_name: "LFMS", description: "Laboratory and Facility Management System", theme_color: "#f8fafc", background_color: "#f8fafc", display: "standalone", start_url: "/", icons: [] }})], resolve: { alias: { "@": path.resolve(rootDir, "src") } } });
