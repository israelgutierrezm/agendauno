import assert from "node:assert/strict";
import http from "node:http";
import { resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { preview } from "vite";

const root = fileURLToPath(new URL("../", import.meta.url));
// La misma lista que prerenderizó el build (seoConfig.rutasMarketing).
const { rutasMarketing } = await import(
  pathToFileURL(resolve(root, "dist-ssr/entry-marketing.mjs")).href
);
const server = await preview({
  root,
  preview: {
    host: "127.0.0.1",
    port: 0,
    strictPort: true,
    open: false,
    allowedHosts: ["agendauno.mx", "estudio.agendauno.mx"],
  },
});
const port = server.httpServer.address().port;
const get = (path, host = "agendauno.mx") =>
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
  for (const path of rutasMarketing) {
    const result = await get(path);
    assert.equal(result.status, 200, path);
    assert.match(result.body, /<h1/);
    assert.match(result.body, /content="index,follow,max-image-preview:large"/);
  }
  for (const path of [
    "/registro",
    "/entrar",
    "/activar/ejemplo?token=secreto",
    "/panel",
  ]) {
    const result = await get(path);
    assert.equal(result.status, 200, path);
    assert.match(result.body, /content="noindex,follow"/);
    assert.doesNotMatch(result.body, /rel="canonical"/);
  }
  // En el subdominio de un negocio, ni la raíz ni las páginas comerciales son la
  // landing de AgendaUno: la aplicación, sin indexar.
  for (const path of ["/", "/clases", "/citas"]) {
    const tenant = await get(path, "estudio.agendauno.mx");
    assert.equal(tenant.status, 200, `estudio.agendauno.mx${path}`);
    assert.match(tenant.body, /content="noindex,follow"/);
    assert.doesNotMatch(tenant.body, /<h1/);
    assert.doesNotMatch(tenant.body, /rel="canonical"/);
  }
  assert.equal((await get("/assets/no-existe.js")).status, 404);
  assert.equal((await get("/assets/no-existe.webp")).status, 404);
  assert.equal((await get("/favicon.ico")).status, 200);
  console.log(
    `HTTP validado: ${rutasMarketing.length} páginas comerciales, acceso, activación, panel, subdominio y 404 de assets.`,
  );
} finally {
  await new Promise((resolve) => server.httpServer.close(resolve));
}
