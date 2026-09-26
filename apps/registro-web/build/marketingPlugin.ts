import { readFile, stat } from "node:fs/promises";
import { isAbsolute, relative, resolve } from "node:path";
import type { Plugin } from "vite";
import {
  rutasMarketing,
  seoParaRuta,
  renderSeoHead,
} from "../src/marketing/seoConfig.ts";

/** Mantiene los metadatos del servidor de desarrollo y preview coherentes. */
export function marketingPlugin(): Plugin {
  return {
    name: "agendauno-marketing",
    transformIndexHtml(html, context) {
      const path = context.originalUrl ?? context.path;
      return html.replace(
        /<!-- marketing:head -->[\s\S]*?<!-- \/marketing:head -->/,
        `<!-- marketing:head -->\n${renderSeoHead(seoParaRuta(path))}\n<!-- /marketing:head -->`,
      );
    },
    configurePreviewServer(server) {
      server.middlewares.use((request, response, next) => {
        const path =
          new URL(request.url ?? "/", "http://localhost").pathname.replace(
            /\/$/,
            "",
          ) || "/";
        if (request.method !== "GET" && request.method !== "HEAD")
          return next();
        if (/\.[a-z0-9]+$/i.test(path) || path.startsWith("/assets/")) {
          const notFound = () => {
            response.statusCode = 404;
            response.setHeader("Content-Type", "text/plain; charset=utf-8");
            response.end(
              request.method === "HEAD" ? undefined : "Archivo no encontrado",
            );
          };
          const directory = resolve(
            server.config.root,
            server.config.build.outDir,
          );
          let asset: string;
          try {
            asset = resolve(directory, `.${decodeURIComponent(path)}`);
          } catch {
            return notFound();
          }
          const relativePath = relative(directory, asset);
          if (relativePath.startsWith("..") || isAbsolute(relativePath))
            return notFound();
          void stat(asset)
            .then((info) => (info.isFile() ? next() : notFound()))
            .catch(notFound);
          return;
        }
        const host = (request.headers.host ?? "").split(":")[0];
        const commercial = [
          "localhost",
          "127.0.0.1",
          "agendauno.mx",
          "www.agendauno.mx",
        ].includes(host ?? "");
        const file =
          commercial && rutasMarketing.includes(path)
            ? path === "/"
              ? "index.html"
              : `${path.slice(1)}/index.html`
            : "app.html";
        void readFile(
          resolve(server.config.root, server.config.build.outDir, file),
          "utf8",
        )
          .then((html) => {
            response.setHeader("Content-Type", "text/html; charset=utf-8");
            response.end(request.method === "HEAD" ? undefined : html);
          })
          .catch(next);
      });
    },
  };
}
