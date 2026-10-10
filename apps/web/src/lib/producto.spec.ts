import { afterEach, describe, expect, it } from "vitest";

import {
  fijarProductoDeSesion,
  productoActual,
  productoDeModalidad,
  productoDelHost,
} from "./producto";

/*
| Dos productos, un código (ADR 0108): cada dominio se presenta con su marca. El host
| manda en producción; en desarrollo, el negocio en sesión o `?producto=`.
*/

afterEach(() => {
  fijarProductoDeSesion(null);
  sessionStorage.clear();
  window.history.replaceState(null, "", "/");
});

describe("producto", () => {
  it("cada dominio y sus subdominios son de su producto", () => {
    expect(productoDelHost("agendauno.mx")).toBe("agendauno");
    expect(productoDelHost("www.agendauno.mx")).toBe("agendauno");
    expect(productoDelHost("barberia.turnouno.mx")).toBe("turnouno");
    expect(productoDelHost("TURNOUNO.MX:443")).toBe("turnouno");
    expect(productoDelHost("a.b.turnouno.mx")).toBeNull();
    expect(productoDelHost("localhost")).toBeNull();
    expect(productoDelHost("turnouno.mx.otro.com")).toBeNull();
  });

  it("la modalidad decide el producto", () => {
    expect(productoDeModalidad("clases")).toBe("agendauno");
    expect(productoDeModalidad("citas")).toBe("turnouno");
    expect(productoDeModalidad(null)).toBe("agendauno");
  });

  it("en desarrollo: el negocio en sesión, luego ?producto= y si no AgendaUno", () => {
    expect(productoActual()).toBe("agendauno");

    window.history.replaceState(null, "", "/?producto=turnouno");
    expect(productoActual()).toBe("turnouno");
    // Se recuerda en la pestaña aunque cambie la ruta.
    window.history.replaceState(null, "", "/registro");
    expect(productoActual()).toBe("turnouno");

    fijarProductoDeSesion("agendauno");
    expect(productoActual()).toBe("agendauno");
  });
});
