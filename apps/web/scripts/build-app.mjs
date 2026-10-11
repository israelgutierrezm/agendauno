import process from "node:process";
import { readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "vite";

/*
| La aplicación (ADR 0108): panel, portal del cliente, escaparate y PWA de cada
| negocio, superadmin, registro y acceso. Una sola para los dos productos: se presenta
| con la marca del dominio en que se abre. Sin `VITE_PRODUCTO` (ese es de las landings).
| Sale en dist/app; cada ruta que no es de la landing se sirve con app.html.
*/
const root = fileURLToPath(new URL("../", import.meta.url));
delete process.env.VITE_PRODUCTO;
await build({ root, build: { outDir: "dist/app", emptyOutDir: true } });
// index.html ya trae la cabecera sin indexar (marketingPlugin): es la de toda ruta.
// En app.html va dentro de un bloque SSI: en el subdominio de un negocio, nginx la
// cambia por la de su página pública, que le da la API (ADR 0128); si no es una página
// pública o la API no responde, queda esta. Sin SSI, son comentarios que no hacen nada.
const index = await readFile(resolve(root, "dist/app/index.html"), "utf8");
const app = index.replace(
  /(<!-- marketing:head -->)([\s\S]*?)(<!-- \/marketing:head -->)/,
  (_, abre, cabeza, cierra) =>
    `${abre}\n<!--# block name="cabeza" -->${cabeza}<!--# endblock -->\n` +
    `<!--# include virtual="/_cabeza-negocio" stub="cabeza" -->\n${cierra}`,
);
if (app === index) {
  throw new Error("index.html no trae la cabecera marcada (marketing:head).");
}
await writeFile(resolve(root, "dist/app/app.html"), app);
// El de la vista previa local. En producción, el subdominio de cada negocio tiene el
// suyo, con su sitemap, que da la API (ADR 0128); el dominio principal, el de su landing.
await writeFile(
  resolve(root, "dist/app/robots.txt"),
  "User-agent: *\nAllow: /\n",
);
console.log(
  "Aplicación: dist/app (app.html para las rutas que no son landing).",
);
