import assert from "node:assert/strict";
import { readFile, access } from "node:fs/promises";
import { fileURLToPath, pathToFileURL } from "node:url";
import { resolve } from "node:path";
import { JSDOM } from "jsdom";

const root = fileURLToPath(new URL("../", import.meta.url));
// La misma lista que prerenderizó el build (seoConfig.paginasMarketing).
const { paginasMarketing } = await import(
  pathToFileURL(resolve(root, "dist-ssr/entry-marketing.mjs")).href
);
const routes = paginasMarketing.map((p) => p.path);
assert.ok(routes.includes("/clases") && routes.includes("/citas"));
const manifest = JSON.parse(
  await readFile(resolve(root, "dist/.vite/manifest.json"), "utf8"),
);
// CSS propio de cada vista: tiene que llegar enlazado en el HTML sin JS.
const VISTAS = {
  landing: "src/views/LandingView.vue",
  modalidad: "src/views/ModalidadView.vue",
  solucion: "src/views/SolucionView.vue",
};
const sitemap = await readFile(resolve(root, "dist/sitemap.xml"), "utf8");
const titles = new Set();
for (const { path: route, vista, modo, name, giro } of paginasMarketing) {
  const html = await readFile(
    resolve(
      root,
      "dist",
      route === "/" ? "index.html" : `${route.slice(1)}/index.html`,
    ),
    "utf8",
  );
  const document = new JSDOM(html).window.document;
  assert.equal(
    document.querySelectorAll("h1").length,
    1,
    `${route}: un encabezado principal`,
  );
  assert.ok(
    document.querySelector("h1")?.textContent.trim().length > 0,
    `${route}: encabezado con texto`,
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
  // Open Graph y JSON-LD propios de cada página (la imagen, un archivo publicado).
  const og = (prop) =>
    document.querySelector(`meta[property="og:${prop}"]`)?.content;
  assert.equal(og("title"), document.title, `${route}: og:title`);
  assert.equal(og("url"), `https://agendauno.mx${route}`, `${route}: og:url`);
  assert.ok(og("description")?.length > 50, `${route}: og:description`);
  assert.match(og("image") ?? "", /^https:\/\/agendauno\.mx\//);
  await access(
    resolve(root, "dist", og("image").replace("https://agendauno.mx/", "")),
  );
  const grafo = JSON.parse(
    document.getElementById("agendauno-route-jsonld")?.textContent ?? "{}",
  )["@graph"];
  assert.ok(Array.isArray(grafo), `${route}: JSON-LD`);
  const pagina = grafo.find((n) => n["@type"] === "WebPage");
  assert.equal(
    pagina?.url,
    `https://agendauno.mx${route}`,
    `${route}: WebPage`,
  );
  if (vista !== "landing") {
    // Inicio → Clases|Citas (→ giro, en las páginas por giro).
    const miga = grafo.find((n) => n["@type"] === "BreadcrumbList");
    assert.deepEqual(
      miga?.itemListElement.map((i) => i.item),
      [
        "https://agendauno.mx/",
        `https://agendauno.mx/${modo}`,
        ...(vista === "solucion" ? [`https://agendauno.mx${route}`] : []),
      ],
      `${route}: miga de pan`,
    );
  }
  // Registro con o sin `?modo=`.
  assert.ok(
    document.querySelector('a[href^="/registro"]'),
    `${route}: enlace al registro`,
  );
  if (modo) {
    // Con su modalidad y, en la página de un solo giro, con su giro (`?giro=`): el
    // «Probar gratis» del menú incluido.
    const registro = giro
      ? `/registro?modo=${modo}&giro=${giro}`
      : `/registro?modo=${modo}`;
    // Se compara el atributo: el selector de jsdom no acepta «&» en el valor.
    assert.ok(
      [...document.querySelectorAll('a[href^="/registro"]')].some(
        (a) => a.getAttribute("href") === registro,
      ),
      `${route}: registro con su modalidad${giro ? " y su giro" : ""}`,
    );
    assert.equal(
      document.querySelector(".tu-public-register")?.getAttribute("href"),
      registro,
      `${route}: «Probar gratis» del menú`,
    );
  }
  // Menú comercial: Clases · Citas · Precios, también sin JS.
  assert.ok(document.querySelector('.tu-public-sections a[href="/clases"]'));
  assert.ok(document.querySelector('.tu-public-sections a[href="/citas"]'));
  const precios = vista === "modalidad" ? `${route}#precios` : "/#precios";
  assert.ok(
    document.querySelector(`.tu-public-sections a[href="${precios}"]`),
    `${route}: «Precios» lleva a ${precios}`,
  );
  if (vista !== "solucion") {
    assert.ok(
      document.getElementById("precios"),
      `${route}: sección #precios a la que lleva el menú`,
    );
  }
  assert.ok(!document.querySelector(".tu-public-back"), `${route}: sin Inicio`);
  assert.ok(sitemap.includes(`<loc>https://agendauno.mx${route}</loc>`));
  titles.add(document.title);
  for (const css of manifest[VISTAS[vista]]?.css ?? []) {
    assert.ok(
      document.querySelector(`link[rel="stylesheet"][href="/${css}"]`),
      `${route} (${name}): CSS de la vista disponible sin JS`,
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
assert.equal(titles.size, routes.length, "un título propio por página");
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
  `SEO validado: ${routes.length} páginas, HTML sin JS, menú, imágenes, CSS, canonical, Open Graph, JSON-LD y fallback privado.`,
);
