import process from "node:process";
import assert from "node:assert/strict";
import { readFile, access } from "node:fs/promises";
import { fileURLToPath, pathToFileURL } from "node:url";
import { resolve } from "node:path";
import { JSDOM } from "jsdom";

/*
| Revisa la landing pre-generada de un producto (ADR 0108):
| `node scripts/check-marketing.mjs agendauno` (o `turnouno`). Cada página con HTML
| completo, su SEO con el dominio y la marca del producto, sin la marca del otro
| (salvo el enlace discreto que lo presenta), y la aplicación sin indexar.
*/
const producto = process.argv[2] ?? "agendauno";
const OTRO = { agendauno: "TurnoUno", turnouno: "AgendaUno" }[producto];
const MARCA = { agendauno: "AgendaUno", turnouno: "TurnoUno" }[producto];
assert.ok(OTRO, `producto desconocido: ${producto}`);

const root = fileURLToPath(new URL("../", import.meta.url));
const dist = resolve(root, "dist", producto);
// La misma lista que prerenderizó el build (seoConfig.paginasMarketing).
const { paginasMarketing, SITE_URL } = await import(
  pathToFileURL(resolve(root, "dist-ssr", producto, "entry-marketing.mjs")).href
);
const SITE = SITE_URL;
const routes = paginasMarketing.map((p) => p.path);
assert.equal(routes[0], "/", "la portada es la página del producto");
const manifest = JSON.parse(
  await readFile(resolve(dist, ".vite/manifest.json"), "utf8"),
);
// CSS propio de cada vista: tiene que llegar enlazado en el HTML sin JS.
const VISTAS = {
  modalidad: "src/views/ModalidadView.vue",
  solucion: "src/views/SolucionView.vue",
};
const sitemap = await readFile(resolve(dist, "sitemap.xml"), "utf8");
const robots = await readFile(resolve(dist, "robots.txt"), "utf8");
assert.ok(
  robots.includes(`Sitemap: ${SITE}/sitemap.xml`),
  "robots con su sitemap",
);
// Lo que existe en el dominio: la landing y, si no está en ella, la aplicación.
const existe = async (relativo) => {
  for (const base of [dist, resolve(root, "dist/app")]) {
    try {
      await access(resolve(base, relativo));
      return;
    } catch {
      // sigue con la otra
    }
  }
  assert.fail(`no existe ${relativo}`);
};
const titles = new Set();
for (const { path: route, vista, modo, name, giro } of paginasMarketing) {
  const html = await readFile(
    resolve(
      dist,
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
    `${SITE}${route}`,
  );
  assert.equal(document.querySelectorAll('meta[name="description"]').length, 1);
  // Open Graph y JSON-LD propios de cada página (la imagen, un archivo publicado).
  const og = (prop) =>
    document.querySelector(`meta[property="og:${prop}"]`)?.content;
  assert.equal(og("title"), document.title, `${route}: og:title`);
  assert.equal(og("url"), `${SITE}${route}`, `${route}: og:url`);
  assert.equal(og("site_name"), MARCA, `${route}: og:site_name`);
  assert.ok(og("description")?.length > 50, `${route}: og:description`);
  assert.ok((og("image") ?? "").startsWith(`${SITE}/`), `${route}: og:image`);
  await existe(og("image").replace(`${SITE}/`, ""));
  const grafo = JSON.parse(
    document.getElementById("agendauno-route-jsonld")?.textContent ?? "{}",
  )["@graph"];
  assert.ok(Array.isArray(grafo), `${route}: JSON-LD`);
  const pagina = grafo.find((n) => n["@type"] === "WebPage");
  assert.equal(pagina?.url, `${SITE}${route}`, `${route}: WebPage`);
  assert.equal(
    grafo.find((n) => n["@type"] === "Organization")?.name,
    MARCA,
    `${route}: organización`,
  );
  if (vista === "solucion") {
    // Inicio → giro.
    const miga = grafo.find((n) => n["@type"] === "BreadcrumbList");
    assert.deepEqual(
      miga?.itemListElement.map((i) => i.item),
      [`${SITE}/`, `${SITE}${route}`],
      `${route}: miga de pan`,
    );
  }
  // La marca del producto, nunca la del otro (salvo el enlace que lo presenta).
  const sinEnlaceAlOtro = document.cloneNode(true);
  sinEnlaceAlOtro
    .querySelectorAll(".tu-modalidad-otra")
    .forEach((nodo) => nodo.remove());
  assert.ok(
    !sinEnlaceAlOtro.body.textContent.includes(OTRO),
    `${route}: sin la marca ${OTRO}`,
  );
  assert.ok(!document.title.includes(OTRO), `${route}: título sin ${OTRO}`);
  // Registro con su modalidad y, en la página de un solo giro, con su giro.
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
    `${route}: el botón del menú lleva al registro`,
  );
  // Menú del producto: Funciones · Precios · Preguntas, también sin JS.
  for (const destino of ["/#soluciones", "/#precios", "/#preguntas"]) {
    assert.ok(
      document.querySelector(`.tu-public-sections a[href="${destino}"]`),
      `${route}: menú lleva a ${destino}`,
    );
  }
  if (vista === "modalidad") {
    for (const id of ["soluciones", "precios", "preguntas"]) {
      assert.ok(document.getElementById(id), `${route}: sección #${id}`);
    }
  }
  assert.ok(!document.querySelector(".tu-public-back"), `${route}: sin Inicio`);
  assert.ok(sitemap.includes(`<loc>${SITE}${route}</loc>`));
  titles.add(document.title);
  for (const css of manifest[VISTAS[vista]]?.css ?? []) {
    assert.ok(
      document.querySelector(`link[rel="stylesheet"][href="/${css}"]`),
      `${route} (${name}): CSS de la vista disponible sin JS`,
    );
  }
  for (const image of document.querySelectorAll("img")) {
    assert.ok(image.hasAttribute("alt"), `${route}: imagen sin alternativa`);
    await existe(image.getAttribute("src").replace(/^\//, ""));
  }
  for (const style of document.querySelectorAll(
    'link[rel="stylesheet"][href^="/"]',
  )) {
    await access(resolve(dist, style.getAttribute("href").slice(1)));
  }
}
assert.equal(titles.size, routes.length, "un título propio por página");
// La aplicación (registro, entrar, panel…) nunca se indexa.
const app = await readFile(resolve(root, "dist/app/app.html"), "utf8");
assert.ok(app.includes('content="noindex,follow"'), "app.html sin indexar");
assert.ok(!app.includes('rel="canonical"'), "app.html sin canonical");
assert.ok(!app.includes("application/ld+json"), "app.html sin JSON-LD");
assert.ok(!sitemap.includes("/registro"));
console.log(
  `SEO de ${producto} validado: ${routes.length} páginas, HTML sin JS, marca, menú, imágenes, CSS, canonical, Open Graph, JSON-LD y aplicación sin indexar.`,
);
