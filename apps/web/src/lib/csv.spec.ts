import { describe, expect, it } from "vitest";

import { celdaCsv, lineaCsv } from "./csv";

/*
| CSV armados en el navegador (ADR 0173): lo que una hoja de cálculo tomaría por fórmula
| lleva un apóstrofo delante; los números negativos se quedan como números.
*/
describe("celdas de CSV", () => {
  it("neutraliza fórmulas y deja los números", () => {
    expect(celdaCsv('=HYPERLINK("http://x","clic")')).toBe(
      '"\'=HYPERLINK(""http://x"",""clic"")"',
    );
    expect(celdaCsv("+52 55 1234 5678")).toBe('"\'+52 55 1234 5678"');
    expect(celdaCsv("@SUMA(A1)")).toBe('"\'@SUMA(A1)"');
    expect(celdaCsv("-899.00")).toBe('"-899.00"');
    expect(celdaCsv(-12)).toBe('"-12"');
    expect(celdaCsv(null)).toBe('""');
  });

  it("una línea con sus celdas separadas por coma", () => {
    expect(lineaCsv(["Ana, la de pilates", 3, "=1+1"])).toBe(
      '"Ana, la de pilates","3","\'=1+1"',
    );
  });
});
