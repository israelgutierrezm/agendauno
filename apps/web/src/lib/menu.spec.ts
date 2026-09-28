import { describe, expect, it } from "vitest";

import { hojas, MENU } from "./menu";

describe("menú lateral del panel", () => {
  it("va del día a día a la configuración, con la suscripción al final", () => {
    expect(
      MENU.filter((i) => !i.soloMiembro && !i.soloInstructor).map(
        (i) => i.clave,
      ),
    ).toEqual([
      "panel",
      "operacion",
      "clientes",
      "membresias",
      "punto-venta",
      "cobros",
      "equipo",
      "marketing",
      "reportes",
      "ajustes",
      "suscripcion",
    ]);
  });

  it("cada pantalla aparece una sola vez", () => {
    const rutas = hojas(MENU).map((h) => h.ruta);
    expect(new Set(rutas).size).toBe(rutas.length);
  });

  it("la agenda y la recepción quedan a un clic, al abrir el día a día", () => {
    const operacion = MENU.find((i) => i.clave === "operacion");
    expect(operacion?.hijos?.slice(0, 2).map((h) => h.ruta)).toEqual([
      "agenda",
      "recepcion",
    ]);
  });
});
