import { readdirSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * En un `<style scoped>`, Vue convierte `:global(.dark) .tarjeta` en `.dark` a secas:
 * todo lo que sigue a `:global(...)` se pierde y la regla cae sobre la página entera
 * (así una opacidad del fondo decorativo oscurecía todo el modo oscuro). El ancestro
 * se escribe sin `:global` (`.dark .tarjeta`): Vue solo marca el último selector.
 */
describe("estilos scoped", () => {
  it("ningún :global(...) lleva un selector después", () => {
    const raiz = resolve(process.cwd(), "src");
    const vue = readdirSync(raiz, { recursive: true, encoding: "utf8" })
      .filter((ruta) => ruta.endsWith(".vue"))
      .map((ruta) => join(raiz, ruta));
    const malos: string[] = [];
    for (const archivo of vue) {
      const fuente = readFileSync(archivo, "utf8");
      for (const estilo of fuente.matchAll(
        /<style[^>]*\bscoped\b[^>]*>([\s\S]*?)<\/style>/g,
      )) {
        for (const m of estilo[1]!.matchAll(/:global\([^)]*\)\s*[^\s{,]/g)) {
          malos.push(`${archivo.slice(raiz.length + 1)}: ${m[0]}`);
        }
      }
    }
    expect(malos).toEqual([]);
  });
});
