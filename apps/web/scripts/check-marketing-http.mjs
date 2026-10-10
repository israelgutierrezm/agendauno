import assert from "node:assert/strict";
import http from "node:http";
import { resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { preview } from "vite";

/*
| Revisa por HTTP lo que sirve cada dominio (ADR 0108), con la misma regla que nginx:
| la landing de cada producto en su dominio y la aplicación en lo demás (registro,
| panel, subdominios de los negocios), sin indexar.
*/
const root = fileURLToPath(new URL("../", import.meta.url));
const PRODUCTOS = {
  agendauno: {
    dominio: "agendauno.mx",
    marca: "AgendaUno",
    negocio: "estudio",
  },
  turnouno: { dominio: "turnouno.mx", marca: "TurnoUno", negocio: "barberia" },
};
const server = await preview({
  root,
  preview: {
    host: "127.0.0.1",
    port: 0,
    strictPort: true,
    open: false,
    allowedHosts: Object.values(PRODUCTOS).flatMap((p) => [
      p.dominio,
      `www.${p.dominio}`,
      `${p.negocio}.${p.dominio}`,
    ]),
  },
});
const port = server.httpServer.address().port;
const get = (path, host) =>
  new Promise((resolve, reject) => {
    http
      .get({ host: "127.0.0.1", port, path, headers: { host } }, (response) => {
        let body = "";
        response.setEncoding("utf8");
        response.on("data", (chunk) => (body += chunk));
        response.on("end", () =>
          resolve({ status: response.statusCode, body }),
        );
      })
      .on("error", reject);
  });
try {
  let paginas = 0;
  for (const [id, { dominio, marca, negocio }] of Object.entries(PRODUCTOS)) {
    // La misma lista que prerenderizó el build de esa landing.
    const { rutasMarketing } = await import(
      pathToFileURL(resolve(root, "dist-ssr", id, "entry-marketing.mjs")).href
    );
    for (const path of rutasMarketing) {
      const result = await get(path, dominio);
      assert.equal(result.status, 200, `${dominio}${path}`);
      assert.match(result.body, /<h1/);
      assert.match(
        result.body,
        /content="index,follow,max-image-preview:large"/,
      );
      assert.ok(result.body.includes(marca), `${dominio}${path}: ${marca}`);
      paginas++;
    }
    const robots = await get("/robots.txt", dominio);
    assert.ok(robots.body.includes(`https://${dominio}/sitemap.xml`), "robots");
    for (const path of [
      "/registro",
      "/entrar",
      "/activar/ejemplo?token=secreto",
      "/panel",
    ]) {
      const result = await get(path, dominio);
      assert.equal(result.status, 200, `${dominio}${path}`);
      assert.match(result.body, /content="noindex,follow"/);
      assert.doesNotMatch(result.body, /rel="canonical"/);
    }
    // En el subdominio de un negocio, ni la raíz ni las páginas comerciales son la
    // landing del producto: la aplicación, sin indexar.
    for (const path of ["/", "/clases", "/citas"]) {
      const tenant = await get(path, `${negocio}.${dominio}`);
      assert.equal(tenant.status, 200, `${negocio}.${dominio}${path}`);
      assert.match(tenant.body, /content="noindex,follow"/);
      assert.doesNotMatch(tenant.body, /<h1/);
      assert.doesNotMatch(tenant.body, /rel="canonical"/);
    }
    assert.equal((await get("/assets/no-existe.js", dominio)).status, 404);
    assert.equal((await get("/favicon.ico", dominio)).status, 200);
  }
  console.log(
    `HTTP validado: ${paginas} páginas de las dos landings, acceso, activación, panel, subdominios y 404 de assets.`,
  );
} finally {
  await new Promise((resolve) => server.httpServer.close(resolve));
}
