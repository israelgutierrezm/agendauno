import { describe, expect, it } from "vitest";

import { periodoDe, rangoDePeriodo } from "./periodos";

// 12 de octubre de 2026, 23:30 en Monterrey: en UTC ya es el 13.
const ahora = new Date("2026-10-13T05:30:00Z");
const zona = "America/Monterrey";

describe("atajos de periodo en días del negocio", () => {
  it("calcula hoy, los últimos 7 días, este mes y el mes pasado", () => {
    expect(rangoDePeriodo("hoy", zona, ahora)).toEqual({
      desde: "2026-10-12",
      hasta: "2026-10-12",
    });
    expect(rangoDePeriodo("semana", zona, ahora)).toEqual({
      desde: "2026-10-06",
      hasta: "2026-10-12",
    });
    expect(rangoDePeriodo("mes", zona, ahora)).toEqual({
      desde: "2026-10-01",
      hasta: "2026-10-12",
    });
    expect(rangoDePeriodo("mesPasado", zona, ahora)).toEqual({
      desde: "2026-09-01",
      hasta: "2026-09-30",
    });
  });

  it("reconoce un rango que es un atajo y no uno libre", () => {
    expect(periodoDe("2026-10-01", "2026-10-12", zona, ahora)).toBe("mes");
    expect(periodoDe("2026-10-02", "2026-10-12", zona, ahora)).toBeNull();
    expect(periodoDe("", "", zona, ahora)).toBeNull();
  });
});
