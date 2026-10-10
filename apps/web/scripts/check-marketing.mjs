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
| (ni en el texto ni en los atributos, salvo el enlace discreto que lo presenta y las
| excepciones de abajo), sus íconos, y la aplicación sin indexar. Si el producto aún
| no recibe registros (el superadmin lo cerró: prelanzamiento), nada ofrece prueba
| gratis, registrarse ni contratar: todo lleva a la lista de interesados; si los recibe,
| no queda nada de la lista de interesados.
*/
const producto = process.argv[2] ?? "agendauno";
const OTRO = { agendauno: "TurnoUno", turnouno: "AgendaUno" }[producto];
const MARCA = { agendauno: "AgendaUno", turnouno: "TurnoUno" }[producto];
assert.ok(OTRO, `producto desconocido: ${producto}`);

const root = fileURLToPath(new URL("../", import.meta.url));
const dist = resolve(root, "dist", producto);
// La misma lista que prerenderizó el build (seoConfig.paginasMarketing), y si el
// producto recibía registros al pre-generarla (el respaldo de precios.ts).
const {
  paginasMarketing,
  SITE_URL,
  REGISTRO_ABIERTO_POR_OMISION: registroAbierto,
  FRASES_SOLO_CON_REGISTRO,
  FRASES_SOLO_EN_PRELANZAMIENTO,
  frasesEncontradas,
} = await import(
  pathToFileURL(resolve(root, "dist-ssr", producto, "entry-marketing.mjs")).href
);
assert.equal(typeof registroAbierto, "boolean", "registro del producto");

/*
| La marca del otro producto en un atributo (un enlace, un correo, un metadato, un
| texto alternativo…) también falla. Excepciones justificadas:
| - el id del JSON-LD de cada ruta (`agendauno-route-jsonld`): un nombre técnico común
|   a los dos builds (lib/seo.ts lo reemplaza al navegar); no se ve ni se indexa;
| - el `mailto:` de cotizar en Precios: el buzón de ventas es uno para la plataforma
|   (lo configura el superadmin, ConfiguracionPlataforma::ventasCorreo; respaldo en
|   precios.ts) y el texto del botón es «Contáctanos»;
| - lo que va dentro del enlace discreto al otro producto (`.tu-modalidad-otra`).
*/
const OTRO_EN_ATRIBUTO = new RegExp(OTRO, "i");
const EXCEPCIONES_MARCA = [
  { selector: "script#agendauno-route-jsonld", atributo: "id" },
  { selector: '.precios-contacto a[href^="mailto:"]', atributo: "href" },
  { selector: ".tu-modalidad-otra, .tu-modalidad-otra *", atributo: "*" },
];
const esExcepcion = (elemento, atributo) =>
  EXCEPCIONES_MARCA.some(
    (e) =>
      (e.atributo === "*" || e.atributo === atributo) &&
      elemento.matches(e.selector),
  );
function marcaAjenaEnAtributos(document) {
  const hallazgos = [];
  for (const elemento of document.querySelectorAll("*")) {
    for (const { name, value } of elemento.attributes) {
      if (OTRO_EN_ATRIBUTO.test(value) && !esExcepcion(elemento, name)) {
        hallazgos.push(
          `<${elemento.tagName.toLowerCase()} ${name}="${value}">`,
        );
      }
    }
  }
  return hallazgos;
}

/*
| Según el registro del producto, frases que no pueden aparecer
| (src/marketing/prelanzamiento.ts, las mismas que revisan las pruebas): cerrado, las
| que ofrecen probar, registrarse o contratar; abierto, las de la lista de interesados.
| Se buscan en el texto visible, el título, los metadatos, el JSON-LD y los atributos
| que se leen (alt, title, aria-label).
*/
const frasesFuera = registroAbierto
  ? FRASES_SOLO_EN_PRELANZAMIENTO
  : FRASES_SOLO_CON_REGISTRO;
