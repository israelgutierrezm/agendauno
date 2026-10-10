import { readFile, stat } from "node:fs/promises";
import { isAbsolute, relative, resolve } from "node:path";
import type { Plugin } from "vite";
import { seoParaRuta, renderSeoHead } from "../src/marketing/seoConfig.ts";
import {
  PRODUCTOS,
  PRODUCTOS_LISTA,
  type Producto,
} from "../src/lib/producto.ts";

/**
 * La landing que sirve un host: la del producto en su dominio o `www.` (ADR 0108); en
 * localhost, la de AgendaUno. `null` en el subdominio de un negocio u otro host.
 */
function landingDelHost(host: string): Producto | null {
  if (host === "localhost" || host === "127.0.0.1") {
    return "agendauno";
  }
  return (
    PRODUCTOS_LISTA.find((id) => {
      const dominio = PRODUCTOS[id].dominio;
      return host === dominio || host === `www.${dominio}`;
    }) ?? null
  );
}

async function esArchivo(ruta: string): Promise<boolean> {
  try {
    return (await stat(ruta)).isFile();
  } catch {
    return false;
  }
}

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
    // Como nginx en producción: dist/{producto} para la landing de su dominio y
    // dist/app para todo lo demás (también los subdominios de los negocios).
    configurePreviewServer(server) {
      const dist = resolve(server.config.root, server.config.build.outDir);
      server.middlewares.use((request, response, next) => {
        if (request.method !== "GET" && request.method !== "HEAD")
          return next();
        const url = new URL(request.url ?? "/", "http://localhost");
        const path = url.pathname.replace(/\/$/, "") || "/";
        const host = (request.headers.host ?? "").split(":")[0] ?? "";
        const landing = landingDelHost(host);
        const notFound = () => {
          response.statusCode = 404;
          response.setHeader("Content-Type", "text/plain; charset=utf-8");
          response.end(
            request.method === "HEAD" ? undefined : "Archivo no encontrado",
          );
        };
        const dentro = (base: string, ruta: string): string | null => {
          let archivo: string;
          try {
            archivo = resolve(dist, base, `.${decodeURIComponent(ruta)}`);
          } catch {
            return null;
          }
          const rel = relative(resolve(dist, base), archivo);
          return rel.startsWith("..") || isAbsolute(rel) ? null : archivo;
        };
        const sitios = [...(landing ? [landing] : []), "app"];
        if (/\.[a-z0-9]+$/i.test(path) || path.startsWith("/assets")) {
          void (async () => {
            for (const sitio of sitios) {
              const archivo = dentro(sitio, path);
              if (archivo !== null && (await esArchivo(archivo))) {
                request.url = `/${sitio}${url.pathname}${url.search}`;
                return next();
              }
            }
            notFound();
          })();
          return;
        }
        void (async () => {
          const pagina =
            landing === null
              ? null
              : dentro(
                  landing,
                  path === "/" ? "/index.html" : `${path}/index.html`,
                );
          const archivo =
            pagina !== null && (await esArchivo(pagina))
              ? pagina
              : resolve(dist, "app/app.html");
          const html = await readFile(archivo, "utf8");
          response.setHeader("Content-Type", "text/html; charset=utf-8");
          response.end(request.method === "HEAD" ? undefined : html);
        })().catch(next);
      });
    },
  };
}
