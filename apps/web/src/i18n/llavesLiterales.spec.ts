import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";

/*
| Una llave que no existe se ve cruda en la pantalla («cobranza.algo»). Aquí se
| recorre src y cada llave LITERAL de `$t("…")` o `t("…")` debe existir en los mensajes
| es-MX combinados. Las que se piden con `te(` antes (hay respaldo) se toleran.
| Las armadas en el momento las revisa llavesDinamicas.spec.ts.
*/

const fuentes = import.meta.glob<string>(
  ["/src/**/*.vue", "/src/**/*.ts", "!/src/**/*.spec.ts"],
  { query: "?raw", import: "default", eager: true },
);

const LLAVE = /(?<![\w$.])\$?t\(\s*["'`]([A-Za-z][\w-]*(?:\.[\w-]+)+)["'`]/g;

function existe(mensajes: Record<string, unknown>, llave: string): boolean {
  let nodo: unknown = mensajes;
  for (const parte of llave.split(".")) {
    if (nodo === null || typeof nodo !== "object" || !(parte in nodo)) {
      return false;
    }
    nodo = (nodo as Record<string, unknown>)[parte];
  }
  return typeof nodo === "string" || typeof nodo === "function";
}

describe("llaves de i18n", () => {
  it("toda llave literal existe en los mensajes", () => {
    const mensajes = i18n.global.getLocaleMessage("es-MX") as Record<
      string,
      unknown
    >;
    const faltan: string[] = [];
    for (const [archivo, fuente] of Object.entries(fuentes)) {
      for (const m of fuente.matchAll(LLAVE)) {
        const llave = m[1]!;
        if (existe(mensajes, llave) || fuente.includes(`te("${llave}")`)) {
          continue;
        }
        faltan.push(`${archivo}: ${llave}`);
      }
    }
    expect([...new Set(faltan)]).toEqual([]);
  });
});
