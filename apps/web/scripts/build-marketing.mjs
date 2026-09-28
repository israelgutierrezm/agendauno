import { readFile, writeFile, mkdir } from "node:fs/promises";
import { resolve, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { build, loadEnv } from "vite";

const root = fileURLToPath(new URL("../", import.meta.url));
await build({ root, build: { manifest: true } });
await build({
  root,
  build: {
    ssr: "src/entry-marketing.ts",
    outDir: "dist-ssr",
    copyPublicDir: false,
    rollupOptions: { output: { entryFileNames: "entry-marketing.mjs" } },
  },
});
const { render, rutasMarketing, seoParaRuta, renderSeoHead, SITE_URL } =
  await import(
    pathToFileURL(resolve(root, "dist-ssr/entry-marketing.mjs")).href
  );
const template = await readFile(resolve(root, "dist/index.html"), "utf8");
const manifest = JSON.parse(
  await readFile(resolve(root, "dist/.vite/manifest.json"), "utf8"),
);
const env = loadEnv("production", root, "");
const verification = env.GOOGLE_SITE_VERIFICATION?.trim();
if (verification && !/^[\w-]+$/.test(verification))
  throw new Error(
    "GOOGLE_SITE_VERIFICATION debe ser el token público, no una etiqueta HTML.",
  );

function head(html, seo) {
  return html.replace(
    /<!-- marketing:head -->[\s\S]*?<!-- \/marketing:head -->/,
    () =>
      `<!-- marketing:head -->\n${renderSeoHead(seo)}\n<!-- /marketing:head -->`,
  );
}
const fallback = head(template, seoParaRuta("/app"));
await writeFile(resolve(root, "dist/app.html"), fallback);
// Archivos explícitos para hosts estáticos; el resto de rutas usa app.html.
for (const path of [
  "registro",
  "entrar",
  "activar",
  "negocios",
  "aviso-de-privacidad",
]) {
  await mkdir(resolve(root, "dist", path), { recursive: true });
  await writeFile(
    resolve(root, "dist", path, "index.html"),
    head(template, seoParaRuta(`/${path}`)),
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
    "dist",
    path === "/" ? "index.html" : `${path.slice(1)}/index.html`,
  );
  await mkdir(dirname(destination), { recursive: true });
  await writeFile(destination, page);
}
await writeFile(
  resolve(root, "dist/sitemap.xml"),
  `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${rutasMarketing.map((path) => `\n  <url><loc>${SITE_URL}${path}</loc></url>`).join("")}\n</urlset>\n`,
);
console.log(
  `Marketing: ${rutasMarketing.length} páginas con HTML completo; aplicación separada en app.html.`,
);