function textosLegibles(document) {
  const atributos = [
    ...document.querySelectorAll(
      'meta[name="description"], meta[property^="og:"], meta[name^="twitter:"]',
    ),
  ].map((m) => m.getAttribute("content") ?? "");
  const accesibles = [
    ...document.querySelectorAll("[alt], [title], [aria-label]"),
  ].flatMap((e) =>
    ["alt", "title", "aria-label"]
      .map((a) => e.getAttribute(a))
      .filter((v) => v !== null),
  );
  return [
    document.title,
    document.body.textContent,
    ...atributos,
    ...accesibles,
    ...[...document.querySelectorAll('script[type="application/ld+json"]')].map(
      (s) => s.textContent,
    ),
  ].join("\n");
}
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
  // Ni en los atributos (enlaces, correos, metadatos…), salvo las excepciones.
  assert.deepEqual(
    marcaAjenaEnAtributos(document),
    [],
    `${route}: atributos con la marca ${OTRO}`,
  );
  // Título y descripción a la medida de los buscadores.
  assert.ok(
    document.title.length <= 60,
    `${route}: título de ${document.title.length} caracteres (máx. 60)`,
  );
  const descripcion = document
    .querySelector('meta[name="description"]')
    .getAttribute("content");
  assert.ok(
    descripcion.length <= 160,
    `${route}: descripción de ${descripcion.length} caracteres (máx. 160)`,
  );
  // Imagen Open Graph con su texto alternativo.
  assert.ok(
    (document.querySelector('meta[property="og:image:alt"]')?.content ?? "")
      .length > 5,
    `${route}: og:image:alt`,
  );
  // Íconos de la pestaña: los del producto (TurnoUno, los provisionales de su PWA),
  // y que existan.
  const iconos = [
    ...document.querySelectorAll(
      'link[rel="icon"], link[rel="apple-touch-icon"]',
    ),
  ].map((l) => l.getAttribute("href"));
  assert.ok(iconos.length >= 2, `${route}: íconos`);
  for (const icono of iconos) {
    if (producto === "turnouno") {
      assert.match(icono, /^\/assets\/pwa\/turnouno-/, `${route}: ícono`);
    }
    await existe(icono.replace(/^\//, "").replace(/\?.*$/, ""));
  }
  // Lo que no puede decir según su registro (texto, metadatos, JSON-LD y atributos).
  assert.deepEqual(
    frasesEncontradas(textosLegibles(document), frasesFuera),
    [],
    registroAbierto
      ? `${route}: con registro abierto, nada de la lista de interesados`
      : `${route}: prelanzamiento sin ofrecer prueba ni registro`,
  );
  // Sin registro abierto: los botones piden avisar y no se ofrece buscar negocios
  // (aún no los hay). Con él, la prueba y el registro, como siempre.
  if (!registroAbierto) {
    assert.match(
      document.querySelector(".tu-public-register")?.textContent ?? "",
      /Quiero que me avisen/,
      `${route}: el botón del menú pide avisar`,
    );
    assert.ok(
      !document.querySelector('a[href="/negocios"]'),
      `${route}: sin «Encuentra tu negocio»`,
    );
    if (vista === "modalidad") {
      assert.ok(
        document.querySelector('[data-sello="proximamente"]') &&
          !document.querySelector('[data-sello="prueba"]') &&
          !document.querySelector('[data-sello="cancelacion"]'),
        `${route}: sello «Abre pronto», sin prueba ni permanencia`,
      );
    }
  } else {
    assert.match(
      document.querySelector(".tu-public-register")?.textContent ?? "",
      /Probar gratis/,
      `${route}: el botón del menú ofrece la prueba`,
    );
    assert.match(
      document
        .querySelector('meta[name="description"]')
        .getAttribute("content"),
      /gratis durante \d+ días, sin tarjeta\.$/,
      `${route}: la descripción cierra con la prueba`,
    );
    if (vista === "modalidad") {
      assert.ok(
        document.querySelector('[data-sello="prueba"]'),
        `${route}: sello de la prueba`,
      );
    }
  }
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
  `SEO de ${producto} validado: ${routes.length} páginas, HTML sin JS, marca (texto y atributos), íconos, menú, imágenes, CSS, canonical, Open Graph, JSON-LD, ${registroAbierto ? "registro abierto (prueba, sin lista de interesados)" : "prelanzamiento sin prueba ni registro"} y aplicación sin indexar.`,
);
