import process from "node:process";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { resolve, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { build, loadEnv } from "vite";

/*
| La landing de un producto (ADR 0108): `node scripts/build-marketing.mjs agendauno`
| (o `turnouno`). Pre-genera con HTML completo la portada y las páginas por giro de ese
| producto, su sitemap y su robots, en dist/{producto}; sus recursos van en
| /assets-{producto}/ para no chocar con los de la aplicación (dist/app). Lo demás de
| su dominio (registro, entrar, panel…) lo sirve la aplicación.
*/
const PRODUCTOS = ["agendauno", "turnouno"];
const producto = process.argv[2] ?? "agendauno";
if (!PRODUCTOS.includes(producto)) {
  throw new Error(
    `Producto desconocido: ${producto} (${PRODUCTOS.join(", ")}).`,
  );
}
process.env.VITE_PRODUCTO = producto;

const root = fileURLToPath(new URL("../", import.meta.url));
const salida = `dist/${producto}`;
const ssr = `dist-ssr/${producto}`;
await build({
  root,
  build: {
    manifest: true,
    outDir: salida,
    assetsDir: `assets-${producto}`,
    emptyOutDir: true,
  },
});
await build({
  root,
  build: {
    ssr: "src/entry-marketing.ts",
    outDir: ssr,
    emptyOutDir: true,
    copyPublicDir: false,
    rollupOptions: { output: { entryFileNames: "entry-marketing.mjs" } },
  },
});
const { render, rutasMarketing, seoParaRuta, renderSeoHead, SITE_URL } =
  await import(pathToFileURL(resolve(root, ssr, "entry-marketing.mjs")).href);
const manifest = JSON.parse(
  await readFile(resolve(root, salida, ".vite/manifest.json"), "utf8"),
);
const env = loadEnv("production", root, "");
const verification = env.GOOGLE_SITE_VERIFICATION?.trim();
if (verification && !/^[\w-]+$/.test(verification))
  throw new Error(
    "GOOGLE_SITE_VERIFICATION debe ser el token público, no una etiqueta HTML.",
  );

/*
| Íconos de la pestaña y de inicio (iOS) de cada landing. index.html trae los de
| AgendaUno; TurnoUno aún no tiene logotipo: usa sus íconos provisionales de la PWA
| (public/assets/pwa), los mismos de sus negocios sin logo.
*/
const ICONOS = {
  turnouno: [
    '<link rel="icon" type="image/png" sizes="192x192" href="/assets/pwa/turnouno-192.png" />',
    '<link rel="icon" type="image/png" sizes="512x512" href="/assets/pwa/turnouno-512.png" />',
    '<link rel="apple-touch-icon" href="/assets/pwa/turnouno-192.png" />',
  ],
};
function iconos(html) {
  const propios = ICONOS[producto];
  if (!propios) return html;
  const sinIconos = html.replace(
    /\s*<link\b[^>]*\brel="(?:icon|apple-touch-icon)"[^>]*>/g,
    "",
  );
  // Justo después del charset, que va primero.
  const charset = /<meta charset="[^"]*"\s*\/?>/i;
  if (!charset.test(sinIconos))
    throw new Error("index.html sin <meta charset>: ¿dónde van los íconos?");
  return sinIconos.replace(
    charset,
    (meta) => `${meta}\n    ${propios.join("\n    ")}`,
  );
}
const template = iconos(
  await readFile(resolve(root, salida, "index.html"), "utf8"),
);

function head(html, seo) {
  return html.replace(
    /<!-- marketing:head -->[\s\S]*?<!-- \/marketing:head -->/,
    () =>
      `<!-- marketing:head -->\n${renderSeoHead(seo)}\n<!-- /marketing:head -->`,
  );
}
for (const path of rutasMarketing) {
  const { html, modules } = await render(path);
  let page = head(template, seoParaRuta(path)).replace(
    '<div id="app"></div>',
    () => `<div id="app">${html}</div>`,
  );
  const styles = new Set();
  const visited = new Set();
  function collect(id) {
    if (visited.has(id)) return;
    visited.add(id);
    const chunk = manifest[id];
    if (!chunk) return;
    for (const css of chunk.css ?? []) styles.add(css);
    for (const dependency of chunk.imports ?? []) collect(dependency);
  }
  modules.forEach(collect);
  for (const css of styles) {
    if (!page.includes(`href="/${css}"`))
      page = page.replace(
        "</head>",
        `<link rel="stylesheet" href="/${css}">\n</head>`,
      );
  }
  if (verification)
    page = page.replace(
      "</head>",
      `<meta name="google-site-verification" content="${verification}">\n</head>`,
    );
  const destination = resolve(
    root,
    salida,
    path === "/" ? "index.html" : `${path.slice(1)}/index.html`,
  );
  await mkdir(dirname(destination), { recursive: true });
  await writeFile(destination, page);
}
await writeFile(
  resolve(root, salida, "sitemap.xml"),
  `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${rutasMarketing.map((path) => `\n  <url><loc>${SITE_URL}${path}</loc></url>`).join("")}\n</urlset>\n`,
);
await writeFile(
  resolve(root, salida, "robots.txt"),
  `User-agent: *\nAllow: /\n\nSitemap: ${SITE_URL}/sitemap.xml\n`,
);
console.log(
  `Landing de ${producto}: ${rutasMarketing.length} páginas con HTML completo en ${salida}.`,
);
