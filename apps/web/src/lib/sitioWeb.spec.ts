import { describe, expect, it } from "vitest";

import {
  colorDeContraste,
  moverSeccion,
  sitioPorDefecto,
  type TipoSeccion,
} from "./sitioWeb";

/*
| El sitio de cada negocio (ADR 0114): la portada y el contacto no se mueven; un negocio
| de citas no tiene horario de clases; el texto de los botones se lee sobre su color.
*/

const lista = (tipos: TipoSeccion[]) => tipos.map((tipo) => ({ tipo }));

describe("sitio del negocio", () => {
  it("mueve una sección sin pasar de la portada ni del contacto", () => {
    const secciones = lista(["inicio", "nosotros", "precios", "contacto"]);
    expect(moverSeccion(secciones, 1, 1).map((s) => s.tipo)).toEqual([
      "inicio",
      "precios",
      "nosotros",
      "contacto",
    ]);
    // No sube por encima de la portada ni baja después del contacto.
    expect(moverSeccion(secciones, 1, -1)).toEqual(secciones);
    expect(moverSeccion(secciones, 2, 1)).toEqual(secciones);
    // Las fijas no se mueven.
    expect(moverSeccion(secciones, 0, 1)).toEqual(secciones);
    // Devuelve otra lista (no cambia la original).
    expect(moverSeccion(secciones, 1, 1)).not.toBe(secciones);
  });

  it("la página de siempre: un negocio de citas no tiene horario de clases", () => {
    expect(sitioPorDefecto(false).secciones.map((s) => s.tipo)).toContain(
      "horario",
    );
    const citas = sitioPorDefecto(true).secciones.map((s) => s.tipo);
    expect(citas).not.toContain("horario");
    expect(citas[0]).toBe("inicio");
    expect(citas.at(-1)).toBe("contacto");
  });

  it("el texto sobre el color de la marca se lee", () => {
    expect(colorDeContraste("#031b4e")).toBe("#ffffff");
    expect(colorDeContraste("#f5d76e")).toBe("#111111");
    expect(colorDeContraste("no-es-color")).toBe("#ffffff");
  });
});
