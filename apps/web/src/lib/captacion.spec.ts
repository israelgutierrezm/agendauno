import { describe, expect, it } from "vitest";

import {
  abierto,
  codigoEmbebido,
  rutaFormulario,
  urlFormulario,
  whatsappDe,
} from "./captacion";

/*
| Captación (ADR 0117): el enlace de un formulario (en el subdominio del negocio en
| producción, en el mismo sitio en desarrollo) y el código para embeberlo.
*/

describe("captación", () => {
  it("arma el enlace del formulario en el subdominio del negocio, o local en desarrollo", () => {
    expect(rutaFormulario("fluo", "01abc")).toBe("/f/fluo/01abc");
    expect(urlFormulario("fluo", "01abc", "agendauno.mx")).toBe(
      "https://fluo.agendauno.mx/f/fluo/01abc",
    );
    expect(
      urlFormulario("fluo", "01abc", "localhost", "http://localhost:5175"),
    ).toBe("http://localhost:5175/f/fluo/01abc");
  });

  it("el código para embeber usa la versión sin encabezado y ajusta su altura", () => {
    const codigo = codigoEmbebido(
      "https://fluo.agendauno.mx/f/fluo/01abc",
      'Clase "muestra"',
    );
    expect(codigo).toContain(
      'src="https://fluo.agendauno.mx/f/fluo/01abc?embed=1"',
    );
    expect(codigo).toContain('title="Clase muestra"');
    expect(codigo).toContain("agendauno:altura");
  });

  it("WhatsApp solo con un número y abiertos los que siguen en seguimiento", () => {
    expect(whatsappDe("+52 55 1234 5678")).toBe("https://wa.me/525512345678");
    expect(whatsappDe("123")).toBeNull();
    expect(abierto("nuevo")).toBe(true);
    expect(abierto("invitacion_enviada")).toBe(true);
    expect(abierto("convertido")).toBe(false);
    expect(abierto("descartado")).toBe(false);
  });
});
