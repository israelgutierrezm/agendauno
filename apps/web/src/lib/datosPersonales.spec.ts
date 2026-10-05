import { describe, expect, it } from "vitest";

import { edadDe, hoyIso } from "./datosPersonales";

describe("datos personales", () => {
  it("la edad cuenta si ya pasó su cumpleaños este año", () => {
    const hoy = new Date(2026, 9, 5);
    expect(edadDe("1994-03-14", hoy)).toBe(32);
    expect(edadDe("1994-10-05", hoy)).toBe(32);
    expect(edadDe("1994-10-06", hoy)).toBe(31);
    expect(edadDe(null, hoy)).toBeNull();
  });

  it("el tope del campo de fecha es hoy (local)", () => {
    expect(hoyIso(new Date(2026, 0, 9))).toBe("2026-01-09");
  });
});
