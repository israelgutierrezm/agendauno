import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";

/*
| Las llaves armadas en el momento (`$t(`retencion.estados.${estado}`)`) no las ve
| quien busca textos sin uso: así se borró `retencion.estados` y la pantalla mostraba
| la llave cruda. Aquí se recorre src y cada prefijo LITERAL de esas llaves debe
| existir en los mensajes es-MX combinados (los que monta src/i18n/index.ts).
*/

const fuentes = import.meta.glob<string>(
  ["/src/**/*.vue", "/src/**/*.ts", "!/src/**/*.spec.ts"],
  { query: "?raw", import: "default", eager: true },
);

// `$t(`` o `t(`` (no `algo.t(` ni `te(`) seguido de un texto literal y luego `${`.
const LLAVE_DINAMICA = /(?<![\w$.])\$?t\(\s*`([^`$]*)\$\{/g;

type Mensajes = Record<string, unknown>;

/**
 * ¿Existe el prefijo? «a.b.» pide el grupo `a.b`; «a.b.c» (la llave sigue pegada,
 * p. ej. `c${n}`) pide el grupo `a.b` con alguna llave que empiece con «c».
 */
function existePrefijo(mensajes: Mensajes, prefijo: string): boolean {
  const partes = prefijo.split(".");
  const inicio = partes.pop() ?? "";
  let nodo: unknown = mensajes;
  for (const parte of partes) {
    if (nodo === null || typeof nodo !== "object" || !(parte in nodo)) {
      return false;
    }
    nodo = (nodo as Mensajes)[parte];
  }
  if (nodo === null || typeof nodo !== "object") {
    return false;
  }
  return Object.keys(nodo).some((llave) => llave.startsWith(inicio));
}

describe("llaves de texto armadas en el momento", () => {
  const mensajes = i18n.global.getLocaleMessage("es-MX") as Mensajes;
  const usos: { archivo: string; prefijo: string }[] = [];
  for (const [archivo, codigo] of Object.entries(fuentes)) {
    for (const encontrado of codigo.matchAll(LLAVE_DINAMICA)) {
      const prefijo = encontrado[1].trim();
      // Sin una parte literal con punto no hay grupo que revisar.
      if (prefijo.includes(".")) {
        usos.push({ archivo, prefijo });
      }
    }
  }

  it("encuentra los usos (la búsqueda no se rompió)", () => {
    expect(usos.length).toBeGreaterThan(50);
    expect(usos).toContainEqual({
      archivo: "/src/views/RetencionView.vue",
      prefijo: "retencion.estados.",
    });
  });

  it("cada prefijo existe en los mensajes es-MX", () => {
    const faltan = usos
      .filter(({ prefijo }) => !existePrefijo(mensajes, prefijo))
      .map(({ archivo, prefijo }) => `${archivo}: ${prefijo}`);
    expect(faltan).toEqual([]);
  });

  it("los estados de Renovaciones tienen texto", () => {
    expect(i18n.global.t("retencion.estados.por_vencer")).toBe("Por vencer");
    expect(i18n.global.t("retencion.estados.vencida")).toBe("Vencida");
  });
});
