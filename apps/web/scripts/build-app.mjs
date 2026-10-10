import process from "node:process";
import { copyFile, writeFile } from "node:fs/promises";
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
await copyFile(
  resolve(root, "dist/app/index.html"),
  resolve(root, "dist/app/app.html"),
);
// En el subdominio de un negocio: su página se indexa (con su canonical); el mapa
// del sitio es el de la landing de cada producto, no este.
await writeFile(
  resolve(root, "dist/app/robots.txt"),
  "User-agent: *\nAllow: /\n",
);
console.log(
  "Aplicación: dist/app (app.html para las rutas que no son landing).",
);
