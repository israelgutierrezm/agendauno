import { describe, expect, it } from "vitest";

import { esVisible, hojas, MENU, puedeEntrar } from "./menu";

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
      "ventas-grupo",
      "equipo",
      "marketing",
      "reportes",
      "ajustes",
      "suscripcion",
    ]);
  });

  it("agrupa en un tercer nivel: ventas, usuarios del equipo y la configuración", () => {
    const hijos = (clave: string): string[] =>
      MENU.find((i) => i.clave === clave)?.hijos?.map((h) => h.clave) ?? [];

    expect(hijos("ventas-grupo")).toEqual([
      "membresias",
      "punto-venta",
      "cobros",
    ]);
    // Accesos y roles viven con el equipo, en «Usuarios».
    const usuarios = MENU.find((i) => i.clave === "equipo")?.hijos?.find(
      (h) => h.clave === "usuarios-grupo",
    );
    expect(usuarios?.hijos?.map((h) => h.ruta)).toEqual(["usuarios", "roles"]);
    expect(hijos("ajustes")).toEqual([
      "negocio-grupo",
      "servicios-grupo",
      "pagos-grupo",
      "registros-grupo",
    ]);
  });

  it("cada pantalla aparece una sola vez", () => {
    const rutas = hojas(MENU).map((h) => h.ruta);
    expect(new Set(rutas).size).toBe(rutas.length);
  });

  it("suspendido por renta, solo se ve y se entra a la renta para pagarla", () => {
    const sesion = {
      suspendido: true,
      puede: () => true,
      usuario: { rol: "propietario" },
    } as unknown as Parameters<typeof esVisible>[1];

    expect(
      hojas(MENU)
        .filter((h) => esVisible(h, sesion))
        .map((h) => h.ruta),
    ).toEqual(["renta"]);
    expect(puedeEntrar("renta", sesion)).toBe(true);
    expect(puedeEntrar("agenda", sesion)).toBe(false);
    expect(puedeEntrar("ficha-miembro", sesion)).toBe(false);
  });

  it("la agenda y la recepción quedan a un clic, al abrir el día a día", () => {
    const operacion = MENU.find((i) => i.clave === "operacion");
    expect(operacion?.hijos?.slice(0, 2).map((h) => h.ruta)).toEqual([
      "agenda",
      "recepcion",
    ]);
  });
});
