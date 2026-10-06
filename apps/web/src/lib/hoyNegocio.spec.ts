import { describe, expect, it } from "vitest";

import { hoyComoFecha, hoyEnNegocio, inicioDeMesEnNegocio } from "./hoyNegocio";

describe("hoy en el negocio", () => {
  // 1 de octubre, 05:30 UTC: en la Ciudad de México aún es 30 de septiembre.
  const ahora = new Date("2026-10-01T05:30:00Z");

  it("usa la zona del negocio, no la del navegador", () => {
    expect(hoyEnNegocio("America/Mexico_City", ahora)).toBe("2026-09-30");
    expect(hoyEnNegocio("Europe/Madrid", ahora)).toBe("2026-10-01");
  });

  it("el inicio del mes también es el del negocio", () => {
    expect(inicioDeMesEnNegocio("America/Mexico_City", ahora)).toBe(
      "2026-09-01",
    );
    expect(inicioDeMesEnNegocio("Europe/Madrid", ahora)).toBe("2026-10-01");
  });

  it("como fecha: ese día a mediodía", () => {
    const d = hoyComoFecha("America/Mexico_City", ahora);
    expect([
      d.getFullYear(),
      d.getMonth() + 1,
      d.getDate(),
      d.getHours(),
    ]).toEqual([2026, 9, 30, 12]);
  });
});
