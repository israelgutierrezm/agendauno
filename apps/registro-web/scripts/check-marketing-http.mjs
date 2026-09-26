import assert from "node:assert/strict";
import http from "node:http";
import { fileURLToPath } from "node:url";
import { preview } from "vite";

const server = await preview({
  root: fileURLToPath(new URL("../", import.meta.url)),
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
  for (const path of [
    "/",
    ...[
      "pilates",
      "pole-dance",
      "academias",
      "barberias",
      "spas",
      "terapeutas",
    ].map((s) => `/software-para-${s}`),
  ]) {
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
  const tenant = await get("/", "estudio.agendauno.mx");
  assert.match(tenant.body, /content="noindex,follow"/);
  assert.doesNotMatch(tenant.body, /<h1/);
  assert.equal((await get("/assets/no-existe.js")).status, 404);
  assert.equal((await get("/assets/no-existe.webp")).status, 404);
  assert.equal((await get("/favicon.ico")).status, 200);
  console.log(
    "HTTP validado: 7 páginas comerciales, acceso, activación, panel, subdominio y 404 de assets.",
  );
} finally {
  await new Promise((resolve) => server.httpServer.close(resolve));
}
