import { describe, expect, it } from "vitest";

import { listaDeNombres, textoAlcance, textoCobertura } from "./cobertura";

const t = (llave: string, valores?: Record<string, unknown>): string =>
  valores ? `${llave}:${String(valores.sedes)}` : llave;

describe("dónde vale un plan", () => {
  it("dice todas las sedes o cuáles, nunca «multisucursal» sin nombres", () => {
    expect(textoCobertura({ todas_sucursales: true }, t)).toBe(
      "escaparate.planes.todasLasSedes",
    );
    expect(
      textoCobertura(
        { todas_sucursales: false, sucursales: [{ nombre: "Roma" }] },
        t,
      ),
    ).toBe("escaparate.planes.soloEn:Roma");
    expect(listaDeNombres(["Roma", "Polanco", "Condesa"])).toBe(
      "Roma, Polanco y Condesa",
    );
    expect(listaDeNombres(["Roma", "Polanco"])).toBe("Roma y Polanco");
  });
});

describe("qué deja reservar un plan vendido", () => {
  const tt = (llave: string, valores?: Record<string, unknown>): string =>
    valores ? `${llave}:${Object.values(valores).join(",")}` : llave;

  it("dice sedes, actividad y clases solo si restringen algo", () => {
    expect(
      textoAlcance(
        {
          todas_sucursales: false,
          sucursales: [{ nombre: "Roma" }],
          actividad: "Pole",
          aplica_a: ["Pole 1", "Aéreo"],
        },
        tt,
        { variasSedes: true, esCitas: false },
      ),
    ).toBe(
      "escaparate.planes.soloEn:Roma · escaparate.planes.paraActividad:Pole · escaparate.planes.soloClases:Pole 1 y Aéreo",
    );
    // Un solo local y sin restricciones: nada que aclarar.
    expect(
      textoAlcance({ todas_sucursales: true, aplica_a: [] }, tt, {
        variasSedes: false,
        esCitas: false,
      }),
    ).toBeNull();
  });
});
