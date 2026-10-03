import assert from "node:assert/strict";
import { readFile, access } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";
import { JSDOM } from "jsdom";

const root = fileURLToPath(new URL("../", import.meta.url));
const routes = [
  "/",
  ...[
    "pilates",
    "pole-dance",
    "academias",
    "barberias",
    "spas",
    "terapeutas",
    "crossfit-hyrox",
    "nutriologos",
  ].map((s) => `/software-para-${s}`),
];
const sitemap = await readFile(resolve(root, "dist/sitemap.xml"), "utf8");
const titles = new Set();
for (const route of routes) {
  const html = await readFile(
    resolve(
      root,
      "dist",
      route === "/" ? "index.html" : `${route.slice(1)}/index.html`,
    ),
    "utf8",
  );
  const document = new JSDOM(html).window.document;
  if (route === "/") {
    assert.equal(
      document.querySelector("h1")?.textContent.trim(),
      "Menos pendientes. Más tiempo para tus clientes.",
    );
    const beneficios = [...document.querySelectorAll("h2")].find(
      (n) => n.textContent.trim() === "Lo que necesitas para operar y crecer.",
    );
    assert.equal(
      beneficios?.querySelector(".tu-titulo-enfasis")?.textContent,
      "crecer",
    );
  }
  assert.equal(
    document.querySelectorAll("h1").length,
    1,
    `${route}: un encabezado principal`,
  );
  assert.ok(
    document.querySelector("#app")?.textContent.length > 1000,
    `${route}: contenido sin ejecutar JS`,
  );
  assert.equal(
    document.querySelector('link[rel="canonical"]')?.href,
    `https://agendauno.mx${route}`,
  );
  assert.equal(document.querySelectorAll('meta[name="description"]').length, 1);
  assert.ok(document.querySelector('a[href="/registro"]'));
  assert.ok(sitemap.includes(`<loc>https://agendauno.mx${route}</loc>`));
  titles.add(document.title);
  if (route !== "/") {
    assert.ok(
      document.querySelector('link[rel="stylesheet"][href*="SolucionView"]'),
      `${route}: CSS de la solución disponible sin JS`,
    );
  }
  for (const image of document.querySelectorAll("img")) {
    assert.ok(image.hasAttribute("alt"), `${route}: imagen sin alternativa`);
    await access(
      resolve(root, "dist", image.getAttribute("src").replace(/^\//, "")),
    );
  }
  for (const style of document.querySelectorAll(
    'link[rel="stylesheet"][href^="/"]',
  )) {
    await access(resolve(root, "dist", style.getAttribute("href").slice(1)));
  }
}
assert.equal(titles.size, routes.length);
for (const file of [
  "app.html",
  "registro/index.html",
  "entrar/index.html",
  "activar/index.html",
  "negocios/index.html",
]) {
  const html = await readFile(resolve(root, "dist", file), "utf8");
  assert.ok(html.includes('content="noindex,follow"'), file);
  assert.ok(!html.includes('rel="canonical"'), file);
  assert.ok(!html.includes("application/ld+json"), file);
}
assert.ok(!sitemap.includes("/registro"));
console.log(
  "SEO validado: 7 páginas, HTML sin JS, imágenes, CSS, canonical y fallback privado.",
);
