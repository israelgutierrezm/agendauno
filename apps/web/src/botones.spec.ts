import { readdirSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { mensajesBase } from "@/i18n";

/**
 * El contrato de botones (style.css): `tu-btn` con una de cinco variantes
 * (primario, secundario, discreto, peligro y el sólido de peligro para confirmar),
 * `tu-btn-chico` para filas y `tu-enlace` solo para ir a otra pantalla o abrir
 * información. Así el usuario anticipa qué aspecto tiene una acción.
 */
const raiz = resolve(process.cwd(), "src");
const archivos = readdirSync(raiz, { recursive: true, encoding: "utf8" })
  .filter((ruta) => /\.(vue|ts|css)$/.test(ruta) && !ruta.endsWith(".spec.ts"))
  .map((ruta) => ({ ruta, fuente: readFileSync(join(raiz, ruta), "utf8") }));

// Acciones que cambian datos: nunca con aspecto de enlace.
const ACCION =
  /^(editar|eliminar|borrar|quitar|configurar|archivar|desarchivar|revocar|desconectar|dividir|reprogramar|reembolsar|anular)/i;

// Una acción de riesgo dice sobre qué actúa: «Desactivar pago automático», no «Quitar».
const GENERICAS = new Set([
  "quitar",
  "eliminar",
  "borrar",
  "cancelar",
  "desactivar",
  "archivar",
  "revocar",
  "devolver",
  "descartar",
  "dar de baja",
  "anular",
  "desconectar",
  "suspender",
  "condonar",
  "reembolsar",
  // Reintentar no es de riesgo: si un botón de peligro lo dice, está mal pintado.
  "reintentar",
]);

function texto(clave: string): unknown {
  return clave
    .split(".")
    .reduce<unknown>(
      (valor, parte) => (valor as Record<string, unknown> | undefined)?.[parte],
      mensajesBase,
    );
}

describe("contrato de botones", () => {
  it("no quedan las variantes retiradas", () => {
    const malos = archivos.flatMap(({ ruta, fuente }) =>
      [
        ...fuente.matchAll(
          /\btu-btn-(fantasma|contorno|suave|minimo|icono)\b/g,
        ),
      ].map((m) => `${ruta}: ${m[0]}`),
    );
    expect(malos).toEqual([]);
  });

  it("un botón no cambia su tamaño con text-sm o text-xs (para eso, tu-btn-chico)", () => {
    const malos = archivos.flatMap(({ ruta, fuente }) =>
      [
        ...fuente.matchAll(
          /(?<![:\w-])class="([^"]*)"|'([^'\n]*)'|`([^`\n]*)`/g,
        ),
      ]
        .map((m) => m[1] ?? m[2] ?? m[3] ?? "")
        .filter(
          (clases) =>
            /(?<![\w-])tu-btn(?![\w-])/.test(clases) &&
            /(?<![\w:-])text-(sm|xs)(?![\w-])/.test(clases),
        )
        .map((clases) => `${ruta}: ${clases}`),
    );
    expect(malos).toEqual([]);
  });

  it("una acción que cambia datos no se ve como enlace", () => {
    const malos: string[] = [];
    for (const { ruta, fuente } of archivos.filter((a) =>
      a.ruta.endsWith(".vue"),
    )) {
      for (const m of fuente.matchAll(
        /<button\b([^>]*?)>([\s\S]*?)<\/button>/g,
      )) {
        const atributos = m[1] ?? "";
        if (!/class="[^"]*\btu-enlace\b/.test(atributos)) {
          continue;
        }
        const cuerpo = m[2] ?? "";
        const claves = [...cuerpo.matchAll(/\$?t\(\s*["'`]([^"'`]+)["'`]/g)]
          .map((c) => c[1]!.split(".").at(-1) ?? "")
          .filter((c) => ACCION.test(c));
        const texto = cuerpo
          .replace(/{{[\s\S]*?}}/g, " ")
          .replace(/<[^>]*>/g, " ")
          .trim();
        if (claves.length > 0 || ACCION.test(texto)) {
          malos.push(`${ruta}: ${(claves[0] ?? texto).slice(0, 60)}`);
        }
      }
    }
    expect(malos).toEqual([]);
  });

  it("una acción de riesgo nombra lo que cancela, quita o elimina", () => {
    const malos: string[] = [];
    for (const { ruta, fuente } of archivos.filter((a) =>
      a.ruta.endsWith(".vue"),
    )) {
      for (const m of fuente.matchAll(
        /<button\b([^>]*?)>([\s\S]*?)<\/button>/g,
      )) {
        if (!/\btu-btn-peligro\b/.test(m[1] ?? "")) {
          continue;
        }
        const cuerpo = m[2] ?? "";
        for (const c of cuerpo.matchAll(/\$?t\(\s*["'`]([\w.]+)["'`]/g)) {
          const valor = texto(c[1]!);
          if (
            typeof valor === "string" &&
            GENERICAS.has(valor.trim().toLowerCase())
          ) {
            malos.push(`${ruta}: ${c[1]} «${valor}»`);
          }
        }
        const literal = cuerpo
          .replace(/{{[\s\S]*?}}/g, " ")
          .replace(/<[^>]*>/g, " ")
          .trim()
          .toLowerCase();
        if (GENERICAS.has(literal)) {
          malos.push(`${ruta}: «${literal}»`);
        }
      }
    }
    expect(malos).toEqual([]);
  });
});
