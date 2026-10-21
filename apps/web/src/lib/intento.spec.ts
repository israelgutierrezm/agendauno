import { describe, expect, it } from "vitest";

import { intentoUnico } from "./intento";

describe("la clave de un intento (ADR 0201)", () => {
  it("es la misma al repetir lo mismo y otra si cambia lo que se pide", () => {
    const intento = intentoUnico();
    const primera = intento.clave({ hora: "10:00" });

    expect(intento.clave({ hora: "10:00" })).toBe(primera);
    expect(intento.clave({ hora: "10:30" })).not.toBe(primera);
  });

  it("tras una respuesta, lo siguiente es otro intento aunque sea igual", () => {
    const intento = intentoUnico();
    const primera = intento.clave({ hora: "10:00" });
    intento.listo();

    expect(intento.clave({ hora: "10:00" })).not.toBe(primera);
  });
});
