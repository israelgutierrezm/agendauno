import { describe, expect, it } from "vitest";

import { listaDeNombres, textoCobertura } from "./cobertura";

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
