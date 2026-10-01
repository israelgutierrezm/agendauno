import { describe, expect, it } from "vitest";

import { imagenAcceso } from "./imagenesAcceso";

const disponibles = {
  "acceso-agenda": "/assets/acceso-agenda.webp",
  "acceso-agenda-citas": "/assets/acceso-agenda-citas.webp",
};

describe("imágenes de las tarjetas de acceso", () => {
  it("usa la primera que exista: la del giro y, si no, la general", () => {
    expect(
      imagenAcceso(["acceso-agenda-citas", "acceso-agenda"], disponibles),
    ).toBe("/assets/acceso-agenda-citas.webp");
    expect(
      imagenAcceso(["acceso-agenda-clases", "acceso-agenda"], disponibles),
    ).toBe("/assets/acceso-agenda.webp");
  });

  it("sin imagen, la tarjeta se queda con su ilustración", () => {
    expect(imagenAcceso("acceso-pagos", disponibles)).toBeNull();
    expect(imagenAcceso(undefined, disponibles)).toBeNull();
  });
});
